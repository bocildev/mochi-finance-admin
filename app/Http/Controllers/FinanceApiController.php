<?php

namespace App\Http\Controllers;

use App\Services\FinanceVaultService;
use App\Services\GeminiPlannerService;
use App\Services\NotificationParserService;
use Carbon\Carbon;
use Iam\Client\Claims;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceApiController extends Controller
{
    public function __construct(
        private FinanceVaultService $vault,
        private NotificationParserService $parser,
        private GeminiPlannerService $gemini
    ) {}

    public function corsPreflight(Request $request)
    {
        return response('', 204, [
            'Access-Control-Allow-Origin' => $request->header('Origin', '*'),
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept, X-Requested-With',
        ]);
    }

    private function sub(Request $request): string
    {
        /** @var Claims $claims */
        $claims = $request->attributes->get('iam_claims');

        return $claims->sub;
    }

    private function userRow(Request $request): object
    {
        return $request->attributes->get('iam_user');
    }

    private function requirePro(Request $request, string $featureName): ?JsonResponse
    {
        if ($this->vault->isFree($this->userRow($request))) {
            return response()->json([
                'error' => 'pro_required',
                'message' => "Fitur {$featureName} khusus untuk member Mochi Pro ✨.",
            ], 403);
        }

        return null;
    }

    private function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    // ==================== BOOTSTRAP & CATEGORIES ====================

    public function bootstrap(Request $request): JsonResponse
    {
        $sub = $this->sub($request);
        $userRow = $this->userRow($request);

        return response()->json($this->vault->getVaultSnapshot($sub, $userRow));
    }

    public function categories(Request $request): JsonResponse
    {
        $sub = $this->sub($request);
        $this->vault->ensureDefaultCategories($sub);

        $categories = DB::table('finance_categories')
            ->where('user_sub', $sub)
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => $this->vault->mapCategory($c))
            ->values();

        return response()->json(['categories' => $categories]);
    }

    // ==================== WALLETS ====================

    public function listWallets(Request $request): JsonResponse
    {
        return response()->json(['wallets' => $this->vault->listWallets($this->sub($request))]);
    }

    public function storeWallet(Request $request): JsonResponse
    {
        $sub = $this->sub($request);
        $userRow = $this->userRow($request);

        $maxWallets = $this->vault->maxWalletsForUser($userRow);
        if ($maxWallets > 0) {
            $currentCount = DB::table('finance_wallets')->where('user_sub', $sub)->count();
            if ($currentCount >= $maxWallets) {
                return response()->json([
                    'error' => 'wallet_limit_reached',
                    'message' => "Paket Mochi Free Starter dibatasi maksimal {$maxWallets} dompet/rekening. Upgrade ke Mochi Pro ✨ untuk menambah dompet tanpa batas!",
                ], 403);
            }
        }

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'type' => 'required|in:bank,ewallet,cash,investment,credit',
            'balance' => 'nullable|numeric',
            'accountNumber' => 'nullable|string|max:64',
            'icon' => 'nullable|string|max:64',
            'color' => 'nullable|string|max:32',
        ]);

        $now = Carbon::now();
        $nowMs = $this->nowMs();
        $clientId = 'wallet-'.$nowMs.'-'.Str::lower(Str::random(4));

        DB::table('finance_wallets')->insert([
            'user_sub' => $sub,
            'client_id' => $clientId,
            'name' => trim($data['name']),
            'type' => $data['type'],
            'balance' => (float) ($data['balance'] ?? 0),
            'account_number' => $data['accountNumber'] ?? null,
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? '#f472b6',
            'created_ms' => $nowMs,
            'updated_ms' => $nowMs,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->vault->refreshUserMetrics($sub);
        $wallet = DB::table('finance_wallets')->where('user_sub', $sub)->where('client_id', $clientId)->first();

        return response()->json(['wallet' => $this->vault->mapWallet($wallet)], 201);
    }

    public function updateWallet(Request $request, string $id): JsonResponse
    {
        $sub = $this->sub($request);
        $existing = DB::table('finance_wallets')
            ->where('user_sub', $sub)
            ->where('client_id', $id)
            ->first();

        if (! $existing) {
            return response()->json(['error' => 'not_found', 'message' => 'Dompet tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'type' => 'sometimes|required|in:bank,ewallet,cash,investment,credit',
            'balance' => 'sometimes|numeric',
            'accountNumber' => 'nullable|string|max:64',
            'icon' => 'nullable|string|max:64',
            'color' => 'nullable|string|max:32',
        ]);

        $now = Carbon::now();
        $updates = [
            'updated_ms' => $this->nowMs(),
            'updated_at' => $now,
        ];
        if (array_key_exists('name', $data)) {
            $updates['name'] = trim($data['name']);
        }
        if (array_key_exists('type', $data)) {
            $updates['type'] = $data['type'];
        }
        if (array_key_exists('balance', $data)) {
            $updates['balance'] = (float) $data['balance'];
        }
        if (array_key_exists('accountNumber', $data)) {
            $updates['account_number'] = $data['accountNumber'];
        }
        if (array_key_exists('icon', $data)) {
            $updates['icon'] = $data['icon'];
        }
        if (array_key_exists('color', $data)) {
            $updates['color'] = $data['color'];
        }

        DB::table('finance_wallets')
            ->where('user_sub', $sub)
            ->where('client_id', $id)
            ->update($updates);

        $this->vault->refreshUserMetrics($sub);
        $updated = DB::table('finance_wallets')->where('user_sub', $sub)->where('client_id', $id)->first();

        return response()->json(['wallet' => $this->vault->mapWallet($updated)]);
    }

    public function destroyWallet(Request $request, string $id): JsonResponse
    {
        $sub = $this->sub($request);
        $deleted = DB::table('finance_wallets')
            ->where('user_sub', $sub)
            ->where('client_id', $id)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['error' => 'not_found', 'message' => 'Dompet tidak ditemukan.'], 404);
        }

        $this->vault->refreshUserMetrics($sub);

        return response()->json(['deleted' => true, 'id' => $id]);
    }

    // ==================== TRANSACTIONS ====================

    public function listTransactions(Request $request): JsonResponse
    {
        $sub = $this->sub($request);
        $transactions = DB::table('finance_transactions')
            ->where('user_sub', $sub)
            ->orderByDesc('tx_date')
            ->orderByDesc('created_ms')
            ->get()
            ->map(fn ($t) => $this->vault->mapTransaction($t))
            ->values();

        return response()->json(['transactions' => $transactions]);
    }

    public function storeTransaction(Request $request): JsonResponse
    {
        $sub = $this->sub($request);
        $userRow = $this->userRow($request);

        if ($this->vault->isTrialExpired($userRow)) {
            return response()->json([
                'error' => 'trial_expired',
                'message' => 'Masa trial 14 hari pencatatan transaksi Anda telah berakhir. Upgrade ke Mochi Pro ✨ untuk melanjutkan!',
            ], 403);
        }

        $data = $request->validate([
            'walletId' => 'required|string|max:100',
            'targetWalletId' => 'nullable|string|max:100|different:walletId|required_if:type,transfer',
            'amount' => 'required|numeric|gt:0',
            'type' => 'required|in:expense,income,transfer',
            'categoryId' => 'required|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'date' => 'nullable|string|max:40',
            'receiptImage' => 'nullable|string|max:5000000',
            'tags' => 'nullable|array',
            'inboxRefId' => 'nullable|string|max:100',
        ]);

        $now = Carbon::now();
        $nowMs = $this->nowMs();
        $amount = (float) $data['amount'];
        $txId = 'tx-'.$nowMs.'-'.Str::lower(Str::random(4));

        $error = null;
        $createdTx = null;

        DB::transaction(function () use ($sub, $data, $now, $nowMs, $amount, $txId, &$error, &$createdTx) {
            $wallet = DB::table('finance_wallets')
                ->where('user_sub', $sub)
                ->where('client_id', $data['walletId'])
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $error = ['status' => 404, 'code' => 'wallet_not_found', 'message' => 'Dompet asal tidak ditemukan.'];

                return;
            }

            if ($data['type'] === 'expense') {
                DB::table('finance_wallets')
                    ->where('id', $wallet->id)
                    ->update([
                        'balance' => (float) $wallet->balance - $amount,
                        'updated_ms' => $nowMs,
                        'updated_at' => $now,
                    ]);
            } elseif ($data['type'] === 'income') {
                DB::table('finance_wallets')
                    ->where('id', $wallet->id)
                    ->update([
                        'balance' => (float) $wallet->balance + $amount,
                        'updated_ms' => $nowMs,
                        'updated_at' => $now,
                    ]);
            } elseif ($data['type'] === 'transfer') {
                $targetWallet = DB::table('finance_wallets')
                    ->where('user_sub', $sub)
                    ->where('client_id', $data['targetWalletId'])
                    ->lockForUpdate()
                    ->first();

                if (! $targetWallet) {
                    $error = ['status' => 404, 'code' => 'target_wallet_not_found', 'message' => 'Dompet tujuan transfer tidak ditemukan.'];

                    return;
                }

                DB::table('finance_wallets')->where('id', $wallet->id)->update([
                    'balance' => (float) $wallet->balance - $amount,
                    'updated_ms' => $nowMs,
                    'updated_at' => $now,
                ]);
                DB::table('finance_wallets')->where('id', $targetWallet->id)->update([
                    'balance' => (float) $targetWallet->balance + $amount,
                    'updated_ms' => $nowMs,
                    'updated_at' => $now,
                ]);
            }

            DB::table('finance_transactions')->insert([
                'user_sub' => $sub,
                'client_id' => $txId,
                'wallet_id' => $data['walletId'],
                'target_wallet_id' => $data['type'] === 'transfer' ? $data['targetWalletId'] : null,
                'amount' => $amount,
                'type' => $data['type'],
                'category_id' => $data['categoryId'],
                'notes' => (string) ($data['notes'] ?? ''),
                'tx_date' => ! empty($data['date']) ? $data['date'] : $now->toIso8601String(),
                'receipt_image' => $data['receiptImage'] ?? null,
                'tags' => isset($data['tags']) ? json_encode($data['tags']) : json_encode([]),
                'inbox_ref_id' => $data['inboxRefId'] ?? null,
                'created_ms' => $nowMs,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->vault->refreshUserMetrics($sub);
            $createdTx = DB::table('finance_transactions')->where('user_sub', $sub)->where('client_id', $txId)->first();
        });

        if ($error !== null) {
            return response()->json(['error' => $error['code'], 'message' => $error['message']], $error['status']);
        }

        return response()->json([
            'transaction' => $this->vault->mapTransaction($createdTx),
            'wallets' => $this->vault->listWallets($sub),
        ], 201);
    }

    public function destroyTransaction(Request $request, string $id): JsonResponse
    {
        $sub = $this->sub($request);
        $now = Carbon::now();
        $nowMs = $this->nowMs();
        $found = false;

        DB::transaction(function () use ($sub, $id, $now, $nowMs, &$found) {
            $tx = DB::table('finance_transactions')
                ->where('user_sub', $sub)
                ->where('client_id', $id)
                ->first();

            if (! $tx) {
                return;
            }
            $found = true;
            $amount = (float) $tx->amount;

            $wallet = DB::table('finance_wallets')
                ->where('user_sub', $sub)
                ->where('client_id', $tx->wallet_id)
                ->lockForUpdate()
                ->first();

            if ($wallet) {
                if ($tx->type === 'expense') {
                    DB::table('finance_wallets')->where('id', $wallet->id)->update([
                        'balance' => (float) $wallet->balance + $amount,
                        'updated_ms' => $nowMs,
                        'updated_at' => $now,
                    ]);
                } elseif ($tx->type === 'income') {
                    DB::table('finance_wallets')->where('id', $wallet->id)->update([
                        'balance' => (float) $wallet->balance - $amount,
                        'updated_ms' => $nowMs,
                        'updated_at' => $now,
                    ]);
                } elseif ($tx->type === 'transfer') {
                    DB::table('finance_wallets')->where('id', $wallet->id)->update([
                        'balance' => (float) $wallet->balance + $amount,
                        'updated_ms' => $nowMs,
                        'updated_at' => $now,
                    ]);
                    if ($tx->target_wallet_id) {
                        $target = DB::table('finance_wallets')
                            ->where('user_sub', $sub)
                            ->where('client_id', $tx->target_wallet_id)
                            ->lockForUpdate()
                            ->first();
                        if ($target) {
                            DB::table('finance_wallets')->where('id', $target->id)->update([
                                'balance' => (float) $target->balance - $amount,
                                'updated_ms' => $nowMs,
                                'updated_at' => $now,
                            ]);
                        }
                    }
                }
            }

            DB::table('finance_transactions')->where('id', $tx->id)->delete();
            $this->vault->refreshUserMetrics($sub);
        });

        if (! $found) {
            return response()->json(['error' => 'not_found', 'message' => 'Transaksi tidak ditemukan.'], 404);
        }

        return response()->json([
            'deleted' => true,
            'id' => $id,
            'wallets' => $this->vault->listWallets($sub),
        ]);
    }

    // ==================== BUDGETS (PRO) ====================

    public function storeBudget(Request $request): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Alokasi Anggaran Bulanan')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $data = $request->validate([
            'categoryId' => 'required|string|max:100',
            'monthlyLimit' => 'required|numeric|gt:0',
            'month' => 'nullable|string|max:16',
        ]);

        $now = Carbon::now();
        $month = ! empty($data['month']) ? $data['month'] : $now->format('Y-m');

        $existing = DB::table('finance_budgets')
            ->where('user_sub', $sub)
            ->where('category_id', $data['categoryId'])
            ->where('month', $month)
            ->first();

        if ($existing) {
            DB::table('finance_budgets')->where('id', $existing->id)->update([
                'monthly_limit' => (float) $data['monthlyLimit'],
                'updated_at' => $now,
            ]);
            $row = DB::table('finance_budgets')->where('id', $existing->id)->first();
            $this->vault->refreshUserMetrics($sub);

            return response()->json(['budget' => $this->vault->mapBudget($row)], 200);
        }

        $clientId = 'b-'.$this->nowMs().'-'.Str::lower(Str::random(4));
        DB::table('finance_budgets')->insert([
            'user_sub' => $sub,
            'client_id' => $clientId,
            'category_id' => $data['categoryId'],
            'monthly_limit' => (float) $data['monthlyLimit'],
            'month' => $month,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->vault->refreshUserMetrics($sub);
        $row = DB::table('finance_budgets')->where('user_sub', $sub)->where('client_id', $clientId)->first();

        return response()->json(['budget' => $this->vault->mapBudget($row)], 201);
    }

    public function updateBudget(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Alokasi Anggaran Bulanan')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $existing = DB::table('finance_budgets')->where('user_sub', $sub)->where('client_id', $id)->first();
        if (! $existing) {
            return response()->json(['error' => 'not_found', 'message' => 'Anggaran tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'monthlyLimit' => 'required|numeric|gt:0',
        ]);

        DB::table('finance_budgets')->where('id', $existing->id)->update([
            'monthly_limit' => (float) $data['monthlyLimit'],
            'updated_at' => Carbon::now(),
        ]);

        $this->vault->refreshUserMetrics($sub);
        $updated = DB::table('finance_budgets')->where('id', $existing->id)->first();

        return response()->json(['budget' => $this->vault->mapBudget($updated)]);
    }

    public function destroyBudget(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Alokasi Anggaran Bulanan')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $deleted = DB::table('finance_budgets')->where('user_sub', $sub)->where('client_id', $id)->delete();
        if ($deleted === 0) {
            return response()->json(['error' => 'not_found', 'message' => 'Anggaran tidak ditemukan.'], 404);
        }

        $this->vault->refreshUserMetrics($sub);

        return response()->json(['deleted' => true, 'id' => $id]);
    }

    public function batchStoreBudgets(Request $request): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Alokasi Anggaran Bulanan')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'budgets' => 'required|array|min:1',
            'budgets.*.categoryId' => 'required|string|max:100',
            'budgets.*.monthlyLimit' => 'required|numeric|min:0',
        ]);

        $now = Carbon::now();
        $month = $data['month'];
        $nowMs = $this->nowMs();

        DB::transaction(function () use ($sub, $month, $data, $now, $nowMs) {
            foreach ($data['budgets'] as $idx => $item) {
                $catId = (string) $item['categoryId'];
                $limit = (float) $item['monthlyLimit'];

                $existing = DB::table('finance_budgets')
                    ->where('user_sub', $sub)
                    ->where('category_id', $catId)
                    ->where('month', $month)
                    ->first();

                if ($limit <= 0) {
                    if ($existing) {
                        DB::table('finance_budgets')->where('id', $existing->id)->delete();
                    }

                    continue;
                }

                if ($existing) {
                    DB::table('finance_budgets')->where('id', $existing->id)->update([
                        'monthly_limit' => $limit,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('finance_budgets')->insert([
                        'user_sub' => $sub,
                        'client_id' => 'b-'.($nowMs + $idx).'-'.Str::lower(Str::random(4)),
                        'category_id' => $catId,
                        'monthly_limit' => $limit,
                        'month' => $month,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $this->vault->refreshUserMetrics($sub);
        });

        $budgets = DB::table('finance_budgets')
            ->where('user_sub', $sub)
            ->orderBy('id')
            ->get()
            ->map(fn ($b) => $this->vault->mapBudget($b))
            ->values();

        return response()->json(['budgets' => $budgets]);
    }

    // ==================== SAVING GOALS (PRO) ====================

    public function storeSavingGoal(Request $request): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Celengan Impian')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'targetAmount' => 'required|numeric|gt:0',
            'targetDate' => 'nullable|string|max:24',
            'icon' => 'nullable|string|max:64',
            'color' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:1000',
        ]);

        $now = Carbon::now();
        $nowMs = $this->nowMs();
        $clientId = 'goal-'.$nowMs.'-'.Str::lower(Str::random(4));

        DB::table('finance_saving_goals')->insert([
            'user_sub' => $sub,
            'client_id' => $clientId,
            'name' => trim($data['name']),
            'target_amount' => (float) $data['targetAmount'],
            'current_amount' => 0,
            'target_date' => ! empty($data['targetDate']) ? $data['targetDate'] : null,
            'icon' => $data['icon'] ?? 'Shield',
            'color' => $data['color'] ?? '#f472b6',
            'notes' => $data['notes'] ?? null,
            'created_ms' => $nowMs,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->vault->refreshUserMetrics($sub);
        $row = DB::table('finance_saving_goals')->where('user_sub', $sub)->where('client_id', $clientId)->first();

        return response()->json(['savingGoal' => $this->vault->mapSavingGoal($row)], 201);
    }

    public function updateSavingGoal(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Celengan Impian')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $existing = DB::table('finance_saving_goals')->where('user_sub', $sub)->where('client_id', $id)->first();
        if (! $existing) {
            return response()->json(['error' => 'not_found', 'message' => 'Celengan tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'targetAmount' => 'sometimes|required|numeric|gt:0',
            'targetDate' => 'nullable|string|max:24',
            'icon' => 'nullable|string|max:64',
            'color' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:1000',
        ]);

        $updates = ['updated_at' => Carbon::now()];
        if (array_key_exists('name', $data)) {
            $updates['name'] = trim($data['name']);
        }
        if (array_key_exists('targetAmount', $data)) {
            $updates['target_amount'] = (float) $data['targetAmount'];
        }
        if (array_key_exists('targetDate', $data)) {
            $updates['target_date'] = $data['targetDate'] ?: null;
        }
        if (array_key_exists('icon', $data)) {
            $updates['icon'] = $data['icon'];
        }
        if (array_key_exists('color', $data)) {
            $updates['color'] = $data['color'];
        }
        if (array_key_exists('notes', $data)) {
            $updates['notes'] = $data['notes'];
        }

        DB::table('finance_saving_goals')->where('id', $existing->id)->update($updates);
        $this->vault->refreshUserMetrics($sub);
        $updated = DB::table('finance_saving_goals')->where('id', $existing->id)->first();

        return response()->json(['savingGoal' => $this->vault->mapSavingGoal($updated)]);
    }

    public function depositSavingGoal(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Celengan Impian')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $data = $request->validate([
            'walletId' => 'required|string|max:100',
            'amount' => 'required|numeric|gt:0',
        ]);

        $now = Carbon::now();
        $nowMs = $this->nowMs();
        $amount = (float) $data['amount'];
        $txId = 'tx-'.$nowMs.'-'.Str::lower(Str::random(4));

        $error = null;
        $updatedGoal = null;
        $createdTx = null;

        DB::transaction(function () use ($sub, $id, $data, $now, $nowMs, $amount, $txId, &$error, &$updatedGoal, &$createdTx) {
            $goal = DB::table('finance_saving_goals')->where('user_sub', $sub)->where('client_id', $id)->lockForUpdate()->first();
            if (! $goal) {
                $error = ['status' => 404, 'code' => 'not_found', 'message' => 'Celengan tidak ditemukan.'];

                return;
            }

            $wallet = DB::table('finance_wallets')->where('user_sub', $sub)->where('client_id', $data['walletId'])->lockForUpdate()->first();
            if (! $wallet) {
                $error = ['status' => 404, 'code' => 'wallet_not_found', 'message' => 'Dompet tidak ditemukan.'];

                return;
            }

            if ((float) $wallet->balance < $amount) {
                $error = ['status' => 422, 'code' => 'insufficient_balance', 'message' => 'Saldo dompet tidak mencukupi.'];

                return;
            }

            DB::table('finance_wallets')->where('id', $wallet->id)->update([
                'balance' => (float) $wallet->balance - $amount,
                'updated_ms' => $nowMs,
                'updated_at' => $now,
            ]);

            DB::table('finance_transactions')->insert([
                'user_sub' => $sub,
                'client_id' => $txId,
                'wallet_id' => $wallet->client_id,
                'target_wallet_id' => null,
                'amount' => $amount,
                'type' => 'expense',
                'category_id' => 'cat-other-income',
                'notes' => 'Nabung ke Celengan: '.$goal->name,
                'tx_date' => $now->toIso8601String(),
                'receipt_image' => null,
                'tags' => json_encode([]),
                'inbox_ref_id' => null,
                'created_ms' => $nowMs,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('finance_saving_goals')->where('id', $goal->id)->update([
                'current_amount' => (float) $goal->current_amount + $amount,
                'updated_at' => $now,
            ]);

            $this->vault->refreshUserMetrics($sub);
            $updatedGoal = DB::table('finance_saving_goals')->where('id', $goal->id)->first();
            $createdTx = DB::table('finance_transactions')->where('user_sub', $sub)->where('client_id', $txId)->first();
        });

        if ($error !== null) {
            return response()->json(['error' => $error['code'], 'message' => $error['message']], $error['status']);
        }

        return response()->json([
            'savingGoal' => $this->vault->mapSavingGoal($updatedGoal),
            'transaction' => $this->vault->mapTransaction($createdTx),
            'wallets' => $this->vault->listWallets($sub),
        ]);
    }

    public function withdrawSavingGoal(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Celengan Impian')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $data = $request->validate([
            'walletId' => 'required|string|max:100',
            'amount' => 'required|numeric|gt:0',
        ]);

        $now = Carbon::now();
        $nowMs = $this->nowMs();
        $amount = (float) $data['amount'];
        $txId = 'tx-'.$nowMs.'-'.Str::lower(Str::random(4));

        $error = null;
        $updatedGoal = null;
        $createdTx = null;

        DB::transaction(function () use ($sub, $id, $data, $now, $nowMs, $amount, $txId, &$error, &$updatedGoal, &$createdTx) {
            $goal = DB::table('finance_saving_goals')->where('user_sub', $sub)->where('client_id', $id)->lockForUpdate()->first();
            if (! $goal) {
                $error = ['status' => 404, 'code' => 'not_found', 'message' => 'Celengan tidak ditemukan.'];

                return;
            }

            if ((float) $goal->current_amount < $amount) {
                $error = ['status' => 422, 'code' => 'insufficient_goal_balance', 'message' => 'Saldo celengan tidak mencukupi.'];

                return;
            }

            $wallet = DB::table('finance_wallets')->where('user_sub', $sub)->where('client_id', $data['walletId'])->lockForUpdate()->first();
            if (! $wallet) {
                $error = ['status' => 404, 'code' => 'wallet_not_found', 'message' => 'Dompet tujuan tidak ditemukan.'];

                return;
            }

            DB::table('finance_wallets')->where('id', $wallet->id)->update([
                'balance' => (float) $wallet->balance + $amount,
                'updated_ms' => $nowMs,
                'updated_at' => $now,
            ]);

            DB::table('finance_transactions')->insert([
                'user_sub' => $sub,
                'client_id' => $txId,
                'wallet_id' => $wallet->client_id,
                'target_wallet_id' => null,
                'amount' => $amount,
                'type' => 'income',
                'category_id' => 'cat-other-income',
                'notes' => 'Cairkan Celengan: '.$goal->name,
                'tx_date' => $now->toIso8601String(),
                'receipt_image' => null,
                'tags' => json_encode([]),
                'inbox_ref_id' => null,
                'created_ms' => $nowMs,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('finance_saving_goals')->where('id', $goal->id)->update([
                'current_amount' => (float) $goal->current_amount - $amount,
                'updated_at' => $now,
            ]);

            $this->vault->refreshUserMetrics($sub);
            $updatedGoal = DB::table('finance_saving_goals')->where('id', $goal->id)->first();
            $createdTx = DB::table('finance_transactions')->where('user_sub', $sub)->where('client_id', $txId)->first();
        });

        if ($error !== null) {
            return response()->json(['error' => $error['code'], 'message' => $error['message']], $error['status']);
        }

        return response()->json([
            'savingGoal' => $this->vault->mapSavingGoal($updatedGoal),
            'transaction' => $this->vault->mapTransaction($createdTx),
            'wallets' => $this->vault->listWallets($sub),
        ]);
    }

    public function destroySavingGoal(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Celengan Impian')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $deleted = DB::table('finance_saving_goals')->where('user_sub', $sub)->where('client_id', $id)->delete();
        if ($deleted === 0) {
            return response()->json(['error' => 'not_found', 'message' => 'Celengan tidak ditemukan.'], 404);
        }

        $this->vault->refreshUserMetrics($sub);

        return response()->json(['deleted' => true, 'id' => $id]);
    }

    // ==================== RECURRING BILLS (PRO) ====================

    public function storeRecurringBill(Request $request): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Tagihan & Langganan Rutin')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'amount' => 'required|numeric|gt:0',
            'categoryId' => 'required|string|max:100',
            'walletId' => 'required|string|max:100',
            'dueDay' => 'required|integer|min:1|max:31',
            'billingCycle' => 'nullable|in:monthly,yearly',
            'billType' => 'nullable|in:subscription,installment,debt',
            'totalTenor' => 'nullable|integer|min:1|max:600|required_if:billType,installment',
            'paidCount' => 'nullable|integer|min:0|max:600',
            'targetMonth' => ['nullable', 'required_if:billType,debt', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'creditorName' => 'nullable|string|max:120',
            'paidAmount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $billType = $data['billType'] ?? 'subscription';
        $amount = (float) $data['amount'];
        $totalTenor = null;
        $paidCount = 0;
        $targetMonth = null;
        $creditorName = null;
        $paidAmount = 0.0;
        $isActive = true;

        if ($billType === 'installment') {
            $totalTenor = (int) $data['totalTenor'];
            $paidCount = (int) ($data['paidCount'] ?? 0);
            if ($paidCount > $totalTenor) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'Jumlah cicilan yang sudah dibayar tidak boleh melebihi total tenor.',
                ], 422);
            }
            $isActive = $paidCount < $totalTenor;
        } elseif ($billType === 'debt') {
            $targetMonth = (string) $data['targetMonth'];
            $creditorName = ! empty($data['creditorName']) ? trim((string) $data['creditorName']) : null;
            $paidAmount = (float) ($data['paidAmount'] ?? 0);
            if ($paidAmount > $amount) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'Nominal hutang yang sudah dibayar tidak boleh melebihi total hutang.',
                ], 422);
            }
            $totalTenor = 1;
            $isPaidOff = ($paidAmount + 0.009) >= $amount;
            $paidCount = $isPaidOff ? 1 : 0;
            $isActive = ! $isPaidOff;
        }

        $now = Carbon::now();
        $nowMs = $this->nowMs();
        $clientId = 'rec-'.$nowMs.'-'.Str::lower(Str::random(4));

        DB::table('finance_recurring_bills')->insert([
            'user_sub' => $sub,
            'client_id' => $clientId,
            'name' => trim($data['name']),
            'amount' => $amount,
            'category_id' => $data['categoryId'],
            'wallet_id' => $data['walletId'],
            'due_day' => (int) $data['dueDay'],
            'billing_cycle' => $data['billingCycle'] ?? 'monthly',
            'bill_type' => $billType,
            'total_tenor' => $totalTenor,
            'paid_count' => $paidCount,
            'target_month' => $targetMonth,
            'creditor_name' => $creditorName,
            'paid_amount' => $paidAmount,
            'is_active' => $isActive,
            'notes' => $data['notes'] ?? null,
            'last_paid_date' => null,
            'created_ms' => $nowMs,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->vault->refreshUserMetrics($sub);
        $row = DB::table('finance_recurring_bills')->where('user_sub', $sub)->where('client_id', $clientId)->first();

        return response()->json(['recurringBill' => $this->vault->mapRecurringBill($row)], 201);
    }

    public function updateRecurringBill(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Tagihan & Langganan Rutin')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $existing = DB::table('finance_recurring_bills')->where('user_sub', $sub)->where('client_id', $id)->first();
        if (! $existing) {
            return response()->json(['error' => 'not_found', 'message' => 'Tagihan tidak ditemukan.'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'amount' => 'sometimes|required|numeric|gt:0',
            'categoryId' => 'sometimes|required|string|max:100',
            'walletId' => 'sometimes|required|string|max:100',
            'dueDay' => 'sometimes|required|integer|min:1|max:31',
            'billingCycle' => 'sometimes|in:monthly,yearly',
            'billType' => 'sometimes|in:subscription,installment,debt',
            'totalTenor' => 'nullable|integer|min:1|max:600',
            'paidCount' => 'nullable|integer|min:0|max:600',
            'targetMonth' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'creditorName' => 'nullable|string|max:120',
            'paidAmount' => 'nullable|numeric|min:0',
            'isActive' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $resolvedType = $data['billType'] ?? ($existing->bill_type ?? 'subscription');
        $resolvedAmount = array_key_exists('amount', $data) ? (float) $data['amount'] : (float) $existing->amount;
        $resolvedTenor = array_key_exists('totalTenor', $data)
            ? ($data['totalTenor'] !== null ? (int) $data['totalTenor'] : null)
            : ($existing->total_tenor !== null ? (int) $existing->total_tenor : null);
        $resolvedPaid = array_key_exists('paidCount', $data)
            ? (int) ($data['paidCount'] ?? 0)
            : (int) ($existing->paid_count ?? 0);
        $resolvedTargetMonth = array_key_exists('targetMonth', $data)
            ? ($data['targetMonth'] ? (string) $data['targetMonth'] : null)
            : ($existing->target_month ?? null);
        $resolvedCreditor = array_key_exists('creditorName', $data)
            ? ($data['creditorName'] !== null && trim((string) $data['creditorName']) !== '' ? trim((string) $data['creditorName']) : null)
            : ($existing->creditor_name ?? null);
        $resolvedPaidAmount = array_key_exists('paidAmount', $data)
            ? (float) ($data['paidAmount'] ?? 0)
            : (float) ($existing->paid_amount ?? 0);

        if ($resolvedType === 'installment') {
            if (! $resolvedTenor || $resolvedTenor < 1) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'Total tenor cicilan wajib diisi minimal 1 kali.',
                ], 422);
            }
            if ($resolvedPaid > $resolvedTenor) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'Jumlah cicilan yang sudah dibayar tidak boleh melebihi total tenor.',
                ], 422);
            }
            $resolvedTargetMonth = null;
            $resolvedCreditor = null;
            $resolvedPaidAmount = 0.0;
        } elseif ($resolvedType === 'debt') {
            if (! $resolvedTargetMonth) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'Bulan jatuh tempo hutang wajib dipilih (format YYYY-MM).',
                ], 422);
            }
            if ($resolvedPaidAmount > $resolvedAmount) {
                return response()->json([
                    'error' => 'validation_error',
                    'message' => 'Nominal yang sudah dibayar tidak boleh melebihi total hutang.',
                ], 422);
            }
            $resolvedTenor = 1;
            $resolvedPaid = ($resolvedPaidAmount + 0.009) >= $resolvedAmount ? 1 : 0;
        } else {
            $resolvedTenor = null;
            $resolvedPaid = 0;
            $resolvedTargetMonth = null;
            $resolvedCreditor = null;
            $resolvedPaidAmount = 0.0;
        }

        $updates = [
            'updated_at' => Carbon::now(),
            'bill_type' => $resolvedType,
            'total_tenor' => $resolvedTenor,
            'paid_count' => $resolvedPaid,
            'target_month' => $resolvedTargetMonth,
            'creditor_name' => $resolvedCreditor,
            'paid_amount' => $resolvedPaidAmount,
        ];
        if (array_key_exists('name', $data)) {
            $updates['name'] = trim($data['name']);
        }
        if (array_key_exists('amount', $data)) {
            $updates['amount'] = $resolvedAmount;
        }
        if (array_key_exists('categoryId', $data)) {
            $updates['category_id'] = $data['categoryId'];
        }
        if (array_key_exists('walletId', $data)) {
            $updates['wallet_id'] = $data['walletId'];
        }
        if (array_key_exists('dueDay', $data)) {
            $updates['due_day'] = (int) $data['dueDay'];
        }
        if (array_key_exists('billingCycle', $data)) {
            $updates['billing_cycle'] = $data['billingCycle'];
        }
        if ($resolvedType === 'installment') {
            if ($resolvedPaid >= $resolvedTenor) {
                $updates['is_active'] = false;
            } elseif (array_key_exists('isActive', $data)) {
                $updates['is_active'] = (bool) $data['isActive'];
            } else {
                $updates['is_active'] = true;
            }
        } elseif ($resolvedType === 'debt') {
            if (($resolvedPaidAmount + 0.009) >= $resolvedAmount) {
                $updates['is_active'] = false;
            } elseif (array_key_exists('isActive', $data)) {
                $updates['is_active'] = (bool) $data['isActive'];
            } else {
                $updates['is_active'] = true;
            }
        } elseif (array_key_exists('isActive', $data)) {
            $updates['is_active'] = (bool) $data['isActive'];
        }
        if (array_key_exists('notes', $data)) {
            $updates['notes'] = $data['notes'];
        }

        DB::table('finance_recurring_bills')->where('id', $existing->id)->update($updates);
        $this->vault->refreshUserMetrics($sub);
        $updated = DB::table('finance_recurring_bills')->where('id', $existing->id)->first();

        return response()->json(['recurringBill' => $this->vault->mapRecurringBill($updated)]);
    }

    public function toggleRecurringBill(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Tagihan & Langganan Rutin')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $existing = DB::table('finance_recurring_bills')->where('user_sub', $sub)->where('client_id', $id)->first();
        if (! $existing) {
            return response()->json(['error' => 'not_found', 'message' => 'Tagihan tidak ditemukan.'], 404);
        }

        $type = $existing->bill_type ?? 'subscription';
        $isInstallmentPaidOff = $type === 'installment'
            && $existing->total_tenor !== null
            && (int) $existing->paid_count >= (int) $existing->total_tenor;
        $isDebtPaidOff = $type === 'debt'
            && ((float) ($existing->paid_amount ?? 0) + 0.009) >= (float) $existing->amount;

        if (($isInstallmentPaidOff || $isDebtPaidOff) && ! (bool) $existing->is_active) {
            return response()->json([
                'error' => 'already_paid_off',
                'message' => ($type === 'debt' ? 'Hutang' : 'Cicilan').' ini sudah lunas. Ubah nominal/tenor lewat Edit jika ingin mengaktifkannya lagi.',
            ], 422);
        }

        DB::table('finance_recurring_bills')->where('id', $existing->id)->update([
            'is_active' => ! (bool) $existing->is_active,
            'updated_at' => Carbon::now(),
        ]);

        $updated = DB::table('finance_recurring_bills')->where('id', $existing->id)->first();

        return response()->json(['recurringBill' => $this->vault->mapRecurringBill($updated)]);
    }

    public function payRecurringBill(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Tagihan & Langganan Rutin')) {
            return $denied;
        }

        $payData = $request->validate([
            'payAmount' => 'nullable|numeric|gt:0',
        ]);

        $sub = $this->sub($request);
        $now = Carbon::now();
        $nowMs = $this->nowMs();
        $txId = 'tx-'.$nowMs.'-'.Str::lower(Str::random(4));

        $error = null;
        $updatedBill = null;
        $createdTx = null;

        DB::transaction(function () use ($sub, $id, $payData, $now, $nowMs, $txId, &$error, &$updatedBill, &$createdTx) {
            $bill = DB::table('finance_recurring_bills')->where('user_sub', $sub)->where('client_id', $id)->lockForUpdate()->first();
            if (! $bill) {
                $error = ['status' => 404, 'code' => 'not_found', 'message' => 'Tagihan tidak ditemukan.'];

                return;
            }

            $billType = $bill->bill_type ?? 'subscription';
            $isInstallment = $billType === 'installment';
            $isDebt = $billType === 'debt';
            $totalTenor = $bill->total_tenor !== null ? (int) $bill->total_tenor : null;
            $paidCount = (int) ($bill->paid_count ?? 0);

            if ($isInstallment && $totalTenor !== null && $paidCount >= $totalTenor) {
                $error = ['status' => 422, 'code' => 'already_paid_off', 'message' => 'Cicilan ini sudah lunas seluruhnya.'];

                return;
            }

            $remainingDebt = max(0.0, (float) $bill->amount - (float) ($bill->paid_amount ?? 0));
            if ($isDebt && $remainingDebt <= 0.009) {
                $error = ['status' => 422, 'code' => 'already_paid_off', 'message' => 'Hutang ini sudah lunas seluruhnya.'];

                return;
            }

            $wallet = DB::table('finance_wallets')->where('user_sub', $sub)->where('client_id', $bill->wallet_id)->lockForUpdate()->first();
            if (! $wallet) {
                $error = ['status' => 422, 'code' => 'wallet_not_found', 'message' => 'Dompet pembayaran untuk tagihan ini tidak ditemukan.'];

                return;
            }

            $amount = (float) $bill->amount;
            $newPaidCount = $paidCount;
            $newPaidAmount = (float) ($bill->paid_amount ?? 0);

            if ($isInstallment) {
                $newPaidCount = $paidCount + 1;
                $txNote = $totalTenor !== null
                    ? "Bayar Cicilan ({$newPaidCount}/{$totalTenor}): {$bill->name}"
                    : "Bayar Cicilan: {$bill->name}";
            } elseif ($isDebt) {
                $requestedPay = isset($payData['payAmount']) ? (float) $payData['payAmount'] : $remainingDebt;
                $amount = min($remainingDebt, $requestedPay);
                $newPaidAmount = round($newPaidAmount + $amount, 2);
                $isNowPaidOff = ($newPaidAmount + 0.009) >= (float) $bill->amount;
                $newPaidCount = $isNowPaidOff ? 1 : 0;
                $monthTag = $bill->target_month ?: $now->format('Y-m');
                $txNote = ($isNowPaidOff ? "Lunasi Hutang ({$monthTag})" : "Cicil Hutang ({$monthTag})").": {$bill->name}";
            } else {
                $txNote = 'Bayar Tagihan: '.$bill->name;
            }

            DB::table('finance_wallets')->where('id', $wallet->id)->update([
                'balance' => (float) $wallet->balance - $amount,
                'updated_ms' => $nowMs,
                'updated_at' => $now,
            ]);

            DB::table('finance_transactions')->insert([
                'user_sub' => $sub,
                'client_id' => $txId,
                'wallet_id' => $wallet->client_id,
                'target_wallet_id' => null,
                'amount' => $amount,
                'type' => 'expense',
                'category_id' => $bill->category_id,
                'notes' => $txNote,
                'tx_date' => $now->toIso8601String(),
                'receipt_image' => null,
                'tags' => json_encode([]),
                'inbox_ref_id' => null,
                'created_ms' => $nowMs,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $billUpdates = [
                'last_paid_date' => $now->format('Y-m-d'),
                'updated_at' => $now,
            ];
            if ($isInstallment) {
                $billUpdates['paid_count'] = $newPaidCount;
                if ($totalTenor !== null && $newPaidCount >= $totalTenor) {
                    $billUpdates['is_active'] = false;
                }
            } elseif ($isDebt) {
                $billUpdates['paid_amount'] = $newPaidAmount;
                $billUpdates['paid_count'] = $newPaidCount;
                if ($newPaidCount === 1) {
                    $billUpdates['is_active'] = false;
                }
            }

            DB::table('finance_recurring_bills')->where('id', $bill->id)->update($billUpdates);

            $this->vault->refreshUserMetrics($sub);
            $updatedBill = DB::table('finance_recurring_bills')->where('id', $bill->id)->first();
            $createdTx = DB::table('finance_transactions')->where('user_sub', $sub)->where('client_id', $txId)->first();
        });

        if ($error !== null) {
            return response()->json(['error' => $error['code'], 'message' => $error['message']], $error['status']);
        }

        return response()->json([
            'recurringBill' => $this->vault->mapRecurringBill($updatedBill),
            'transaction' => $this->vault->mapTransaction($createdTx),
            'wallets' => $this->vault->listWallets($sub),
        ]);
    }

    public function destroyRecurringBill(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Tagihan & Langganan Rutin')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $deleted = DB::table('finance_recurring_bills')->where('user_sub', $sub)->where('client_id', $id)->delete();
        if ($deleted === 0) {
            return response()->json(['error' => 'not_found', 'message' => 'Tagihan tidak ditemukan.'], 404);
        }

        $this->vault->refreshUserMetrics($sub);

        return response()->json(['deleted' => true, 'id' => $id]);
    }

    // ==================== SMART INBOX (PRO) ====================

    public function listInbox(Request $request): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Smart Inbox Approval')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $items = DB::table('finance_inbox_items')
            ->where('user_sub', $sub)
            ->orderByDesc('created_ms')
            ->get()
            ->map(fn ($i) => $this->vault->mapInboxItem($i))
            ->values();

        return response()->json(['inbox' => $items]);
    }

    public function storeInbox(Request $request): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Smart Inbox Approval')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $rawText = (string) ($request->input('text') ?? $request->input('message') ?? $request->input('rawMessage') ?? $request->input('body') ?? '');
        if (trim($rawText) === '') {
            return response()->json(['error' => 'validation_error', 'message' => 'Field text atau message wajib diisi.'], 422);
        }

        $sourceHint = $request->input('source') ?? $request->input('sender');
        $parsed = $this->parser->parse($rawText, is_string($sourceHint) ? $sourceHint : null);

        $wallets = DB::table('finance_wallets')->where('user_sub', $sub)->orderBy('id')->get();
        $suggestedWalletId = $request->input('suggestedWalletId');
        if (! $suggestedWalletId && $wallets->isNotEmpty()) {
            $sourceLower = strtolower($parsed['source']);
            $matched = $wallets->first(fn ($w) => str_contains(strtolower($w->name), $sourceLower));
            $suggestedWalletId = ($matched ?: $wallets->first())->client_id;
        }

        $now = Carbon::now();
        $nowMs = $this->nowMs();
        $clientId = 'inbox-'.$nowMs.'-'.Str::lower(Str::random(4));

        DB::table('finance_inbox_items')->insert([
            'user_sub' => $sub,
            'client_id' => $clientId,
            'source' => $parsed['source'],
            'raw_message' => trim($rawText),
            'detected_amount' => $request->filled('detectedAmount') ? (float) $request->input('detectedAmount') : $parsed['amount'],
            'detected_type' => in_array($request->input('detectedType'), ['expense', 'income', 'transfer'], true)
                ? $request->input('detectedType')
                : $parsed['type'],
            'detected_merchant' => $request->input('detectedMerchant') ?: $parsed['merchant'],
            'detected_date' => $request->input('detectedDate') ?: $now->toIso8601String(),
            'suggested_wallet_id' => $suggestedWalletId,
            'suggested_category_id' => $request->input('suggestedCategoryId') ?: $parsed['suggestedCategory'],
            'status' => 'PENDING',
            'notes' => $request->input('notes') ?: $parsed['notes'],
            'created_ms' => $nowMs,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->vault->refreshUserMetrics($sub);
        $row = DB::table('finance_inbox_items')->where('user_sub', $sub)->where('client_id', $clientId)->first();

        return response()->json([
            'success' => true,
            'message' => 'Transaksi berhasil diterima di Staging Inbox.',
            'item' => $this->vault->mapInboxItem($row),
        ], 201);
    }

    public function approveInbox(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Smart Inbox Approval')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $data = $request->validate([
            'walletId' => 'nullable|string|max:100',
            'categoryId' => 'nullable|string|max:100',
            'amount' => 'nullable|numeric|gt:0',
            'notes' => 'nullable|string|max:1000',
            'receiptImage' => 'nullable|string|max:5000000',
        ]);

        $now = Carbon::now();
        $nowMs = $this->nowMs();
        $txId = 'tx-'.$nowMs.'-'.Str::lower(Str::random(4));

        $error = null;
        $updatedItem = null;
        $createdTx = null;

        DB::transaction(function () use ($sub, $id, $data, $now, $nowMs, $txId, &$error, &$updatedItem, &$createdTx) {
            $item = DB::table('finance_inbox_items')->where('user_sub', $sub)->where('client_id', $id)->lockForUpdate()->first();
            if (! $item) {
                $error = ['status' => 404, 'code' => 'not_found', 'message' => 'Item inbox tidak ditemukan.'];

                return;
            }
            if ($item->status === 'APPROVED') {
                $error = ['status' => 422, 'code' => 'already_approved', 'message' => 'Item inbox ini sudah disetujui sebelumnya.'];

                return;
            }

            $candidateWalletId = $data['walletId'] ?? $item->suggested_wallet_id;
            $wallet = $candidateWalletId
                ? DB::table('finance_wallets')->where('user_sub', $sub)->where('client_id', $candidateWalletId)->lockForUpdate()->first()
                : null;
            if (! $wallet) {
                $wallet = DB::table('finance_wallets')->where('user_sub', $sub)->orderBy('id')->lockForUpdate()->first();
            }
            if (! $wallet) {
                $error = ['status' => 422, 'code' => 'wallet_required', 'message' => 'Tambahkan minimal 1 dompet terlebih dahulu sebelum menyetujui transaksi inbox.'];

                return;
            }

            $amount = isset($data['amount']) ? (float) $data['amount'] : (float) $item->detected_amount;
            $type = in_array($item->detected_type, ['expense', 'income'], true) ? $item->detected_type : 'expense';
            $categoryId = $data['categoryId'] ?? $item->suggested_category_id ?? ($type === 'income' ? 'cat-salary' : 'cat-food');
            $notes = $data['notes'] ?? ($item->notes ?: $item->raw_message);

            $delta = $type === 'income' ? $amount : -$amount;
            DB::table('finance_wallets')->where('id', $wallet->id)->update([
                'balance' => (float) $wallet->balance + $delta,
                'updated_ms' => $nowMs,
                'updated_at' => $now,
            ]);

            DB::table('finance_transactions')->insert([
                'user_sub' => $sub,
                'client_id' => $txId,
                'wallet_id' => $wallet->client_id,
                'target_wallet_id' => null,
                'amount' => $amount,
                'type' => $type,
                'category_id' => $categoryId,
                'notes' => $notes,
                'tx_date' => $item->detected_date ?: $now->toIso8601String(),
                'receipt_image' => $data['receiptImage'] ?? null,
                'tags' => json_encode([]),
                'inbox_ref_id' => $item->client_id,
                'created_ms' => $nowMs,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('finance_inbox_items')->where('id', $item->id)->update([
                'status' => 'APPROVED',
                'updated_at' => $now,
            ]);

            $this->vault->refreshUserMetrics($sub);
            $updatedItem = DB::table('finance_inbox_items')->where('id', $item->id)->first();
            $createdTx = DB::table('finance_transactions')->where('user_sub', $sub)->where('client_id', $txId)->first();
        });

        if ($error !== null) {
            return response()->json(['error' => $error['code'], 'message' => $error['message']], $error['status']);
        }

        return response()->json([
            'item' => $this->vault->mapInboxItem($updatedItem),
            'transaction' => $this->vault->mapTransaction($createdTx),
            'wallets' => $this->vault->listWallets($sub),
        ]);
    }

    public function rejectInbox(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Smart Inbox Approval')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $existing = DB::table('finance_inbox_items')->where('user_sub', $sub)->where('client_id', $id)->first();
        if (! $existing) {
            return response()->json(['error' => 'not_found', 'message' => 'Item inbox tidak ditemukan.'], 404);
        }

        DB::table('finance_inbox_items')->where('id', $existing->id)->update([
            'status' => 'REJECTED',
            'updated_at' => Carbon::now(),
        ]);

        $updated = DB::table('finance_inbox_items')->where('id', $existing->id)->first();

        return response()->json(['item' => $this->vault->mapInboxItem($updated)]);
    }

    public function destroyInbox(Request $request, string $id): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Smart Inbox Approval')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $deleted = DB::table('finance_inbox_items')->where('user_sub', $sub)->where('client_id', $id)->delete();
        if ($deleted === 0) {
            return response()->json(['error' => 'not_found', 'message' => 'Item inbox tidak ditemukan.'], 404);
        }

        $this->vault->refreshUserMetrics($sub);

        return response()->json(['deleted' => true, 'id' => $id]);
    }

    // ==================== MONTHLY SALARY PLANS & AI ANALYSIS (PRO) ====================

    public function listMonthlyPlans(Request $request): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Perencana Gaji & Analisa AI')) {
            return $denied;
        }

        $sub = $this->sub($request);
        $plans = DB::table('finance_monthly_plans')
            ->where('user_sub', $sub)
            ->orderByDesc('month')
            ->get()
            ->map(fn ($p) => $this->vault->mapMonthlyPlan($p))
            ->values();

        return response()->json([
            'monthlyPlans' => $plans,
            'aiSettings' => [
                'configured' => $this->gemini->hasApiKey(),
                'defaultModel' => $this->gemini->getDefaultModel(),
                'models' => $this->gemini->availableModels(),
            ],
        ]);
    }

    public function upsertMonthlyPlan(Request $request, string $month): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Perencana Gaji & Analisa AI')) {
            return $denied;
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return response()->json(['error' => 'validation_error', 'message' => 'Format bulan harus YYYY-MM.'], 422);
        }

        $data = $request->validate([
            'monthlySalary' => 'required|numeric|min:0',
            'strategyNotes' => 'nullable|string|max:1000',
            'preferredModel' => 'nullable|string|max:64',
        ]);

        $sub = $this->sub($request);
        $now = Carbon::now();
        $model = $this->gemini->isValidModel($data['preferredModel'] ?? null)
            ? $data['preferredModel']
            : $this->gemini->getDefaultModel();

        $existing = DB::table('finance_monthly_plans')
            ->where('user_sub', $sub)
            ->where('month', $month)
            ->first();

        if ($existing) {
            DB::table('finance_monthly_plans')->where('id', $existing->id)->update([
                'monthly_salary' => (float) $data['monthlySalary'],
                'strategy_notes' => $data['strategyNotes'] ?? $existing->strategy_notes,
                'preferred_model' => $model,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('finance_monthly_plans')->insert([
                'user_sub' => $sub,
                'month' => $month,
                'monthly_salary' => (float) $data['monthlySalary'],
                'strategy_notes' => $data['strategyNotes'] ?? null,
                'preferred_model' => $model,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $row = DB::table('finance_monthly_plans')->where('user_sub', $sub)->where('month', $month)->first();

        return response()->json(['monthlyPlan' => $this->vault->mapMonthlyPlan($row)]);
    }

    public function analyzeMonthlyPlan(Request $request, string $month): JsonResponse
    {
        if ($denied = $this->requirePro($request, 'Perencana Gaji & Analisa AI')) {
            return $denied;
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return response()->json(['error' => 'validation_error', 'message' => 'Format bulan harus YYYY-MM.'], 422);
        }

        $data = $request->validate([
            'monthlySalary' => 'nullable|numeric|min:0',
            'strategyNotes' => 'nullable|string|max:1000',
            'preferredModel' => 'nullable|string|max:64',
        ]);

        $sub = $this->sub($request);
        $this->vault->ensureDefaultCategories($sub);
        $now = Carbon::now();

        $existing = DB::table('finance_monthly_plans')->where('user_sub', $sub)->where('month', $month)->first();
        $salary = array_key_exists('monthlySalary', $data) && $data['monthlySalary'] !== null
            ? (float) $data['monthlySalary']
            : (float) ($existing->monthly_salary ?? 0);
        $strategyNotes = array_key_exists('strategyNotes', $data)
            ? $data['strategyNotes']
            : ($existing->strategy_notes ?? null);
        $preferredModel = $this->gemini->isValidModel($data['preferredModel'] ?? null)
            ? $data['preferredModel']
            : ($existing->preferred_model ?? $this->gemini->getDefaultModel());

        if ($salary <= 0) {
            return response()->json([
                'error' => 'validation_error',
                'message' => 'Masukkan nominal gaji bulanan lebih dari Rp 0 sebelum menjalankan analisa AI.',
            ], 422);
        }

        $wallets = DB::table('finance_wallets')->where('user_sub', $sub)->get();
        $totalWalletBalance = (float) $wallets->sum('balance');

        $bills = DB::table('finance_recurring_bills')->where('user_sub', $sub)->get();
        $subscriptions = $bills->filter(fn ($b) => ($b->bill_type ?? 'subscription') === 'subscription' && (bool) $b->is_active);
        $installments = $bills->filter(fn ($b) => ($b->bill_type ?? 'subscription') === 'installment' && (bool) $b->is_active);
        $debtsForMonth = $bills->filter(function ($b) use ($month) {
            if (($b->bill_type ?? 'subscription') !== 'debt' || ($b->target_month ?? '') !== $month) {
                return false;
            }
            $remaining = max(0.0, (float) $b->amount - (float) ($b->paid_amount ?? 0));

            return $remaining > 0.009;
        });

        $subscriptionsTotal = (float) $subscriptions->sum('amount');
        $installmentsTotal = (float) $installments->sum('amount');
        $debtsTotal = (float) $debtsForMonth->sum(fn ($d) => max(0.0, (float) $d->amount - (float) ($d->paid_amount ?? 0)));
        $totalCommitments = $subscriptionsTotal + $installmentsTotal + $debtsTotal;
        $freeCashAfterCommitments = $salary - $totalCommitments;

        $categories = DB::table('finance_categories')->where('user_sub', $sub)->get();
        $expenseCategories = $categories
            ->filter(fn ($c) => $c->type === 'expense' && $c->client_id !== 'cat-transfer')
            ->map(fn ($c) => ['id' => $c->client_id, 'name' => $c->name])
            ->values()
            ->all();

        $txThisMonth = DB::table('finance_transactions')
            ->where('user_sub', $sub)
            ->where('tx_date', 'like', $month.'%')
            ->get();

        $spentByCategory = [];
        foreach ($txThisMonth as $t) {
            if ($t->type === 'expense') {
                $spentByCategory[$t->category_id] = ($spentByCategory[$t->category_id] ?? 0.0) + (float) $t->amount;
            }
        }

        $currentBudgets = DB::table('finance_budgets')
            ->where('user_sub', $sub)
            ->where('month', $month)
            ->get()
            ->map(fn ($b) => ['categoryId' => $b->category_id, 'monthlyLimit' => (float) $b->monthly_limit])
            ->values()
            ->all();

        $savingGoals = DB::table('finance_saving_goals')
            ->where('user_sub', $sub)
            ->get()
            ->map(fn ($g) => [
                'name' => $g->name,
                'targetAmount' => (float) $g->target_amount,
                'currentAmount' => (float) $g->current_amount,
            ])
            ->values()
            ->all();

        $context = [
            'month' => $month,
            'monthlySalary' => $salary,
            'totalWalletBalance' => $totalWalletBalance,
            'strategyNotes' => $strategyNotes,
            'commitmentsSummary' => [
                'subscriptionsMonthly' => $subscriptionsTotal,
                'installmentsMonthly' => $installmentsTotal,
                'debtsForSelectedMonth' => $debtsTotal,
                'totalMandatoryCommitments' => $totalCommitments,
                'remainingDisposableFromSalary' => $freeCashAfterCommitments,
            ],
            'subscriptions' => $subscriptions->map(fn ($s) => ['name' => $s->name, 'amount' => (float) $s->amount])->values()->all(),
            'installments' => $installments->map(fn ($i) => [
                'name' => $i->name,
                'amount' => (float) $i->amount,
                'paidCount' => (int) ($i->paid_count ?? 0),
                'totalTenor' => $i->total_tenor !== null ? (int) $i->total_tenor : null,
            ])->values()->all(),
            'debtsForSelectedMonth' => $debtsForMonth->map(fn ($d) => [
                'name' => $d->name,
                'creditor' => $d->creditor_name,
                'remainingAmount' => max(0.0, (float) $d->amount - (float) ($d->paid_amount ?? 0)),
                'dueDay' => (int) $d->due_day,
            ])->values()->all(),
            'expenseCategories' => $expenseCategories,
            'spentByCategoryThisMonth' => $spentByCategory,
            'existingBudgetsThisMonth' => $currentBudgets,
            'savingGoals' => $savingGoals,
        ];

        try {
            $analysis = $this->gemini->analyzeMonthlyPlan($month, $context, $preferredModel);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'ai_analysis_failed',
                'message' => $e->getMessage(),
            ], 422);
        }

        if ($existing) {
            DB::table('finance_monthly_plans')->where('id', $existing->id)->update([
                'monthly_salary' => $salary,
                'strategy_notes' => $strategyNotes,
                'preferred_model' => $analysis['modelUsed'] ?? $preferredModel,
                'ai_analysis' => json_encode($analysis, JSON_UNESCAPED_UNICODE),
                'last_analyzed_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('finance_monthly_plans')->insert([
                'user_sub' => $sub,
                'month' => $month,
                'monthly_salary' => $salary,
                'strategy_notes' => $strategyNotes,
                'preferred_model' => $analysis['modelUsed'] ?? $preferredModel,
                'ai_analysis' => json_encode($analysis, JSON_UNESCAPED_UNICODE),
                'last_analyzed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $row = DB::table('finance_monthly_plans')->where('user_sub', $sub)->where('month', $month)->first();

        return response()->json([
            'monthlyPlan' => $this->vault->mapMonthlyPlan($row),
            'contextSummary' => $context['commitmentsSummary'],
        ]);
    }
}
