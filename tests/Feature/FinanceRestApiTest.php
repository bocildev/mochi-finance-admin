<?php

namespace Tests\Feature;

use App\Services\GeminiPlannerService;
use App\Services\IamSsoService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceRestApiTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKeyPem;

    protected function setUp(): void
    {
        parent::setUp();

        $cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        $opts = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] + (PHP_OS_FAMILY === 'Windows' && is_file($cnf) ? ['config' => $cnf] : []);

        $res = openssl_pkey_new($opts);
        openssl_pkey_export($res, $privPem, null, $opts);
        $details = openssl_pkey_get_details($res);

        $this->privateKeyPem = $privPem;
        $jwks = [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'rest-test-key',
                'alg' => 'RS256',
                'use' => 'sig',
                'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
                'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
            ]],
        ];

        $issuer = rtrim((string) config('iam.issuer'), '/');
        Cache::put('iam_oidc_jwks_'.md5($issuer), $jwks, 600);
        app(IamSsoService::class)->ensureDefaultPlansExist();
    }

    private function tokenFor(
        string $sub,
        array $roles = ['pro_user'],
        array $permissions = ['finance.basic', 'finance.sync', 'finance.smart_inbox', 'finance.analytics', 'finance.export', 'finance.unlimited']
    ): string {
        return JWT::encode([
            'iss' => rtrim((string) config('iam.issuer'), '/'),
            'sub' => $sub,
            'aud' => 'app_mochi_finance',
            'iat' => time(),
            'exp' => time() + 3600,
            'typ' => 'access',
            'roles' => $roles,
            'permissions' => $permissions,
        ], $this->privateKeyPem, 'RS256', 'rest-test-key');
    }

    public function test_owner_bootstrap_and_pull_never_seed_dummy_wallets_or_transactions(): void
    {
        DB::table('finance_users')->insert([
            'sub' => 'usr_owner_real',
            'name' => 'Moch. Adam',
            'email' => 'athallahrich@gmail.com',
            'roles' => json_encode(['admin']),
            'permissions' => json_encode(IamSsoService::ROLE_PERMISSIONS['admin']),
            'plan_tier' => 'admin',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->tokenFor('usr_owner_real', ['admin'], IamSsoService::ROLE_PERMISSIONS['admin']);

        $bootstrap = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bootstrap')
            ->assertOk();

        $this->assertSame([], $bootstrap->json('wallets'));
        $this->assertSame([], $bootstrap->json('transactions'));
        $this->assertCount(11, $bootstrap->json('categories'));

        $pull = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull')
            ->assertOk();

        $this->assertSame([], $pull->json('wallets'));
        $this->assertSame([], $pull->json('transactions'));
    }

    public function test_wallets_crud_owner_isolation_and_free_tier_limit(): void
    {
        $proToken = $this->tokenFor('usr_pro_a');
        $otherToken = $this->tokenFor('usr_pro_b');

        // Create wallet
        $createRes = $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->postJson('/api/v1/wallets', [
                'name' => 'BCA Utama',
                'type' => 'bank',
                'balance' => 1500000,
                'accountNumber' => '1234-5678',
                'color' => '#005baa',
            ])
            ->assertCreated();

        $walletId = $createRes->json('wallet.id');
        $this->assertNotEmpty($walletId);
        $this->assertSame(1500000, $createRes->json('wallet.balance'));

        // Cross-user isolation: User B cannot update or delete User A's wallet
        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->putJson("/api/v1/wallets/{$walletId}", ['name' => 'Hacked'])
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->deleteJson("/api/v1/wallets/{$walletId}")
            ->assertNotFound();

        // Owner updates wallet
        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->putJson("/api/v1/wallets/{$walletId}", [
                'name' => 'BCA Payroll',
                'balance' => 2000000,
            ])
            ->assertOk()
            ->assertJsonPath('wallet.name', 'BCA Payroll')
            ->assertJsonPath('wallet.balance', 2000000);

        // Owner deletes wallet permanently (survives reload/bootstrap)
        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->deleteJson("/api/v1/wallets/{$walletId}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonCount(0, 'wallets');

        // Free user wallet limit (max 2)
        $freeToken = $this->tokenFor('usr_free_limit', ['free_user'], ['finance.basic']);
        $this->withHeader('Authorization', 'Bearer '.$freeToken)
            ->postJson('/api/v1/wallets', ['name' => 'Dompet 1', 'type' => 'cash', 'balance' => 10000])
            ->assertCreated();
        $this->withHeader('Authorization', 'Bearer '.$freeToken)
            ->postJson('/api/v1/wallets', ['name' => 'Dompet 2', 'type' => 'bank', 'balance' => 20000])
            ->assertCreated();
        $this->withHeader('Authorization', 'Bearer '.$freeToken)
            ->postJson('/api/v1/wallets', ['name' => 'Dompet 3', 'type' => 'ewallet', 'balance' => 30000])
            ->assertStatus(403)
            ->assertJsonPath('error', 'wallet_limit_reached');
    }

    public function test_transactions_atomically_update_and_reverse_wallet_balances(): void
    {
        $token = $this->tokenFor('usr_tx_user');

        $w1 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/wallets', ['name' => 'Bank A', 'type' => 'bank', 'balance' => 1000000])
            ->json('wallet.id');
        $w2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/wallets', ['name' => 'GoPay', 'type' => 'ewallet', 'balance' => 200000])
            ->json('wallet.id');

        // Expense 150k from Bank A -> 850k
        $expRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/transactions', [
                'walletId' => $w1,
                'amount' => 150000,
                'type' => 'expense',
                'categoryId' => 'cat-food',
                'notes' => 'Makan siang',
            ])
            ->assertCreated();
        $expId = $expRes->json('transaction.id');
        $this->assertEquals(850000.0, (float) DB::table('finance_wallets')->where('client_id', $w1)->value('balance'));

        // Transfer 250k from Bank A to GoPay -> Bank A 600k, GoPay 450k
        $trfRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/transactions', [
                'walletId' => $w1,
                'targetWalletId' => $w2,
                'amount' => 250000,
                'type' => 'transfer',
                'categoryId' => 'cat-transfer',
                'notes' => 'Topup GoPay',
            ])
            ->assertCreated();
        $trfId = $trfRes->json('transaction.id');
        $this->assertEquals(600000.0, (float) DB::table('finance_wallets')->where('client_id', $w1)->value('balance'));
        $this->assertEquals(450000.0, (float) DB::table('finance_wallets')->where('client_id', $w2)->value('balance'));

        // Delete transfer -> reverses both wallets (Bank A 850k, GoPay 200k)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/transactions/{$trfId}")
            ->assertOk();
        $this->assertEquals(850000.0, (float) DB::table('finance_wallets')->where('client_id', $w1)->value('balance'));
        $this->assertEquals(200000.0, (float) DB::table('finance_wallets')->where('client_id', $w2)->value('balance'));

        // Delete expense -> Bank A back to 1,000,000
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/transactions/{$expId}")
            ->assertOk();
        $this->assertEquals(1000000.0, (float) DB::table('finance_wallets')->where('client_id', $w1)->value('balance'));
    }

    public function test_pro_modules_budgets_goals_recurring_and_inbox_full_lifecycle(): void
    {
        $proToken = $this->tokenFor('usr_pro_full');
        $freeToken = $this->tokenFor('usr_free_blocked', ['free_user'], ['finance.basic']);

        // Free user blocked from Pro modules
        $this->withHeader('Authorization', 'Bearer '.$freeToken)
            ->postJson('/api/v1/budgets', ['categoryId' => 'cat-food', 'monthlyLimit' => 500000])
            ->assertStatus(403)
            ->assertJsonPath('error', 'pro_required');

        $walletId = $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->postJson('/api/v1/wallets', ['name' => 'BCA', 'type' => 'bank', 'balance' => 2000000])
            ->json('wallet.id');

        // 1. Budgets CRUD
        $budgetId = $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->postJson('/api/v1/budgets', ['categoryId' => 'cat-food', 'monthlyLimit' => 1000000, 'month' => '2026-11'])
            ->assertCreated()
            ->json('budget.id');

        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->putJson("/api/v1/budgets/{$budgetId}", ['monthlyLimit' => 1500000])
            ->assertOk()
            ->assertJsonPath('budget.monthlyLimit', 1500000);

        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->deleteJson("/api/v1/budgets/{$budgetId}")
            ->assertOk();

        // 2. Saving Goals CRUD + Deposit + Withdraw
        $goalId = $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->postJson('/api/v1/saving-goals', ['name' => 'Dana Darurat', 'targetAmount' => 5000000])
            ->assertCreated()
            ->json('savingGoal.id');

        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->postJson("/api/v1/saving-goals/{$goalId}/deposit", ['walletId' => $walletId, 'amount' => 400000])
            ->assertOk()
            ->assertJsonPath('savingGoal.currentAmount', 400000);
        $this->assertEquals(1600000.0, (float) DB::table('finance_wallets')->where('client_id', $walletId)->value('balance'));

        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->postJson("/api/v1/saving-goals/{$goalId}/withdraw", ['walletId' => $walletId, 'amount' => 100000])
            ->assertOk()
            ->assertJsonPath('savingGoal.currentAmount', 300000);
        $this->assertEquals(1700000.0, (float) DB::table('finance_wallets')->where('client_id', $walletId)->value('balance'));

        // 3. Recurring Bills CRUD + Toggle + Pay
        $billId = $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->postJson('/api/v1/recurring-bills', [
                'name' => 'Netflix',
                'amount' => 186000,
                'categoryId' => 'cat-entertainment',
                'walletId' => $walletId,
                'dueDay' => 15,
            ])
            ->assertCreated()
            ->json('recurringBill.id');

        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->patchJson("/api/v1/recurring-bills/{$billId}/toggle")
            ->assertOk()
            ->assertJsonPath('recurringBill.isActive', false);

        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->postJson("/api/v1/recurring-bills/{$billId}/pay")
            ->assertOk()
            ->assertJsonPath('transaction.amount', 186000);
        $this->assertEquals(1514000.0, (float) DB::table('finance_wallets')->where('client_id', $walletId)->value('balance'));

        // 4. Smart Inbox ingest -> approve -> reject -> delete
        $inboxRes = $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->postJson('/api/v1/inbox', [
                'text' => 'm-Transfer: BERHASIL m-BCA 19/10 20:15 Rp 42.000 ke KOPI SOE Ref 991823',
            ])
            ->assertCreated()
            ->assertJsonPath('item.source', 'BCA')
            ->assertJsonPath('item.detectedAmount', 42000);
        $inboxId = $inboxRes->json('item.id');

        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->postJson("/api/v1/inbox/{$inboxId}/approve", [
                'walletId' => $walletId,
                'categoryId' => 'cat-food',
                'notes' => 'Kopi Soe',
            ])
            ->assertOk()
            ->assertJsonPath('item.status', 'APPROVED')
            ->assertJsonPath('transaction.amount', 42000);
        $this->assertEquals(1472000.0, (float) DB::table('finance_wallets')->where('client_id', $walletId)->value('balance'));

        $this->withHeader('Authorization', 'Bearer '.$proToken)
            ->deleteJson("/api/v1/inbox/{$inboxId}")
            ->assertOk();
        $this->assertDatabaseMissing('finance_inbox_items', ['client_id' => $inboxId]);
    }

    public function test_legacy_sync_push_prunes_deleted_rows_and_cors_options_allows_rest_methods(): void
    {
        $token = $this->tokenFor('usr_prune_test');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'wallets' => [
                    ['id' => 'w-keep', 'name' => 'Keep', 'type' => 'bank', 'balance' => 1000],
                    ['id' => 'w-remove', 'name' => 'Remove', 'type' => 'cash', 'balance' => 500],
                ],
            ])
            ->assertOk();

        // Push again without w-remove -> must be pruned from DB (no zombie data)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'wallets' => [
                    ['id' => 'w-keep', 'name' => 'Keep', 'type' => 'bank', 'balance' => 1000],
                ],
            ])
            ->assertOk();

        $pull = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull')
            ->assertOk();
        $this->assertCount(1, $pull->json('wallets'));
        $this->assertSame('w-keep', $pull->json('wallets.0.id'));

        // CORS preflight allows PUT, PATCH, DELETE
        $opt = $this->call('OPTIONS', '/api/v1/wallets/w-keep', [], [], [], ['HTTP_ORIGIN' => 'http://localhost:3001']);
        $opt->assertStatus(204);
        $this->assertStringContainsString('DELETE', (string) $opt->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('PUT', (string) $opt->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('PATCH', (string) $opt->headers->get('Access-Control-Allow-Methods'));
    }

    public function test_installment_vs_subscription_bills_remaining_tenor_and_deletion(): void
    {
        $token = $this->tokenFor('usr_cicilan_user');

        $walletId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/wallets', ['name' => 'BCA', 'type' => 'bank', 'balance' => 3000000])
            ->json('wallet.id');

        // 1. Subscription bill defaults
        $subBill = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/recurring-bills', [
                'name' => 'Spotify Family',
                'amount' => 86000,
                'categoryId' => 'cat-entertainment',
                'walletId' => $walletId,
                'dueDay' => 5,
                'billType' => 'subscription',
            ])
            ->assertCreated()
            ->assertJsonPath('recurringBill.billType', 'subscription')
            ->assertJsonPath('recurringBill.totalTenor', null)
            ->assertJsonPath('recurringBill.paidCount', 0);
        $subId = $subBill->json('recurringBill.id');

        // 2. Installment rejects paidCount > totalTenor or missing totalTenor
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/recurring-bills', [
                'name' => 'Cicilan Error',
                'amount' => 500000,
                'categoryId' => 'cat-bills',
                'walletId' => $walletId,
                'dueDay' => 10,
                'billType' => 'installment',
            ])
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/recurring-bills', [
                'name' => 'Cicilan Overpaid',
                'amount' => 500000,
                'categoryId' => 'cat-bills',
                'walletId' => $walletId,
                'dueDay' => 10,
                'billType' => 'installment',
                'totalTenor' => 3,
                'paidCount' => 4,
            ])
            ->assertStatus(422);

        // 3. Create valid installment (3 tenor, 1 already paid -> 2 remaining)
        $instRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/recurring-bills', [
                'name' => 'Cicilan Laptop',
                'amount' => 500000,
                'categoryId' => 'cat-bills',
                'walletId' => $walletId,
                'dueDay' => 10,
                'billType' => 'installment',
                'totalTenor' => 3,
                'paidCount' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('recurringBill.billType', 'installment')
            ->assertJsonPath('recurringBill.totalTenor', 3)
            ->assertJsonPath('recurringBill.paidCount', 1)
            ->assertJsonPath('recurringBill.isActive', true);
        $instId = $instRes->json('recurringBill.id');

        // Pay installment 2/3
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/recurring-bills/{$instId}/pay")
            ->assertOk()
            ->assertJsonPath('recurringBill.paidCount', 2)
            ->assertJsonPath('recurringBill.isActive', true)
            ->assertJsonPath('transaction.notes', 'Bayar Cicilan (2/3): Cicilan Laptop');

        // Pay final installment 3/3 -> auto marked lunas (isActive = false)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/recurring-bills/{$instId}/pay")
            ->assertOk()
            ->assertJsonPath('recurringBill.paidCount', 3)
            ->assertJsonPath('recurringBill.isActive', false)
            ->assertJsonPath('transaction.notes', 'Bayar Cicilan (3/3): Cicilan Laptop');

        $this->assertEquals(2000000.0, (float) DB::table('finance_wallets')->where('client_id', $walletId)->value('balance'));

        // Cannot pay again after lunas
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/recurring-bills/{$instId}/pay")
            ->assertStatus(422)
            ->assertJsonPath('error', 'already_paid_off');

        // Cannot toggle back to active while already lunas
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/recurring-bills/{$instId}/toggle")
            ->assertStatus(422)
            ->assertJsonPath('error', 'already_paid_off');

        // Edit tenor from 3 -> 4 re-activates installment (1 remaining)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/recurring-bills/{$instId}", ['totalTenor' => 4])
            ->assertOk()
            ->assertJsonPath('recurringBill.totalTenor', 4)
            ->assertJsonPath('recurringBill.paidCount', 3)
            ->assertJsonPath('recurringBill.isActive', true);

        // Delete both subscription and installment permanently
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/recurring-bills/{$subId}")
            ->assertOk()
            ->assertJsonPath('deleted', true);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/recurring-bills/{$instId}")
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('finance_recurring_bills', ['client_id' => $subId]);
        $this->assertDatabaseMissing('finance_recurring_bills', ['client_id' => $instId]);
    }

    public function test_month_specific_debt_partial_and_full_payment_lifecycle(): void
    {
        $token = $this->tokenFor('usr_debt_user');

        $walletId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/wallets', ['name' => 'BCA', 'type' => 'bank', 'balance' => 1500000])
            ->json('wallet.id');

        // Missing targetMonth must fail 422
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/recurring-bills', [
                'name' => 'Hutang Teman',
                'amount' => 500000,
                'categoryId' => 'cat-bills',
                'walletId' => $walletId,
                'dueDay' => 25,
                'billType' => 'debt',
            ])
            ->assertStatus(422);

        // Create debt for 2026-12
        $debtRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/recurring-bills', [
                'name' => 'Pinjaman Darurat',
                'amount' => 500000,
                'categoryId' => 'cat-bills',
                'walletId' => $walletId,
                'dueDay' => 25,
                'billType' => 'debt',
                'targetMonth' => '2026-12',
                'creditorName' => 'Budi',
            ])
            ->assertCreated()
            ->assertJsonPath('recurringBill.billType', 'debt')
            ->assertJsonPath('recurringBill.targetMonth', '2026-12')
            ->assertJsonPath('recurringBill.creditorName', 'Budi')
            ->assertJsonPath('recurringBill.paidAmount', 0)
            ->assertJsonPath('recurringBill.isActive', true);
        $debtId = $debtRes->json('recurringBill.id');

        // Partial pay 200k -> remaining 300k, still active
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/recurring-bills/{$debtId}/pay", ['payAmount' => 200000])
            ->assertOk()
            ->assertJsonPath('recurringBill.paidAmount', 200000)
            ->assertJsonPath('recurringBill.isActive', true)
            ->assertJsonPath('transaction.amount', 200000);
        $this->assertEquals(1300000.0, (float) DB::table('finance_wallets')->where('client_id', $walletId)->value('balance'));

        // Pay remaining (default full remaining = 300k) -> auto lunas (isActive = false)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/recurring-bills/{$debtId}/pay")
            ->assertOk()
            ->assertJsonPath('recurringBill.paidAmount', 500000)
            ->assertJsonPath('recurringBill.isActive', false)
            ->assertJsonPath('transaction.amount', 300000);
        $this->assertEquals(1000000.0, (float) DB::table('finance_wallets')->where('client_id', $walletId)->value('balance'));

        // Cannot pay again after debt lunas
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/recurring-bills/{$debtId}/pay")
            ->assertStatus(422)
            ->assertJsonPath('error', 'already_paid_off');

        // Delete debt permanently
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/recurring-bills/{$debtId}")
            ->assertOk()
            ->assertJsonPath('deleted', true);
    }

    public function test_salary_planner_gemini_analysis_fallback_and_flexible_batch_budgets(): void
    {
        $token = $this->tokenFor('usr_planner_pro');
        $otherToken = $this->tokenFor('usr_other_byok');

        // 0. Ensure user without personal key fails gracefully and is isolated
        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson('/api/v1/ai-settings')
            ->assertOk()
            ->assertJsonPath('aiSettings.configured', false);

        // 1. User configures their own encrypted Google Gemini API key & default model
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/ai-settings', [
                'googleApiKey' => 'fake_test_key_for_encryption_check',
                'defaultModel' => 'gemini-3.5-flash-lite',
            ])
            ->assertOk()
            ->assertJsonPath('aiSettings.configured', true)
            ->assertJsonPath('aiSettings.defaultModel', 'gemini-3.5-flash-lite');

        $rawDbVal = (string) DB::table('finance_user_ai_settings')->where('user_sub', 'usr_planner_pro')->value('google_api_key_encrypted');
        $this->assertNotSame('fake_test_key_for_encryption_check', $rawDbVal);
        $this->assertSame('fake_test_key_for_encryption_check', app(GeminiPlannerService::class)->getApiKey('usr_planner_pro'));

        // Another user must NOT see or inherit usr_planner_pro's key
        $this->assertSame('', app(GeminiPlannerService::class)->getApiKey('usr_other_byok'));
        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->postJson('/api/v1/monthly-plans/2026-12/analyze', ['monthlySalary' => 5000000])
            ->assertStatus(422);

        // Test connection endpoint works with user's key
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
            ], 200),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ai-settings/test', ['model' => 'gemini-3.5-flash-lite'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Reset Http fake before fallback test
        Http::swap(new \Illuminate\Http\Client\Factory());

        // 2. Setup wallet + debt in 2026-12 for planner context
        $walletId = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/wallets', ['name' => 'Mandiri', 'type' => 'bank', 'balance' => 4000000])
            ->json('wallet.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/recurring-bills', [
                'name' => 'Hutang Akhir Tahun',
                'amount' => 1000000,
                'categoryId' => 'cat-bills',
                'walletId' => $walletId,
                'dueDay' => 20,
                'billType' => 'debt',
                'targetMonth' => '2026-12',
            ])
            ->assertCreated();

        // 3. Save monthly salary for 2026-12
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/monthly-plans/2026-12', [
                'monthlySalary' => 8000000,
                'strategyNotes' => 'Prioritaskan lunasi hutang Desember dan tetap sisihkan tabungan.',
                'preferredModel' => 'gemini-3.5-flash-lite',
            ])
            ->assertOk()
            ->assertJsonPath('monthlyPlan.monthlySalary', 8000000)
            ->assertJsonPath('monthlyPlan.preferredModel', 'gemini-3.5-flash-lite');

        // 4. Mock Gemini API: 3.5-flash-lite returns 429 rate-limit -> auto-fallback to 3.1-flash-lite 200 JSON!
        $aiPayload = json_encode([
            'summary' => 'Dari gaji Rp 8.000.000 dikurangi hutang Rp 1.000.000, sisa dana bebas Rp 7.000.000.',
            'healthStatus' => 'sehat',
            'allocationRatio' => ['needsPct' => 50, 'wantsPct' => 20, 'savingsAndDebtPct' => 30],
            'actionPlan' => [
                'Sisihkan Rp 1.000.000 di awal bulan untuk melunasi hutang Desember.',
                'Batasi makan & minum di Rp 1.800.000.',
            ],
            'recommendedBudgets' => [
                ['categoryId' => 'cat-food', 'categoryName' => 'Makanan & Minuman', 'recommendedLimit' => 1800000, 'reason' => 'Kebutuhan pokok harian'],
                ['categoryId' => 'cat-transport', 'categoryName' => 'Transportasi', 'recommendedLimit' => 600000, 'reason' => 'Komuter harian'],
                ['categoryId' => 'cat-invalid-id', 'categoryName' => 'Invalid', 'recommendedLimit' => 999999, 'reason' => 'Harus tersaring'],
            ],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent' => Http::response([
                'error' => ['message' => 'Rate limit exceeded'],
            ], 429),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['thought' => true, 'text' => 'internal thought'], ['text' => $aiPayload]],
                    ],
                ]],
            ], 200),
        ]);

        $analyzeRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/monthly-plans/2026-12/analyze', [
                'monthlySalary' => 8000000,
                'preferredModel' => 'gemini-3.5-flash-lite',
            ])
            ->assertOk()
            ->assertJsonPath('monthlyPlan.aiAnalysis.healthStatus', 'sehat')
            ->assertJsonPath('monthlyPlan.aiAnalysis.modelUsed', 'gemini-3.1-flash-lite')
            ->assertJsonPath('monthlyPlan.aiAnalysis.fallbackUsed', true)
            ->assertJsonPath('contextSummary.debtsForSelectedMonth', 1000000)
            ->assertJsonCount(2, 'monthlyPlan.aiAnalysis.recommendedBudgets');

        $this->assertSame(1800000, $analyzeRes->json('monthlyPlan.aiAnalysis.recommendedBudgets.0.recommendedLimit'));

        // 5. Apply flexible user-adjusted budgets for 2026-12 via batch endpoint
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/budgets/batch', [
                'month' => '2026-12',
                'budgets' => [
                    ['categoryId' => 'cat-food', 'monthlyLimit' => 2000000],
                    ['categoryId' => 'cat-transport', 'monthlyLimit' => 550000],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'budgets');

        // Reload bootstrap confirms persisted monthlyPlan & month-scoped budgets, and NEVER leaks API key
        $boot = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/bootstrap')
            ->assertOk()
            ->assertJsonPath('aiSettings.configured', true)
            ->assertJsonPath('monthlyPlans.0.month', '2026-12')
            ->assertJsonPath('monthlyPlans.0.monthlySalary', 8000000);

        $this->assertStringNotContainsString('fake_test_key_for_encryption_check', $boot->getContent());

        // 6. User can clear their own API key
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/ai-settings', [
                'clearApiKey' => true,
                'defaultModel' => 'gemini-3.5-flash-lite',
            ])
            ->assertOk()
            ->assertJsonPath('aiSettings.configured', false)
            ->assertJsonPath('aiSettings.maskedKey', null);
    }

    public function test_gemini_alias_fallback_and_per_model_error_summary(): void
    {
        $token = $this->tokenFor('usr_fallback_test');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/ai-settings', [
                'googleApiKey' => 'user_specific_gemini_key',
                'defaultModel' => 'gemini-3.5-flash-lite',
            ])
            ->assertOk();

        $aiPayload = json_encode([
            'summary' => 'Analisa berhasil lewat alias cadangan resmi Google.',
            'healthStatus' => 'waspada',
            'allocationRatio' => ['needsPct' => 60, 'wantsPct' => 15, 'savingsAndDebtPct' => 25],
            'actionPlan' => ['Prioritaskan dana darurat.'],
            'recommendedBudgets' => [
                ['categoryId' => 'cat-food', 'categoryName' => 'Makanan & Minuman', 'recommendedLimit' => 1200000, 'reason' => 'Hemat'],
            ],
        ]);

        // All 4 primary models return 503 high demand -> gemini-flash-lite-latest alias saves the request!
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent' => Http::response(['error' => ['message' => 'This model is currently experiencing high demand.']], 503),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent' => Http::response(['error' => ['message' => 'This model is currently experiencing high demand.']], 503),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::response(['error' => ['message' => 'This model is currently experiencing high demand.']], 503),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent' => Http::response(['error' => ['message' => 'This model is currently experiencing high demand.']], 503),
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-lite-latest:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $aiPayload]]]]],
            ], 200),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/monthly-plans/2026-11/analyze', [
                'monthlySalary' => 6472000,
                'preferredModel' => 'gemini-3.5-flash-lite',
            ])
            ->assertOk()
            ->assertJsonPath('monthlyPlan.aiAnalysis.modelUsed', 'gemini-flash-lite-latest')
            ->assertJsonPath('monthlyPlan.aiAnalysis.fallbackUsed', true);

        // If every single model + alias returns 503, error message includes per-model details (never masks earlier failures)
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/*' => Http::response([
                'error' => ['message' => 'This model is currently experiencing high demand.'],
            ], 503),
        ]);

        $failRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/monthly-plans/2026-11/analyze', [
                'monthlySalary' => 6472000,
                'preferredModel' => 'gemini-3.8-flash',
            ])
            ->assertStatus(422);

        $msg = (string) $failRes->json('message');
        $this->assertStringContainsString('gemini-3.8-flash', $msg);
        $this->assertStringContainsString('gemini-3.5-flash-lite', $msg);
        $this->assertStringContainsString('gemini-flash-lite-latest', $msg);
    }
}
