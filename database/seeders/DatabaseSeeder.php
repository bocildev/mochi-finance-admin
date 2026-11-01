<?php

namespace Database\Seeders;

use App\Services\IamSsoService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $sso = app(IamSsoService::class);
        $sso->ensureDefaultPlansExist();

        $adminPerm = ['finance.admin', 'finance.basic', 'finance.sync', 'finance.smart_inbox', 'finance.analytics', 'finance.export', 'finance.unlimited'];
        $proPerm = ['finance.basic', 'finance.sync', 'finance.smart_inbox', 'finance.analytics', 'finance.export', 'finance.unlimited'];
        $freePerm = ['finance.basic'];

        $iamUsers = [];
        try {
            $pdo = new \PDO('mysql:host=127.0.0.1;port=3306;dbname=iam_sso_core', 'root', '');
            $stmt = $pdo->query("SELECT sub, name, email, is_super_admin FROM users");
            if ($stmt) {
                $iamUsers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }
        } catch (\Throwable) {
            // Fallback jika DB iam_sso_core tidak terjangkau saat test
        }

        if (! empty($iamUsers)) {
            foreach ($iamUsers as $u) {
                $isOwner = ! empty($u['is_super_admin']) || in_array($u['email'], ['admin@iam-sso.local', 'athallahrich@gmail.com'], true);
                DB::table('finance_users')->updateOrInsert(
                    ['sub' => $u['sub']],
                    [
                        'name' => $u['name'],
                        'email' => $u['email'],
                        'roles' => json_encode([$isOwner ? 'admin' : 'free_user']),
                        'permissions' => json_encode($isOwner ? $adminPerm : $freePerm),
                        'plan_tier' => $isOwner ? 'admin' : 'free',
                        'status' => 'active',
                        'wallets_count' => $isOwner ? 3 : 1,
                        'transactions_count' => $isOwner ? 4 : 0,
                        'budgets_count' => $isOwner ? 2 : 0,
                        'goals_count' => $isOwner ? 2 : 0,
                        'last_login_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        } else {
            DB::table('finance_users')->updateOrInsert(
                ['sub' => 'usr_super_admin'],
                [
                    'name' => 'Super Admin IAM',
                    'email' => 'admin@iam-sso.local',
                    'roles' => json_encode(['admin']),
                    'permissions' => json_encode($adminPerm),
                    'plan_tier' => 'admin',
                    'status' => 'active',
                    'wallets_count' => 3,
                    'transactions_count' => 4,
                    'budgets_count' => 2,
                    'goals_count' => 2,
                    'last_login_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // Seed contoh client terdaftar (Pro & Free) agar SaaS Console informatif
        DB::table('finance_users')->updateOrInsert(
            ['sub' => 'usr_client_pro_01'],
            [
                'name' => 'Nadia Putri Maharani',
                'email' => 'nadia.putri@gmail.com',
                'roles' => json_encode(['pro_user']),
                'permissions' => json_encode($proPerm),
                'plan_tier' => 'pro',
                'billing_cycle' => 'monthly',
                'pro_expires_at' => now()->addDays(24),
                'status' => 'active',
                'wallets_count' => 5,
                'transactions_count' => 48,
                'budgets_count' => 4,
                'goals_count' => 3,
                'last_login_at' => now()->subHours(2),
                'last_sync_at' => now()->subMinutes(25),
                'updated_at' => now(),
                'created_at' => now()->subDays(12),
            ]
        );

        DB::table('finance_users')->updateOrInsert(
            ['sub' => 'usr_client_free_01'],
            [
                'name' => 'Budi Santoso',
                'email' => 'budi.santoso@gmail.com',
                'roles' => json_encode(['free_user']),
                'permissions' => json_encode($freePerm),
                'plan_tier' => 'free',
                'billing_cycle' => null,
                'pro_expires_at' => null,
                'status' => 'active',
                'wallets_count' => 2,
                'transactions_count' => 14,
                'budgets_count' => 2,
                'goals_count' => 1,
                'last_login_at' => now()->subMinutes(40),
                'updated_at' => now(),
                'created_at' => now()->subDays(3),
            ]
        );

        if (DB::table('upgrade_requests')->count() === 0) {
            DB::table('upgrade_requests')->insert([
                [
                    'user_sub' => 'usr_client_free_01',
                    'user_name' => 'Budi Santoso',
                    'user_email' => 'budi.santoso@gmail.com',
                    'requested_plan' => 'pro_monthly',
                    'amount' => 29000,
                    'payment_method' => 'QRIS',
                    'notes' => 'Sudah transfer via QRIS GoPay a.n Budi Santoso, mohon aktifkan fitur Cloud Sync & Unlimited Dompet.',
                    'status' => 'PENDING',
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'created_at' => now()->subMinutes(30),
                    'updated_at' => now()->subMinutes(30),
                ],
                [
                    'user_sub' => 'usr_client_pro_01',
                    'user_name' => 'Nadia Putri Maharani',
                    'user_email' => 'nadia.putri@gmail.com',
                    'requested_plan' => 'pro_monthly',
                    'amount' => 29000,
                    'payment_method' => 'BCA VA',
                    'notes' => 'Langganan Mochi Pro bulanan.',
                    'status' => 'APPROVED',
                    'reviewed_by' => 'athallahrich@gmail.com',
                    'reviewed_at' => now()->subDays(6),
                    'created_at' => now()->subDays(6),
                    'updated_at' => now()->subDays(6),
                ],
            ]);
        }
    }
}
