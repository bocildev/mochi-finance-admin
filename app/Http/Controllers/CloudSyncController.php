<?php

namespace App\Http\Controllers;

use App\Services\FinanceVaultService;
use App\Services\IamSsoService;
use Carbon\Carbon;
use Iam\Client\Claims;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CloudSyncController extends Controller
{
    public function __construct(
        private IamSsoService $sso,
        private FinanceVaultService $vault
    ) {}

    public function planConfig(Request $request)
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin' => $request->header('Origin', '*'),
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept, X-Requested-With',
        ];

        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $corsHeaders);
        }

        $this->sso->ensureDefaultPlansExist();

        $plans = DB::table('saas_plans')->get()->keyBy('key')->map(fn ($p) => [
            'key' => $p->key,
            'name' => $p->name,
            'badge' => $p->badge,
            'monthlyPrice' => (float) $p->monthly_price,
            'yearlyPrice' => (float) $p->yearly_price,
            'maxWallets' => (int) $p->max_wallets,
            'maxBudgets' => (int) $p->max_budgets,
            'maxGoals' => (int) $p->max_goals,
            'allowCloudSync' => (bool) $p->allow_cloud_sync,
            'allowSmartInbox' => (bool) $p->allow_smart_inbox,
            'allowAnalytics' => (bool) $p->allow_analytics,
            'allowCsvExport' => (bool) $p->allow_csv_export,
            'features' => json_decode($p->features ?: '[]', true) ?: [],
        ]);

        $account = null;
        if ($token = $request->bearerToken()) {
            try {
                $claims = $this->sso->verifyAccessToken($token);
                $userRow = $this->sso->syncFinanceUser($claims);
                $pendingUpgrade = DB::table('upgrade_requests')
                    ->where('user_sub', $userRow->sub)
                    ->where('status', 'PENDING')
                    ->latest('id')
                    ->first();

                $now = Carbon::now();
                $trialDays = 14;
                $createdAt = $userRow->created_at ? Carbon::parse($userRow->created_at) : $now;
                $trialExpiresAt = $createdAt->copy()->addDays($trialDays);
                $isFree = ($userRow->plan_tier ?? 'free') === 'free';
                $isTrialExpired = $isFree && $now->greaterThan($trialExpiresAt);
                $trialDaysRemaining = $isFree ? max(0, (int) ceil($now->floatDiffInDays($trialExpiresAt, false))) : 0;

                $account = [
                    'sub' => $userRow->sub,
                    'name' => $userRow->name,
                    'email' => $userRow->email,
                    'tier' => $userRow->plan_tier ?? 'free',
                    'billingCycle' => $userRow->billing_cycle,
                    'proExpiresAt' => $userRow->pro_expires_at,
                    'trialExpiresAt' => $trialExpiresAt->toIso8601String(),
                    'isTrialExpired' => $isTrialExpired,
                    'trialDaysRemaining' => $trialDaysRemaining,
                    'roles' => json_decode($userRow->roles ?: '[]', true) ?: [],
                    'permissions' => json_decode($userRow->permissions ?: '[]', true) ?: [],
                    'pendingUpgrade' => $pendingUpgrade ? [
                        'id' => $pendingUpgrade->id,
                        'requestedPlan' => $pendingUpgrade->requested_plan,
                        'amount' => (float) $pendingUpgrade->amount,
                        'paymentMethod' => $pendingUpgrade->payment_method,
                        'createdAt' => $pendingUpgrade->created_at,
                    ] : null,
                ];
            } catch (\Throwable) {
                $account = null;
            }
        }

        return response()->json([
            'plans' => $plans,
            'account' => $account,
        ], 200, $corsHeaders);
    }

    public function submitUpgradeRequest(Request $request)
    {
        $userRow = $request->attributes->get('iam_user');
        $data = $request->validate([
            'requested_plan' => 'required|in:pro_monthly,pro_yearly',
            'payment_method' => 'required|in:QRIS,BCA,MANDIRI,GOPAY,OVO',
            'notes' => 'nullable|string|max:500',
        ]);

        $proPlan = DB::table('saas_plans')->where('key', 'pro')->first();
        $amount = $data['requested_plan'] === 'pro_yearly'
            ? (float) ($proPlan->yearly_price ?? 249000)
            : (float) ($proPlan->monthly_price ?? 29000);

        $now = Carbon::now();

        // Batalkan request pending lama dari user yang sama bila ada
        DB::table('upgrade_requests')
            ->where('user_sub', $userRow->sub)
            ->where('status', 'PENDING')
            ->update(['status' => 'REJECTED', 'updated_at' => $now]);

        $id = DB::table('upgrade_requests')->insertGetId([
            'user_sub' => $userRow->sub,
            'user_name' => $userRow->name,
            'user_email' => $userRow->email,
            'requested_plan' => $data['requested_plan'],
            'amount' => $amount,
            'payment_method' => $data['payment_method'],
            'notes' => $data['notes'] ?? 'Pengajuan Upgrade Pro dari Mochi Finance PWA',
            'status' => 'PENDING',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'ok' => true,
            'request_id' => $id,
            'message' => 'Permintaan upgrade ke Mochi Pro ✨ berhasil dikirim! Menunggu persetujuan Admin.',
        ]);
    }

    public function pull(Request $request)
    {
        /** @var Claims $claims */
        $claims = $request->attributes->get('iam_claims');
        $userRow = $request->attributes->get('iam_user');
        $sub = $claims->sub;

        // Auto-remap jika ada data lama milik owner dari sub sebelumnya (tanpa auto-seed dummy)
        if ($userRow && in_array(strtolower($userRow->email ?? ''), ['athallahrich@gmail.com', 'admin@iam-sso.local'], true)) {
            $legacySubs = ['60bc11d8-480f-44f5-8640-fc6cce386536', 'usr_owner_athallahrich', 'usr_super_admin'];
            foreach (['finance_wallets', 'finance_transactions', 'finance_categories', 'finance_budgets', 'finance_saving_goals', 'finance_recurring_bills', 'finance_inbox_items'] as $table) {
                DB::table($table)
                    ->where('user_sub', '!=', $sub)
                    ->whereIn('user_sub', $legacySubs)
                    ->update(['user_sub' => $sub]);
            }
        }

        return response()->json($this->vault->getVaultSnapshot($sub, $userRow));
    }

    public function push(Request $request)
    {
        /** @var Claims $claims */
        $claims = $request->attributes->get('iam_claims');
        $userRow = $request->attributes->get('iam_user');
        $sub = $claims->sub;
        $now = Carbon::now();
        $nowMs = (int) round(microtime(true) * 1000);

        $isFree = $this->vault->isFree($userRow);
        $isTrialExpired = $this->vault->isTrialExpired($userRow);
        $maxWallets = $this->vault->maxWalletsForUser($userRow);

        DB::transaction(function () use ($request, $sub, $now, $nowMs, $isFree, $isTrialExpired, $maxWallets) {
            if ($request->has('wallets')) {
                $walletsInput = (array) $request->input('wallets', []);
                if ($isFree && $maxWallets > 0) {
                    $walletsInput = array_slice($walletsInput, 0, $maxWallets);
                }

                $walletIds = [];
                foreach ($walletsInput as $w) {
                    if (empty($w['id'])) {
                        continue;
                    }
                    $cid = (string) $w['id'];
                    $walletIds[] = $cid;
                    DB::table('finance_wallets')->updateOrInsert(
                        ['user_sub' => $sub, 'client_id' => $cid],
                        [
                            'name' => (string) ($w['name'] ?? 'Dompet'),
                            'type' => (string) ($w['type'] ?? 'bank'),
                            'balance' => (float) ($w['balance'] ?? 0),
                            'account_number' => $w['accountNumber'] ?? null,
                            'icon' => $w['icon'] ?? null,
                            'color' => $w['color'] ?? '#f472b6',
                            'created_ms' => (int) ($w['createdAt'] ?? $nowMs),
                            'updated_ms' => (int) ($w['updatedAt'] ?? $nowMs),
                            'updated_at' => $now,
                        ]
                    );
                }
                DB::table('finance_wallets')
                    ->where('user_sub', $sub)
                    ->when(! empty($walletIds), fn ($q) => $q->whereNotIn('client_id', $walletIds))
                    ->delete();
            }

            if ($request->has('categories')) {
                foreach ((array) $request->input('categories', []) as $c) {
                    if (empty($c['id'])) {
                        continue;
                    }
                    DB::table('finance_categories')->updateOrInsert(
                        ['user_sub' => $sub, 'client_id' => (string) $c['id']],
                        [
                            'name' => (string) ($c['name'] ?? 'Kategori'),
                            'type' => (string) ($c['type'] ?? 'expense'),
                            'icon' => $c['icon'] ?? 'Receipt',
                            'color' => $c['color'] ?? '#f472b6',
                            'updated_at' => $now,
                        ]
                    );
                }
            }

            if ($request->has('transactions')) {
                $txInput = (array) $request->input('transactions', []);
                if ($isTrialExpired) {
                    $existingTxIds = DB::table('finance_transactions')->where('user_sub', $sub)->pluck('client_id')->all();
                    $txInput = array_filter($txInput, fn ($t) => in_array((string) ($t['id'] ?? ''), $existingTxIds, true));
                }

                $txIds = [];
                foreach ($txInput as $t) {
                    if (empty($t['id'])) {
                        continue;
                    }
                    $cid = (string) $t['id'];
                    $txIds[] = $cid;
                    DB::table('finance_transactions')->updateOrInsert(
                        ['user_sub' => $sub, 'client_id' => $cid],
                        [
                            'wallet_id' => (string) ($t['walletId'] ?? ''),
                            'target_wallet_id' => $t['targetWalletId'] ?? null,
                            'amount' => (float) ($t['amount'] ?? 0),
                            'type' => (string) ($t['type'] ?? 'expense'),
                            'category_id' => (string) ($t['categoryId'] ?? 'cat-food'),
                            'notes' => $t['notes'] ?? '',
                            'tx_date' => (string) ($t['date'] ?? $now->toIso8601String()),
                            'receipt_image' => $t['receiptImage'] ?? null,
                            'tags' => isset($t['tags']) ? json_encode($t['tags']) : null,
                            'inbox_ref_id' => $t['inboxRefId'] ?? null,
                            'created_ms' => (int) ($t['createdAt'] ?? $nowMs),
                            'updated_at' => $now,
                        ]
                    );
                }
                DB::table('finance_transactions')
                    ->where('user_sub', $sub)
                    ->when(! empty($txIds), fn ($q) => $q->whereNotIn('client_id', $txIds))
                    ->delete();
            }

            // Fitur Khusus Pro: Anggaran, Celengan Impian, Tagihan Rutin, Smart Inbox
            if (! $isFree) {
                if ($request->has('budgets')) {
                    $budgetIds = [];
                    foreach ((array) $request->input('budgets', []) as $b) {
                        if (empty($b['id'])) {
                            continue;
                        }
                        $cid = (string) $b['id'];
                        $budgetIds[] = $cid;
                        DB::table('finance_budgets')->updateOrInsert(
                            ['user_sub' => $sub, 'client_id' => $cid],
                            [
                                'category_id' => (string) ($b['categoryId'] ?? 'cat-food'),
                                'monthly_limit' => (float) ($b['monthlyLimit'] ?? 0),
                                'month' => (string) ($b['month'] ?? $now->format('Y-m')),
                                'updated_at' => $now,
                            ]
                        );
                    }
                    DB::table('finance_budgets')
                        ->where('user_sub', $sub)
                        ->when(! empty($budgetIds), fn ($q) => $q->whereNotIn('client_id', $budgetIds))
                        ->delete();
                }

                if ($request->has('savingGoals')) {
                    $goalIds = [];
                    foreach ((array) $request->input('savingGoals', []) as $g) {
                        if (empty($g['id'])) {
                            continue;
                        }
                        $cid = (string) $g['id'];
                        $goalIds[] = $cid;
                        DB::table('finance_saving_goals')->updateOrInsert(
                            ['user_sub' => $sub, 'client_id' => $cid],
                            [
                                'name' => (string) ($g['name'] ?? 'Goal'),
                                'target_amount' => (float) ($g['targetAmount'] ?? 0),
                                'current_amount' => (float) ($g['currentAmount'] ?? 0),
                                'target_date' => $g['targetDate'] ?? null,
                                'icon' => $g['icon'] ?? 'Shield',
                                'color' => $g['color'] ?? '#10b981',
                                'notes' => $g['notes'] ?? null,
                                'created_ms' => (int) ($g['createdAt'] ?? $nowMs),
                                'updated_at' => $now,
                            ]
                        );
                    }
                    DB::table('finance_saving_goals')
                        ->where('user_sub', $sub)
                        ->when(! empty($goalIds), fn ($q) => $q->whereNotIn('client_id', $goalIds))
                        ->delete();
                }

                if ($request->has('recurringBills')) {
                    $billIds = [];
                    foreach ((array) $request->input('recurringBills', []) as $r) {
                        if (empty($r['id'])) {
                            continue;
                        }
                        $cid = (string) $r['id'];
                        $billIds[] = $cid;
                        DB::table('finance_recurring_bills')->updateOrInsert(
                            ['user_sub' => $sub, 'client_id' => $cid],
                            [
                                'name' => (string) ($r['name'] ?? 'Tagihan'),
                                'amount' => (float) ($r['amount'] ?? 0),
                                'category_id' => (string) ($r['categoryId'] ?? 'cat-bills'),
                                'wallet_id' => (string) ($r['walletId'] ?? ''),
                                'due_day' => (int) ($r['dueDay'] ?? 1),
                                'billing_cycle' => (string) ($r['billingCycle'] ?? 'monthly'),
                                'bill_type' => in_array($r['billType'] ?? 'subscription', ['subscription', 'installment', 'debt'], true)
                                    ? ($r['billType'] ?? 'subscription')
                                    : 'subscription',
                                'total_tenor' => isset($r['totalTenor']) ? (int) $r['totalTenor'] : null,
                                'paid_count' => (int) ($r['paidCount'] ?? 0),
                                'target_month' => $r['targetMonth'] ?? null,
                                'creditor_name' => $r['creditorName'] ?? null,
                                'paid_amount' => (float) ($r['paidAmount'] ?? 0),
                                'is_active' => (bool) ($r['isActive'] ?? true),
                                'notes' => $r['notes'] ?? null,
                                'last_paid_date' => $r['lastPaidDate'] ?? null,
                                'created_ms' => (int) ($r['createdAt'] ?? $nowMs),
                                'updated_at' => $now,
                            ]
                        );
                    }
                    DB::table('finance_recurring_bills')
                        ->where('user_sub', $sub)
                        ->when(! empty($billIds), fn ($q) => $q->whereNotIn('client_id', $billIds))
                        ->delete();
                }

                if ($request->has('inbox')) {
                    $inboxIds = [];
                    foreach ((array) $request->input('inbox', []) as $i) {
                        if (empty($i['id'])) {
                            continue;
                        }
                        $cid = (string) $i['id'];
                        $inboxIds[] = $cid;
                        DB::table('finance_inbox_items')->updateOrInsert(
                            ['user_sub' => $sub, 'client_id' => $cid],
                            [
                                'source' => (string) ($i['source'] ?? 'MANUAL'),
                                'raw_message' => (string) ($i['rawMessage'] ?? ''),
                                'detected_amount' => (float) ($i['detectedAmount'] ?? 0),
                                'detected_type' => (string) ($i['detectedType'] ?? 'expense'),
                                'detected_merchant' => $i['detectedMerchant'] ?? null,
                                'detected_date' => (string) ($i['detectedDate'] ?? $now->toIso8601String()),
                                'suggested_wallet_id' => $i['suggestedWalletId'] ?? null,
                                'suggested_category_id' => $i['suggestedCategoryId'] ?? null,
                                'status' => (string) ($i['status'] ?? 'PENDING'),
                                'notes' => $i['notes'] ?? null,
                                'created_ms' => (int) ($i['createdAt'] ?? $nowMs),
                                'updated_at' => $now,
                            ]
                        );
                    }
                    DB::table('finance_inbox_items')
                        ->where('user_sub', $sub)
                        ->when(! empty($inboxIds), fn ($q) => $q->whereNotIn('client_id', $inboxIds))
                        ->delete();
                }
            }

            $this->vault->refreshUserMetrics($sub);
        });

        return response()->json([
            'ok' => true,
            'sub' => $sub,
            'plan_tier' => $userRow->plan_tier ?? 'free',
            'isTrialExpired' => $isTrialExpired,
            'syncedAt' => $now->toIso8601String(),
        ]);
    }
}
