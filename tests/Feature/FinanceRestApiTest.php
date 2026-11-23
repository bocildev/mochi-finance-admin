<?php

namespace Tests\Feature;

use App\Services\IamSsoService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
}
