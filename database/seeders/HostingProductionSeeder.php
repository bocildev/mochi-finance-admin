<?php

namespace Database\Seeders;

use App\Services\IamSsoService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder Produksi / Hosting Khusus Mochi Finance Admin
 * Memastikan data SaaS dimulai dari 0:
 * - 0 upgrade request
 * - 0 transaksi/dompet dummy
 * - Hanya menyinkronkan akun Super Admin dari IAM Core dengan metrik 0
 */
class HostingProductionSeeder extends Seeder
{
    public function run(): void
    {
        $sso = app(IamSsoService::class);
        $sso->ensureDefaultPlansExist();

        $adminPerm = ['finance.admin', 'finance.basic', 'finance.sync', 'finance.smart_inbox', 'finance.analytics', 'finance.export', 'finance.unlimited'];

        $adminEmail = env('IAM_ADMIN_EMAIL', 'athallahrich@gmail.com');
        $adminName = env('IAM_ADMIN_NAME', 'Moch. Adam Athallahrich');

        // Daftarkan Super Admin platform dengan saldo/metrik bersih 0
        DB::table('finance_users')->updateOrInsert(
            ['email' => $adminEmail],
            [
                'sub' => 'usr_owner_athallahrich',
                'name' => $adminName,
                'email' => $adminEmail,
                'roles' => json_encode(['admin']),
                'permissions' => json_encode($adminPerm),
                'plan_tier' => 'admin',
                'status' => 'active',
                'wallets_count' => 0,
                'transactions_count' => 0,
                'budgets_count' => 0,
                'goals_count' => 0,
                'last_login_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
