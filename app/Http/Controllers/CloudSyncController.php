<?php

namespace App\Http\Controllers;

use App\Services\IamSsoService;
use Carbon\Carbon;
use Iam\Client\Claims;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CloudSyncController extends Controller
{
    public function __construct(private IamSsoService $sso) {}

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

                $account = [
                    'sub' => $userRow->sub,
                    'name' => $userRow->name,
                    'email' => $userRow->email,
                    'tier' => $userRow->plan_tier ?? 'free',
                    'billingCycle' => $userRow->billing_cycle,
                    'proExpiresAt' => $userRow->pro_expires_at,
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
        $sub = $claims->sub;

        $wallets = DB::table('finance_wallets')
            ->where('user_sub', $sub)
            ->orderBy('id')
            ->get()
            ->map(fn ($w) => [
                'id' => $w->client_id,
                'name' => $w->name,
                'type' => $w->type,
                'balance' => (float) $w->balance,
                'accountNumber' => $w->account_number,
                'icon' => $w->icon,
                'color' => $w->color,
                'createdAt' => (int) $w->created_ms,
                'updatedAt' => (int) $w->updated_ms,
            ])->values();

        $categories = DB::table('finance_categories')
            ->where('user_sub', $sub)
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->client_id,
                'name' => $c->name,
                'type' => $c->type,
                'icon' => $c->icon ?: 'Receipt',
                'color' => $c->color ?: '#f472b6',
            ])->values();

        $transactions = DB::table('finance_transactions')
            ->where('user_sub', $sub)
            ->orderByDesc('created_ms')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->client_id,
                'walletId' => $t->wallet_id,
                'targetWalletId' => $t->target_wallet_id,
                'amount' => (float) $t->amount,
                'type' => $t->type,
                'categoryId' => $t->category_id,
                'notes' => (string) ($t->notes ?? ''),
                'date' => $t->tx_date,
                'receiptImage' => $t->receipt_image,
                'tags' => json_decode($t->tags ?: '[]', true) ?: [],
                'inboxRefId' => $t->inbox_ref_id,
                'createdAt' => (int) $t->created_ms,
            ])->values();

        $budgets = DB::table('finance_budgets')
            ->where('user_sub', $sub)
            ->get()
            ->map(fn ($b) => [
                'id' => $b->client_id,
                'categoryId' => $b->category_id,
                'monthlyLimit' => (float) $b->monthly_limit,
                'month' => $b->month,
            ])->values();

        $savingGoals = DB::table('finance_saving_goals')
            ->where('user_sub', $sub)
            ->get()
            ->map(fn ($g) => [
                'id' => $g->client_id,
                'name' => $g->name,
                'targetAmount' => (float) $g->target_amount,
                'currentAmount' => (float) $g->current_amount,
                'targetDate' => $g->target_date,
                'icon' => $g->icon,
                'color' => $g->color,
                'notes' => $g->notes,
                'createdAt' => (int) $g->created_ms,
            ])->values();

        $recurringBills = DB::table('finance_recurring_bills')
            ->where('user_sub', $sub)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->client_id,
                'name' => $r->name,
                'amount' => (float) $r->amount,
                'categoryId' => $r->category_id,
                'walletId' => $r->wallet_id,
                'dueDay' => (int) $r->due_day,
                'billingCycle' => $r->billing_cycle,
                'isActive' => (bool) $r->is_active,
                'notes' => $r->notes,
                'lastPaidDate' => $r->last_paid_date,
                'createdAt' => (int) $r->created_ms,
            ])->values();

        $inbox = DB::table('finance_inbox_items')
            ->where('user_sub', $sub)
            ->orderByDesc('created_ms')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->client_id,
                'source' => $i->source,
                'rawMessage' => $i->raw_message,
                'detectedAmount' => (float) $i->detected_amount,
                'detectedType' => $i->detected_type,
                'detectedMerchant' => $i->detected_merchant,
                'detectedDate' => $i->detected_date,
                'suggestedWalletId' => $i->suggested_wallet_id,
                'suggestedCategoryId' => $i->suggested_category_id,
                'status' => $i->status,
                'notes' => $i->notes,
                'createdAt' => (int) $i->created_ms,
            ])->values();

        $userRow = $request->attributes->get('iam_user');

        return response()->json([
            'sub' => $sub,
            'plan_tier' => $userRow->plan_tier ?? 'pro',
            'syncedAt' => Carbon::now()->toIso8601String(),
            'wallets' => $wallets,
            'categories' => $categories,
            'transactions' => $transactions,
            'budgets' => $budgets,
            'savingGoals' => $savingGoals,
            'recurringBills' => $recurringBills,
            'inbox' => $inbox,
        ]);
    }

    public function push(Request $request)
    {
        /** @var Claims $claims */
        $claims = $request->attributes->get('iam_claims');
        $userRow = $request->attributes->get('iam_user');
        $sub = $claims->sub;
        $now = Carbon::now();
        $nowMs = (int) round(microtime(true) * 1000);

        DB::transaction(function () use ($request, $sub, $now, $nowMs) {
            foreach ((array) $request->input('wallets', []) as $w) {
                if (empty($w['id'])) {
                    continue;
                }
                DB::table('finance_wallets')->updateOrInsert(
                    ['user_sub' => $sub, 'client_id' => (string) $w['id']],
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

            foreach ((array) $request->input('transactions', []) as $t) {
                if (empty($t['id'])) {
                    continue;
                }
                DB::table('finance_transactions')->updateOrInsert(
                    ['user_sub' => $sub, 'client_id' => (string) $t['id']],
                    [
                        'wallet_id' => (string) ($t['walletId'] ?? 'wallet-bca'),
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

            foreach ((array) $request->input('budgets', []) as $b) {
                if (empty($b['id'])) {
                    continue;
                }
                DB::table('finance_budgets')->updateOrInsert(
                    ['user_sub' => $sub, 'client_id' => (string) $b['id']],
                    [
                        'category_id' => (string) ($b['categoryId'] ?? 'cat-food'),
                        'monthly_limit' => (float) ($b['monthlyLimit'] ?? 0),
                        'month' => (string) ($b['month'] ?? $now->format('Y-m')),
                        'updated_at' => $now,
                    ]
                );
            }

            foreach ((array) $request->input('savingGoals', []) as $g) {
                if (empty($g['id'])) {
                    continue;
                }
                DB::table('finance_saving_goals')->updateOrInsert(
                    ['user_sub' => $sub, 'client_id' => (string) $g['id']],
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

            foreach ((array) $request->input('recurringBills', []) as $r) {
                if (empty($r['id'])) {
                    continue;
                }
                DB::table('finance_recurring_bills')->updateOrInsert(
                    ['user_sub' => $sub, 'client_id' => (string) $r['id']],
                    [
                        'name' => (string) ($r['name'] ?? 'Tagihan'),
                        'amount' => (float) ($r['amount'] ?? 0),
                        'category_id' => (string) ($r['categoryId'] ?? 'cat-bills'),
                        'wallet_id' => (string) ($r['walletId'] ?? 'wallet-bca'),
                        'due_day' => (int) ($r['dueDay'] ?? 1),
                        'billing_cycle' => (string) ($r['billingCycle'] ?? 'monthly'),
                        'is_active' => (bool) ($r['isActive'] ?? true),
                        'notes' => $r['notes'] ?? null,
                        'last_paid_date' => $r['lastPaidDate'] ?? null,
                        'created_ms' => (int) ($r['createdAt'] ?? $nowMs),
                        'updated_at' => $now,
                    ]
                );
            }

            foreach ((array) $request->input('inbox', []) as $i) {
                if (empty($i['id'])) {
                    continue;
                }
                DB::table('finance_inbox_items')->updateOrInsert(
                    ['user_sub' => $sub, 'client_id' => (string) $i['id']],
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

            $walletsCount = DB::table('finance_wallets')->where('user_sub', $sub)->count();
            $txCount = DB::table('finance_transactions')->where('user_sub', $sub)->count();
            $budgetsCount = DB::table('finance_budgets')->where('user_sub', $sub)->count();
            $goalsCount = DB::table('finance_saving_goals')->where('user_sub', $sub)->count();

            DB::table('finance_users')->where('sub', $sub)->update([
                'wallets_count' => $walletsCount,
                'transactions_count' => $txCount,
                'budgets_count' => $budgetsCount,
                'goals_count' => $goalsCount,
                'last_sync_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return response()->json([
            'ok' => true,
            'sub' => $sub,
            'plan_tier' => $userRow->plan_tier ?? 'pro',
            'syncedAt' => $now->toIso8601String(),
        ]);
    }
}
