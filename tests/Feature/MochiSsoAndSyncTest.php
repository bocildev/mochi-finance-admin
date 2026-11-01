<?php

namespace Tests\Feature;

use App\Services\IamSsoService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MochiSsoAndSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $privateKeyPem;

    private array $jwks;

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
        $this->jwks = [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => 'test-key-1',
                'alg' => 'RS256',
                'use' => 'sig',
                'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
                'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
            ]],
        ];

        $issuer = rtrim((string) config('iam.issuer'), '/');
        Cache::put('iam_oidc_jwks_'.md5($issuer), $this->jwks, 600);
    }

    private function issueAccessToken(
        string $sub = 'usr_mochi_01',
        array $roles = ['pro_user'],
        array $permissions = ['finance.basic', 'finance.sync', 'finance.smart_inbox', 'finance.analytics', 'finance.export', 'finance.unlimited']
    ): string {
        $payload = [
            'iss' => rtrim((string) config('iam.issuer'), '/'),
            'sub' => $sub,
            'aud' => (string) config('iam.client_id'),
            'iat' => time(),
            'exp' => time() + 3600,
            'typ' => 'access',
            'roles' => $roles,
            'permissions' => $permissions,
        ];

        return JWT::encode($payload, $this->privateKeyPem, 'RS256', 'test-key-1');
    }

    public function test_guest_is_redirected_to_login_and_sso_authorize_uses_pkce(): void
    {
        $this->get('/')->assertRedirect('/login');

        $res = $this->get('/auth/login');
        $res->assertRedirect();
        $location = $res->headers->get('Location');
        $this->assertStringContainsString('/oauth/authorize', $location);
        $this->assertStringContainsString('client_id=app_mochi_finance', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
    }

    public function test_free_user_cannot_access_admin_console_or_cloud_sync_but_can_request_upgrade(): void
    {
        DB::table('finance_users')->insert([
            'sub' => 'usr_free_01',
            'name' => 'Budi Free',
            'email' => 'budi@example.com',
            'roles' => json_encode(['free_user']),
            'permissions' => json_encode(['finance.basic']),
            'plan_tier' => 'free',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $freeSession = [
            'iam_user' => [
                'sub' => 'usr_free_01',
                'name' => 'Budi Free',
                'email' => 'budi@example.com',
                'roles' => ['free_user'],
                'permissions' => ['finance.basic'],
                'plan_tier' => 'free',
            ],
        ];

        // 1. Free user dilarang masuk halaman SaaS Owner Backoffice
        $this->withSession($freeSession)->get('/')->assertStatus(403);
        $this->withSession($freeSession)->get('/clients')->assertStatus(403);

        // 2. Free user diizinkan Cloud Sync dompet (maksimal 2 dompet untuk masa trial)
        $freeToken = $this->issueAccessToken('usr_free_01', ['free_user'], ['finance.basic']);
        $this->withHeader('Authorization', 'Bearer '.$freeToken)
            ->postJson('/api/v1/sync/push', [
                'wallets' => [
                    ['id' => 'w-1', 'name' => 'Dompet 1', 'balance' => 100000],
                    ['id' => 'w-2', 'name' => 'Dompet 2', 'balance' => 200000],
                    ['id' => 'w-3', 'name' => 'Dompet 3', 'balance' => 300000], // Kelebihan kuota (maks 2)
                ],
            ])
            ->assertOk();

        // Hanya 2 dompet pertama yang tersimpan untuk Free
        $this->assertEquals(2, \Illuminate\Support\Facades\DB::table('finance_wallets')->where('user_sub', 'usr_free_01')->count());

        // 3. Free user bisa mengajukan Upgrade ke Mochi Pro via API
        $upRes = $this->withHeader('Authorization', 'Bearer '.$freeToken)
            ->postJson('/api/v1/upgrade-request', [
                'name' => 'Budi Free',
                'email' => 'budi@example.com',
                'requested_plan' => 'pro_monthly',
                'payment_method' => 'QRIS',
                'notes' => 'Transfer via GoPay QRIS',
            ]);

        $upRes->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('upgrade_requests', [
            'user_sub' => 'usr_free_01',
            'requested_plan' => 'pro_monthly',
            'status' => 'PENDING',
        ]);
    }

    public function test_pro_user_can_push_and_pull_cloud_sync_with_rs256_jwt(): void
    {
        $token = $this->issueAccessToken('usr_mochi_pro');

        $pushRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'wallets' => [
                    ['id' => 'wallet-mandiri', 'name' => 'Livin Mandiri', 'type' => 'bank', 'balance' => 12500000, 'createdAt' => 1700000000000, 'updatedAt' => 1700000000000],
                ],
                'transactions' => [
                    ['id' => 'tx-100', 'walletId' => 'wallet-mandiri', 'amount' => 75000, 'type' => 'expense', 'categoryId' => 'cat-food', 'notes' => 'Matcha Mochi Latte', 'date' => '2026-10-25T10:00:00Z', 'createdAt' => 1700000005000],
                ],
            ]);

        $pushRes->assertOk()->assertJsonPath('ok', true)->assertJsonPath('plan_tier', 'pro');

        $pullRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull');

        $pullRes->assertOk()->assertJsonPath('sub', 'usr_mochi_pro')->assertJsonPath('plan_tier', 'pro');
        $walletIds = collect($pullRes->json('wallets'))->pluck('id')->all();
        $this->assertContains('wallet-mandiri', $walletIds);
    }

    public function test_platform_owner_admin_can_manage_clients_approve_upgrades_and_edit_plans(): void
    {
        app(IamSsoService::class)->ensureDefaultPlansExist();

        DB::table('finance_users')->insert([
            [
                'sub' => 'usr_owner_adam',
                'name' => 'Moch. Adam Athallahrich',
                'email' => 'athallahrich@gmail.com',
                'roles' => json_encode(['admin']),
                'permissions' => json_encode(['finance.admin', 'finance.basic', 'finance.sync', 'finance.smart_inbox', 'finance.analytics', 'finance.export', 'finance.unlimited']),
                'plan_tier' => 'admin',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'sub' => 'usr_free_client',
                'name' => 'Siti Client',
                'email' => 'siti@client.id',
                'roles' => json_encode(['free_user']),
                'permissions' => json_encode(['finance.basic']),
                'plan_tier' => 'free',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $reqId = DB::table('upgrade_requests')->insertGetId([
            'user_sub' => 'usr_free_client',
            'user_name' => 'Siti Client',
            'user_email' => 'siti@client.id',
            'requested_plan' => 'pro_yearly',
            'amount' => 249000,
            'payment_method' => 'BCA VA',
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminSession = [
            'iam_user' => [
                'sub' => 'usr_owner_adam',
                'name' => 'Moch. Adam Athallahrich',
                'email' => 'athallahrich@gmail.com',
                'roles' => ['admin'],
                'permissions' => ['finance.admin', 'finance.basic', 'finance.sync', 'finance.smart_inbox', 'finance.analytics', 'finance.export', 'finance.unlimited'],
                'plan_tier' => 'admin',
            ],
        ];

        $this->withSession($adminSession)->get('/')->assertOk()->assertSee('Owner Backoffice Console');
        $this->withSession($adminSession)->get('/clients')->assertOk()->assertSee('Siti Client');
        $this->withSession($adminSession)->get('/upgrades')->assertOk()->assertSee('pro yearly');
        $this->withSession($adminSession)->get('/plans')->assertOk()->assertSee('Mochi Free Starter');

        // Approve upgrade request -> otomatis menaikkan Siti Client ke tier Pro (yearly)
        $this->withSession($adminSession)
            ->post('/upgrades/'.$reqId.'/review', ['action' => 'APPROVE'])
            ->assertRedirect();

        $this->assertDatabaseHas('upgrade_requests', [
            'id' => $reqId,
            'status' => 'APPROVED',
        ]);

        $this->assertDatabaseHas('finance_users', [
            'sub' => 'usr_free_client',
            'plan_tier' => 'pro',
            'billing_cycle' => 'yearly',
        ]);
    }

    public function test_iam_webhook_verifies_hmac_and_disables_user(): void
    {
        config(['iam.webhook_secret' => 'whsec_test_secret_123']);

        DB::table('finance_users')->insert([
            'sub' => 'usr_disable_me',
            'name' => 'Member User',
            'email' => 'member@iam-sso.local',
            'roles' => json_encode(['free_user']),
            'permissions' => json_encode(['finance.basic']),
            'plan_tier' => 'free',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $body = json_encode([
            'id' => 'evt_001',
            'type' => 'user.disabled',
            'data' => ['sub' => 'usr_disable_me'],
        ]);
        $ts = (string) time();
        $sig = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, 'whsec_test_secret_123');

        $res = $this->call(
            'POST',
            '/api/webhooks/iam',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_IAM_TIMESTAMP' => $ts,
                'HTTP_X_IAM_SIGNATURE' => $sig,
            ],
            $body
        );

        $res->assertOk()->assertJsonPath('received', true);
        $this->assertDatabaseHas('finance_users', [
            'sub' => 'usr_disable_me',
            'status' => 'disabled',
        ]);
    }
}
