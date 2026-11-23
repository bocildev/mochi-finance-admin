<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceVaultService
{
    public function __construct(private GeminiPlannerService $gemini) {}
    public function isFree(object $userRow): bool
    {
        return ($userRow->plan_tier ?? 'free') === 'free';
    }

    public function isTrialExpired(object $userRow): bool
    {
        if (! $this->isFree($userRow)) {
            return false;
        }

        $now = Carbon::now();
        $createdAt = ! empty($userRow->created_at) ? Carbon::parse($userRow->created_at) : $now;

        return $now->greaterThan($createdAt->copy()->addDays(14));
    }

    public function maxWalletsForUser(object $userRow): int
    {
        if (! $this->isFree($userRow)) {
            return -1;
        }

        $plan = DB::table('saas_plans')->where('key', 'free')->first();

        return $plan ? (int) $plan->max_wallets : 2;
    }

    public function ensureDefaultCategories(string $sub): void
    {
        if (DB::table('finance_categories')->where('user_sub', $sub)->exists()) {
            return;
        }

        $now = Carbon::now();
        $rows = [];
        foreach (NotificationParserService::DEFAULT_CATEGORIES as $cat) {
            $rows[] = [
                'user_sub' => $sub,
                'client_id' => $cat['id'],
                'name' => $cat['name'],
                'type' => $cat['type'],
                'icon' => $cat['icon'],
                'color' => $cat['color'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('finance_categories')->insert($rows);
    }

    public function refreshUserMetrics(string $sub): void
    {
        $now = Carbon::now();
        DB::table('finance_users')->where('sub', $sub)->update([
            'wallets_count' => DB::table('finance_wallets')->where('user_sub', $sub)->count(),
            'transactions_count' => DB::table('finance_transactions')->where('user_sub', $sub)->count(),
            'budgets_count' => DB::table('finance_budgets')->where('user_sub', $sub)->count(),
            'goals_count' => DB::table('finance_saving_goals')->where('user_sub', $sub)->count(),
            'last_sync_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function mapWallet(object $w): array
    {
        return [
            'id' => $w->client_id,
            'name' => $w->name,
            'type' => $w->type,
            'balance' => (float) $w->balance,
            'accountNumber' => $w->account_number,
            'icon' => $w->icon,
            'color' => $w->color,
            'createdAt' => (int) $w->created_ms,
            'updatedAt' => (int) $w->updated_ms,
        ];
    }

    public function mapCategory(object $c): array
    {
        return [
            'id' => $c->client_id,
            'name' => $c->name,
            'type' => $c->type,
            'icon' => $c->icon ?: 'Receipt',
            'color' => $c->color ?: '#f472b6',
        ];
    }

    public function mapTransaction(object $t): array
    {
        return [
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
        ];
    }

    public function mapBudget(object $b): array
    {
        return [
            'id' => $b->client_id,
            'categoryId' => $b->category_id,
            'monthlyLimit' => (float) $b->monthly_limit,
            'month' => $b->month,
        ];
    }

    public function mapSavingGoal(object $g): array
    {
        return [
            'id' => $g->client_id,
            'name' => $g->name,
            'targetAmount' => (float) $g->target_amount,
            'currentAmount' => (float) $g->current_amount,
            'targetDate' => $g->target_date,
            'icon' => $g->icon,
            'color' => $g->color,
            'notes' => $g->notes,
            'createdAt' => (int) $g->created_ms,
        ];
    }

    public function mapRecurringBill(object $r): array
    {
        return [
            'id' => $r->client_id,
            'name' => $r->name,
            'amount' => (float) $r->amount,
            'categoryId' => $r->category_id,
            'walletId' => $r->wallet_id,
            'dueDay' => (int) $r->due_day,
            'billingCycle' => $r->billing_cycle,
            'billType' => $r->bill_type ?? 'subscription',
            'totalTenor' => isset($r->total_tenor) ? (int) $r->total_tenor : null,
            'paidCount' => (int) ($r->paid_count ?? 0),
            'targetMonth' => $r->target_month ?? null,
            'creditorName' => $r->creditor_name ?? null,
            'paidAmount' => (float) ($r->paid_amount ?? 0),
            'isActive' => (bool) $r->is_active,
            'notes' => $r->notes,
            'lastPaidDate' => $r->last_paid_date,
            'createdAt' => (int) $r->created_ms,
        ];
    }

    public function mapMonthlyPlan(object $p): array
    {
        $analysis = null;
        if (! empty($p->ai_analysis)) {
            $decoded = json_decode($p->ai_analysis, true);
            $analysis = is_array($decoded) ? $decoded : null;
        }

        return [
            'month' => $p->month,
            'monthlySalary' => (float) $p->monthly_salary,
            'strategyNotes' => $p->strategy_notes,
            'preferredModel' => $p->preferred_model ?: $this->gemini->getDefaultModel((string) ($p->user_sub ?? '')),
            'aiAnalysis' => $analysis,
            'lastAnalyzedAt' => $p->last_analyzed_at ? Carbon::parse($p->last_analyzed_at)->toIso8601String() : null,
        ];
    }

    public function mapInboxItem(object $i): array
    {
        return [
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
        ];
    }

    public function listWallets(string $sub): array
    {
        return DB::table('finance_wallets')
            ->where('user_sub', $sub)
            ->orderBy('id')
            ->get()
            ->map(fn ($w) => $this->mapWallet($w))
            ->values()
            ->all();
    }

    public function getVaultSnapshot(string $sub, object $userRow): array
    {
        $this->ensureDefaultCategories($sub);

        $isFree = $this->isFree($userRow);
        $maxWallets = $this->maxWalletsForUser($userRow);

        $walletsQuery = DB::table('finance_wallets')->where('user_sub', $sub)->orderBy('id');
        $wallets = $walletsQuery->get()->map(fn ($w) => $this->mapWallet($w))->values();
        if ($isFree && $maxWallets > 0) {
            $wallets = $wallets->take($maxWallets)->values();
        }

        $categories = DB::table('finance_categories')
            ->where('user_sub', $sub)
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => $this->mapCategory($c))
            ->values();

        $transactions = DB::table('finance_transactions')
            ->where('user_sub', $sub)
            ->orderByDesc('tx_date')
            ->orderByDesc('created_ms')
            ->get()
            ->map(fn ($t) => $this->mapTransaction($t))
            ->values();

        $budgets = $isFree
            ? []
            : DB::table('finance_budgets')
                ->where('user_sub', $sub)
                ->orderBy('id')
                ->get()
                ->map(fn ($b) => $this->mapBudget($b))
                ->values()
                ->all();

        $savingGoals = $isFree
            ? []
            : DB::table('finance_saving_goals')
                ->where('user_sub', $sub)
                ->orderBy('id')
                ->get()
                ->map(fn ($g) => $this->mapSavingGoal($g))
                ->values()
                ->all();

        $recurringBills = $isFree
            ? []
            : DB::table('finance_recurring_bills')
                ->where('user_sub', $sub)
                ->orderBy('id')
                ->get()
                ->map(fn ($r) => $this->mapRecurringBill($r))
                ->values()
                ->all();

        $inbox = $isFree
            ? []
            : DB::table('finance_inbox_items')
                ->where('user_sub', $sub)
                ->orderByDesc('created_ms')
                ->get()
                ->map(fn ($i) => $this->mapInboxItem($i))
                ->values()
                ->all();

        $monthlyPlans = ($isFree || ! Schema::hasTable('finance_monthly_plans'))
            ? []
            : DB::table('finance_monthly_plans')
                ->where('user_sub', $sub)
                ->orderByDesc('month')
                ->get()
                ->map(fn ($p) => $this->mapMonthlyPlan($p))
                ->values()
                ->all();

        return [
            'sub' => $sub,
            'plan_tier' => $userRow->plan_tier ?? 'free',
            'isTrialExpired' => $this->isTrialExpired($userRow),
            'syncedAt' => Carbon::now()->toIso8601String(),
            'wallets' => $wallets->all(),
            'categories' => $categories->all(),
            'transactions' => $transactions->all(),
            'budgets' => $budgets,
            'savingGoals' => $savingGoals,
            'recurringBills' => $recurringBills,
            'inbox' => $inbox,
            'monthlyPlans' => $monthlyPlans,
            'aiSettings' => $this->gemini->getUserSettingsSummary($sub),
        ];
    }
}
