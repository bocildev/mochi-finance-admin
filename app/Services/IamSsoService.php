<?php

namespace App\Services;

use Carbon\Carbon;
use Iam\Client\Claims;
use Iam\Client\TokenVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IamSsoService
{
    public const ROLE_PERMISSIONS = [
        'admin' => [
            'finance.admin',
            'finance.basic',
            'finance.sync',
            'finance.smart_inbox',
            'finance.analytics',
            'finance.export',
            'finance.unlimited',
        ],
        'pro_user' => [
            'finance.basic',
            'finance.sync',
            'finance.smart_inbox',
            'finance.analytics',
            'finance.export',
            'finance.unlimited',
        ],
        'free_user' => [
            'finance.basic',
        ],
    ];

    public function issuer(): string
    {
        return rtrim((string) config('iam.issuer'), '/');
    }

    public function clientId(): string
    {
        return (string) config('iam.client_id');
    }

    public function getJwks(bool $forceRefresh = false): array
    {
        $cacheKey = 'iam_oidc_jwks_'.md5($this->issuer());
        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 600, function () {
            $res = Http::timeout(8)->acceptJson()->get($this->issuer().'/oauth/jwks');
            if (! $res->successful()) {
                throw new RuntimeException('Gagal mengambil JWKS dari IAM-SSO-CORE ('.$res->status().')');
            }

            return $res->json();
        });
    }

    public function isSessionActiveInIam(?string $accessToken): bool
    {
        if (! $accessToken) {
            return false;
        }

        if (app()->runningUnitTests()) {
            return true;
        }

        try {
            $res = Http::timeout(4)
                ->withToken($accessToken)
                ->acceptJson()
                ->get($this->issuer().'/oauth/userinfo');

            return $res->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function verifyAccessToken(string $jwt): Claims
    {
        try {
            $verifier = new TokenVerifier($this->issuer(), $this->clientId(), $this->getJwks(false));

            return $verifier->verifyAccessToken($jwt);
        } catch (\Throwable) {
            $verifier = new TokenVerifier($this->issuer(), $this->clientId(), $this->getJwks(true));

            return $verifier->verifyAccessToken($jwt);
        }
    }

    public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $redirectUri): array
    {
        $res = Http::timeout(10)->acceptJson()->post($this->issuer().'/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId(),
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);

        if (! $res->successful()) {
            $err = $res->json();
            throw new RuntimeException($err['error_description'] ?? $err['error'] ?? 'Gagal menukar authorization code.');
        }

        $tokenData = $res->json();
        $claims = $this->verifyAccessToken($tokenData['access_token']);

        $userInfo = [];
        try {
            $infoRes = Http::timeout(8)
                ->withToken($tokenData['access_token'])
                ->acceptJson()
                ->get($this->issuer().'/oauth/userinfo');
            if ($infoRes->successful()) {
                $userInfo = $infoRes->json();
            }
        } catch (\Throwable) {
            // Fallback ke klaim JWT
        }

        $userRow = $this->syncFinanceUser(
            $claims,
            (string) ($userInfo['name'] ?? 'Mochi SSO User'),
            (string) ($userInfo['email'] ?? '')
        );

        return [
            'tokens' => $tokenData,
            'claims' => $claims,
            'user' => $userRow,
        ];
    }

    public function directLogin(string $email, string $password): array
    {
        $res = Http::timeout(10)->acceptJson()->post($this->issuer().'/oauth/direct-login', [
            'client_id' => $this->clientId(),
            'email' => $email,
            'password' => $password,
        ]);

        if (! $res->successful()) {
            $err = $res->json();
            throw new RuntimeException($err['error_description'] ?? $err['error'] ?? 'Email atau password tidak sesuai.');
        }

        $tokenData = $res->json();
        $claims = $this->verifyAccessToken($tokenData['access_token']);
        $userInfo = $tokenData['user'] ?? [];

        $userRow = $this->syncFinanceUser(
            $claims,
            (string) ($userInfo['name'] ?? 'Mochi User'),
            (string) ($userInfo['email'] ?? $email)
        );

        return [
            'tokens' => $tokenData,
            'claims' => $claims,
            'user' => $userRow,
        ];
    }

    public function syncFinanceUser(Claims $claims, ?string $name = null, ?string $email = null): object
    {
        $this->ensureDefaultPlansExist();

        $existing = DB::table('finance_users')->where('sub', $claims->sub)->first();
        $now = Carbon::now();

        $resolvedEmail = strtolower($email ?: ($existing->email ?? 'user@iam-sso.local'));
        $roles = $claims->roles;
        $permissions = $claims->permissions;

        // Jika email pemilik platform login (athallahrich@gmail.com / admin@iam-sso.local), pastikan tier admin
        $isOwnerEmail = in_array($resolvedEmail, ['athallahrich@gmail.com', 'admin@iam-sso.local'], true);
        if ($isOwnerEmail && ! in_array('admin', $roles, true)) {
            $roles = ['admin'];
            $permissions = self::ROLE_PERMISSIONS['admin'];
            $this->syncRoleToIamDatabase($claims->sub, 'admin');
        }

        // Jika di DB lokal tier sudah di-upgrade oleh Admin ke pro/free, utamakan role dari DB lokal bila token lama belum di-refresh
        if ($existing && ! $isOwnerEmail) {
            if ($existing->plan_tier === 'pro' && ! in_array('pro_user', $roles, true) && ! in_array('admin', $roles, true)) {
                $roles = ['pro_user'];
                $permissions = self::ROLE_PERMISSIONS['pro_user'];
            } elseif ($existing->plan_tier === 'free' && in_array('pro_user', $roles, true)) {
                $roles = ['free_user'];
                $permissions = self::ROLE_PERMISSIONS['free_user'];
            }
        }

        $tier = $this->resolveTierFromRolesAndPermissions($roles, $permissions);

        $payload = [
            'name' => $name ?: ($existing->name ?? 'Mochi User'),
            'email' => $resolvedEmail,
            'roles' => json_encode(array_values(array_unique($roles))),
            'permissions' => json_encode(array_values(array_unique($permissions))),
            'plan_tier' => $tier,
            'tenant_id' => $claims->tenantId,
            'last_login_at' => $now,
            'updated_at' => $now,
        ];

        if ($existing) {
            DB::table('finance_users')->where('sub', $claims->sub)->update($payload);
        } else {
            $payload['sub'] = $claims->sub;
            $payload['status'] = 'active';
            $payload['billing_cycle'] = $tier === 'admin' ? 'lifetime' : null;
            $payload['created_at'] = $now;
            DB::table('finance_users')->insert($payload);
        }

        return DB::table('finance_users')->where('sub', $claims->sub)->first();
    }

    public function resolveTierFromRolesAndPermissions(array $roles, array $permissions): string
    {
        if (in_array('admin', $roles, true) || in_array('owner', $roles, true) || in_array('finance.admin', $permissions, true) || in_array('*', $permissions, true)) {
            return 'admin';
        }
        if (in_array('pro_user', $roles, true) || in_array('finance.sync', $permissions, true) || in_array('finance.unlimited', $permissions, true)) {
            return 'pro';
        }

        return 'free';
    }

    public function changeUserTier(string $sub, string $targetTier, string $billingCycle = 'monthly'): void
    {
        $now = Carbon::now();
        $roleKey = match ($targetTier) {
            'admin' => 'admin',
            'pro' => 'pro_user',
            default => 'free_user',
        };

        $permissions = self::ROLE_PERMISSIONS[$roleKey];
        $expiresAt = null;
        $cycle = null;

        if ($targetTier === 'pro') {
            $cycle = $billingCycle;
            $expiresAt = match ($billingCycle) {
                'yearly' => $now->copy()->addYear(),
                'lifetime' => null,
                default => $now->copy()->addMonth(),
            };
        } elseif ($targetTier === 'admin') {
            $cycle = 'lifetime';
        }

        DB::table('finance_users')->where('sub', $sub)->update([
            'plan_tier' => $targetTier,
            'billing_cycle' => $cycle,
            'pro_expires_at' => $expiresAt,
            'roles' => json_encode([$roleKey]),
            'permissions' => json_encode($permissions),
            'updated_at' => $now,
        ]);

        $this->syncRoleToIamDatabase($sub, $roleKey);
    }

    public function syncRoleToIamDatabase(string $sub, string $roleKey): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        try {
            $pdo = new \PDO('mysql:host=127.0.0.1;port=3306;dbname=iam_sso_core', 'root', '');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $userStmt = $pdo->prepare('SELECT id FROM users WHERE sub = :sub LIMIT 1');
            $userStmt->execute(['sub' => $sub]);
            $userId = $userStmt->fetchColumn();

            $appStmt = $pdo->prepare('SELECT id FROM applications WHERE client_id = :cid LIMIT 1');
            $appStmt->execute(['cid' => $this->clientId()]);
            $appId = $appStmt->fetchColumn();

            if (! $userId || ! $appId) {
                return;
            }

            $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE application_id = :app_id AND `key` = :rkey LIMIT 1');
            $roleStmt->execute(['app_id' => $appId, 'rkey' => $roleKey]);
            $targetRoleId = $roleStmt->fetchColumn();

            if (! $targetRoleId) {
                return;
            }

            // Hapus role lama untuk aplikasi mochi-finance lalu pasang role baru
            $delStmt = $pdo->prepare('DELETE FROM user_roles WHERE user_id = :uid AND role_id IN (SELECT id FROM roles WHERE application_id = :app_id)');
            $delStmt->execute(['uid' => $userId, 'app_id' => $appId]);

            $insStmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id, organization_id) VALUES (:uid, :rid, NULL)');
            $insStmt->execute(['uid' => $userId, 'rid' => $targetRoleId]);
        } catch (\Throwable) {
            // Abaikan bila berjalan pada environment testing SQLite in-memory
        }
    }

    public function ensureDefaultPlansExist(): void
    {
        $now = Carbon::now();
        if (! DB::table('saas_plans')->where('key', 'free')->exists()) {
            DB::table('saas_plans')->insert([
                [
                    'key' => 'free',
                    'name' => 'Mochi Free Starter',
                    'badge' => 'FREE STARTER',
                    'monthly_price' => 0,
                    'yearly_price' => 0,
                    'max_wallets' => 2,
                    'max_budgets' => 0,
                    'max_goals' => 0,
                    'allow_cloud_sync' => true,
                    'allow_smart_inbox' => false,
                    'allow_analytics' => false,
                    'allow_csv_export' => false,
                    'features' => json_encode([
                        'Pencatatan Transaksi Harian (Trial 14 Hari)',
                        'Maksimal 2 Dompet / Rekening Aktif',
                        'Cloud Sync & Backup Otomatis (Maks 2 Dompet)',
                        'Keamanan Kunci PIN 4 Digit',
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'key' => 'pro',
                    'name' => 'Mochi Pro Plus ✨',
                    'badge' => 'MOCHI PRO ✨',
                    'monthly_price' => 29000,
                    'yearly_price' => 249000,
                    'max_wallets' => -1,
                    'max_budgets' => -1,
                    'max_goals' => -1,
                    'allow_cloud_sync' => true,
                    'allow_smart_inbox' => true,
                    'allow_analytics' => true,
                    'allow_csv_export' => true,
                    'features' => json_encode([
                        'Dompet, Rekening, Anggaran, & Celengan Tanpa Batas (Unlimited)',
                        'Cloud Sync & Backup Otomatis Lintas Perangkat (HP & Laptop)',
                        'Smart Inbox Otomatis (Deteksi Mutasi BCA, Mandiri, GoPay, OVO)',
                        'Grafik & Analitik Cashflow 6 Bulan + Kategori Donut',
                        'Ekspor Laporan Keuangan CSV (Excel Compatible)',
                        'Tagihan & Langganan Rutin (1-Click Pay)',
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        } else {
            DB::table('saas_plans')->where('key', 'free')->update([
                'max_wallets' => 2,
                'max_budgets' => 0,
                'max_goals' => 0,
                'allow_cloud_sync' => true,
                'features' => json_encode([
                    'Pencatatan Transaksi Harian (Trial 14 Hari)',
                    'Maksimal 2 Dompet / Rekening Aktif',
                    'Cloud Sync & Backup Otomatis (Maks 2 Dompet)',
                    'Keamanan Kunci PIN 4 Digit',
                ]),
                'updated_at' => $now,
            ]);
        }
    }
}
