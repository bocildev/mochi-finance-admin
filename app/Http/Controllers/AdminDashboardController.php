<?php

namespace App\Http\Controllers;

use App\Services\IamSsoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function __construct(private IamSsoService $sso) {}

    private function syncClientsFromIam(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        try {
            $iamHost = env('IAM_DB_HOST', config('database.connections.mysql.host', '127.0.0.1'));
            $iamPort = env('IAM_DB_PORT', config('database.connections.mysql.port', '3306'));
            $iamDb = env('IAM_DB_DATABASE', 'iam_sso_core');
            $iamUser = env('IAM_DB_USERNAME', config('database.connections.mysql.username', 'root'));
            $iamPass = env('IAM_DB_PASSWORD', config('database.connections.mysql.password', ''));

            $pdo = new \PDO("mysql:host={$iamHost};port={$iamPort};dbname={$iamDb}", $iamUser, $iamPass);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $appStmt = $pdo->query("SELECT id FROM applications WHERE client_id IN ('app_mochi_finance_admin', 'app_mochi_finance')");
            $appIds = $appStmt ? $appStmt->fetchAll(\PDO::FETCH_COLUMN) : [];
            if (empty($appIds)) {
                return;
            }

            $inIds = implode(',', array_map('intval', $appIds));
            $sql = "
                SELECT u.sub, u.name, u.email, u.status, u.is_super_admin, u.created_at,
                       GROUP_CONCAT(r.`key`) AS role_keys
                FROM users u
                LEFT JOIN user_applications ua ON ua.user_id = u.id AND ua.application_id IN ({$inIds})
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                LEFT JOIN roles r ON r.id = ur.role_id AND r.application_id IN ({$inIds})
                WHERE ua.application_id IS NOT NULL OR r.id IS NOT NULL OR u.is_super_admin = 1
                GROUP BY u.id, u.sub, u.name, u.email, u.status, u.is_super_admin, u.created_at
            ";
            $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_OBJ);

            foreach ($rows as $row) {
                $existing = DB::table('finance_users')->where('sub', $row->sub)->first();
                $roleKeys = array_filter(explode(',', (string) $row->role_keys));
                if (empty($roleKeys)) {
                    $roleKeys = ($row->is_super_admin || in_array(strtolower($row->email), ['admin@iam-sso.local', 'athallahrich@gmail.com'], true))
                        ? ['admin']
                        : ['free_user'];
                }

                $primaryRole = in_array('admin', $roleKeys, true)
                    ? 'admin'
                    : (in_array('pro_user', $roleKeys, true) ? 'pro_user' : 'free_user');

                $tier = match ($primaryRole) {
                    'admin' => 'admin',
                    'pro_user' => 'pro',
                    default => 'free',
                };

                $perms = IamSsoService::ROLE_PERMISSIONS[$primaryRole] ?? IamSsoService::ROLE_PERMISSIONS['free_user'];

                if (! $existing) {
                    DB::table('finance_users')->insert([
                        'sub' => $row->sub,
                        'name' => $row->name,
                        'email' => strtolower($row->email),
                        'roles' => json_encode([$primaryRole]),
                        'permissions' => json_encode($perms),
                        'plan_tier' => $tier,
                        'billing_cycle' => $tier === 'admin' ? 'lifetime' : null,
                        'status' => $row->status === 'active' ? 'active' : 'disabled',
                        'created_at' => $row->created_at ?: now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        } catch (\Throwable) {
            // Abaikan bila berjalan pada environment testing SQLite
        }
    }

    private function commonViewData(Request $request): array
    {
        $this->sso->ensureDefaultPlansExist();
        $this->syncClientsFromIam();

        $pendingUpgradesCount = (int) DB::table('upgrade_requests')->where('status', 'PENDING')->count();

        return [
            'user' => $request->attributes->get('iam_user'),
            'pendingUpgradesCount' => $pendingUpgradesCount,
            'pwaUrl' => config('iam.pwa_url'),
            'iamIssuer' => config('iam.issuer'),
        ];
    }

    public function index(Request $request)
    {
        $base = $this->commonViewData($request);

        $proPlan = DB::table('saas_plans')->where('key', 'pro')->first();
        $monthlyPrice = (float) ($proPlan->monthly_price ?? 29000);
        $yearlyPrice = (float) ($proPlan->yearly_price ?? 249000);

        $totalClients = (int) DB::table('finance_users')->where('plan_tier', '!=', 'admin')->count();
        $proClients = (int) DB::table('finance_users')->where('plan_tier', 'pro')->where('status', 'active')->count();
        $freeClients = (int) DB::table('finance_users')->where('plan_tier', 'free')->where('status', 'active')->count();
        $suspendedClients = (int) DB::table('finance_users')->where('status', '!=', 'active')->count();

        $monthlyProCount = (int) DB::table('finance_users')->where('plan_tier', 'pro')->where('billing_cycle', 'monthly')->count();
        $yearlyProCount = (int) DB::table('finance_users')->where('plan_tier', 'pro')->where('billing_cycle', 'yearly')->count();
        $mrr = ($monthlyProCount * $monthlyPrice) + ($yearlyProCount * ($yearlyPrice / 12));
        $conversionRate = $totalClients > 0 ? round(($proClients / $totalClients) * 100, 1) : 0.0;

        $totalSyncedWallets = (int) DB::table('finance_wallets')->count();
        $totalSyncedTx = (int) DB::table('finance_transactions')->count();

        $recentClients = DB::table('finance_users')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(function ($c) {
                $c->wallets_count = DB::table('finance_wallets')->where('user_sub', $c->sub)->count();
                $c->transactions_count = DB::table('finance_transactions')->where('user_sub', $c->sub)->count();
                return $c;
            });

        $recentUpgrades = DB::table('upgrade_requests')
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        $webhookEvents = DB::table('iam_webhook_events')
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        return view('admin.overview', array_merge($base, [
            'stats' => [
                'totalClients' => $totalClients,
                'proClients' => $proClients,
                'freeClients' => $freeClients,
                'suspendedClients' => $suspendedClients,
                'mrr' => $mrr,
                'arr' => $mrr * 12,
                'conversionRate' => $conversionRate,
                'totalSyncedWallets' => $totalSyncedWallets,
                'totalSyncedTx' => $totalSyncedTx,
            ],
            'recentClients' => $recentClients,
            'recentUpgrades' => $recentUpgrades,
            'webhookEvents' => $webhookEvents,
        ]));
    }

    public function clients(Request $request)
    {
        $base = $this->commonViewData($request);

        $tierFilter = (string) $request->query('tier', 'all');
        $search = trim((string) $request->query('q', ''));

        $query = DB::table('finance_users')->orderByRaw("CASE WHEN plan_tier = 'admin' THEN 3 WHEN plan_tier = 'pro' THEN 1 ELSE 2 END")->orderByDesc('created_at');

        if ($tierFilter === 'pro') {
            $query->where('plan_tier', 'pro');
        } elseif ($tierFilter === 'free') {
            $query->where('plan_tier', 'free');
        } elseif ($tierFilter === 'admin') {
            $query->where('plan_tier', 'admin');
        } elseif ($tierFilter === 'suspended') {
            $query->where('status', '!=', 'active');
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('sub', 'like', '%'.$search.'%');
            });
        }

        $clients = $query->get()->map(function ($c) {
            $realWallets = DB::table('finance_wallets')->where('user_sub', $c->sub)->count();
            $realTx = DB::table('finance_transactions')->where('user_sub', $c->sub)->count();
            $c->wallets_count = $realWallets;
            $c->transactions_count = $realTx;
            return $c;
        });

        return view('admin.clients', array_merge($base, [
            'clients' => $clients,
            'tierFilter' => $tierFilter,
            'search' => $search,
        ]));
    }

    public function updateClientTier(Request $request, string $sub)
    {
        $data = $request->validate([
            'plan_tier' => 'required|in:free,pro,admin',
            'billing_cycle' => 'nullable|in:monthly,yearly,lifetime',
        ]);

        $client = DB::table('finance_users')->where('sub', $sub)->first();
        if (! $client) {
            return back()->withErrors(['client' => 'Klien tidak ditemukan.']);
        }

        $this->sso->changeUserTier($sub, $data['plan_tier'], $data['billing_cycle'] ?? 'monthly');

        return back()->with('status', 'Level akun '.$client->name.' berhasil diubah menjadi '.strtoupper($data['plan_tier']).' dan disinkronkan ke IAM-SSO-CORE!');
    }

    public function toggleClientStatus(Request $request, string $sub)
    {
        $currentAdmin = $request->attributes->get('iam_user');
        if ($currentAdmin['sub'] === $sub) {
            return back()->withErrors(['client' => 'Anda tidak dapat menangguhkan akun Administrator Anda sendiri.']);
        }

        $client = DB::table('finance_users')->where('sub', $sub)->first();
        if (! $client) {
            return back()->withErrors(['client' => 'Klien tidak ditemukan.']);
        }

        $newStatus = $client->status === 'active' ? 'disabled' : 'active';
        DB::table('finance_users')->where('sub', $sub)->update([
            'status' => $newStatus,
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Status akun '.$client->name.' diubah menjadi '.($newStatus === 'active' ? 'AKTIF' : 'SUSPENDED').'.');
    }

    public function upgrades(Request $request)
    {
        $base = $this->commonViewData($request);
        $statusFilter = (string) $request->query('status', 'ALL');

        $query = DB::table('upgrade_requests')->orderByRaw("CASE WHEN status = 'PENDING' THEN 0 ELSE 1 END")->orderByDesc('created_at');
        if (in_array($statusFilter, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
            $query->where('status', $statusFilter);
        }

        $requests = $query->get();

        return view('admin.upgrades', array_merge($base, [
            'requests' => $requests,
            'statusFilter' => $statusFilter,
        ]));
    }

    public function reviewUpgrade(Request $request, int $id)
    {
        $admin = $request->attributes->get('iam_user');
        $data = $request->validate([
            'action' => 'required|in:APPROVE,REJECT',
        ]);

        $reqRow = DB::table('upgrade_requests')->where('id', $id)->first();
        if (! $reqRow) {
            return back()->withErrors(['upgrade' => 'Data pengajuan upgrade tidak ditemukan.']);
        }

        $now = Carbon::now();
        $newStatus = $data['action'] === 'APPROVE' ? 'APPROVED' : 'REJECTED';

        DB::table('upgrade_requests')->where('id', $id)->update([
            'status' => $newStatus,
            'reviewed_by' => $admin['email'] ?? 'admin',
            'reviewed_at' => $now,
            'updated_at' => $now,
        ]);

        if ($newStatus === 'APPROVED') {
            $cycle = $reqRow->requested_plan === 'pro_yearly' ? 'yearly' : 'monthly';
            $this->sso->changeUserTier($reqRow->user_sub, 'pro', $cycle);

            return back()->with('status', 'Upgrade PRO untuk '.$reqRow->user_name.' telah DISETUJUI! Role `pro_user` sudah aktif di IAM-SSO-CORE.');
        }

        return back()->with('status', 'Pengajuan upgrade dari '.$reqRow->user_name.' telah ditolak.');
    }

    public function plans(Request $request)
    {
        $base = $this->commonViewData($request);
        $plans = DB::table('saas_plans')->orderBy('id')->get();

        return view('admin.plans', array_merge($base, [
            'plans' => $plans,
        ]));
    }

    public function updatePlan(Request $request, string $key)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'badge' => 'required|string|max:64',
            'monthly_price' => 'required|numeric|min:0',
            'yearly_price' => 'required|numeric|min:0',
            'max_wallets' => 'required|integer|min:-1',
            'max_budgets' => 'required|integer|min:-1',
            'max_goals' => 'required|integer|min:-1',
            'allow_cloud_sync' => 'nullable|boolean',
            'allow_smart_inbox' => 'nullable|boolean',
            'allow_analytics' => 'nullable|boolean',
            'allow_csv_export' => 'nullable|boolean',
        ]);

        DB::table('saas_plans')->where('key', $key)->update([
            'name' => $data['name'],
            'badge' => $data['badge'],
            'monthly_price' => (float) $data['monthly_price'],
            'yearly_price' => (float) $data['yearly_price'],
            'max_wallets' => (int) $data['max_wallets'],
            'max_budgets' => (int) $data['max_budgets'],
            'max_goals' => (int) $data['max_goals'],
            'allow_cloud_sync' => $request->boolean('allow_cloud_sync'),
            'allow_smart_inbox' => $request->boolean('allow_smart_inbox'),
            'allow_analytics' => $request->boolean('allow_analytics'),
            'allow_csv_export' => $request->boolean('allow_csv_export'),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Konfigurasi paket '.strtoupper($key).' berhasil diperbarui dan langsung berlaku di aplikasi PWA!');
    }

    public function deleteClient(Request $request, string $sub)
    {
        $client = DB::table('finance_users')->where('sub', $sub)->first();
        if (! $client) {
            return back()->with('error', 'Klien tidak ditemukan.');
        }

        // Proteksi Platform Owner
        $isOwner = in_array(strtolower($client->email), ['athallahrich@gmail.com', 'admin@iam-sso.local'], true)
            || $client->plan_tier === 'admin';

        if ($isOwner) {
            return back()->with('error', 'Akun Platform Owner / Administrator tidak dapat dihapus.');
        }

        DB::transaction(function () use ($sub) {
            DB::table('finance_wallets')->where('user_sub', $sub)->delete();
            DB::table('finance_categories')->where('user_sub', $sub)->delete();
            DB::table('finance_transactions')->where('user_sub', $sub)->delete();
            DB::table('finance_budgets')->where('user_sub', $sub)->delete();
            DB::table('finance_saving_goals')->where('user_sub', $sub)->delete();
            DB::table('finance_recurring_bills')->where('user_sub', $sub)->delete();
            DB::table('finance_inbox_items')->where('user_sub', $sub)->delete();
            DB::table('upgrade_requests')->where('user_sub', $sub)->delete();
            DB::table('finance_users')->where('sub', $sub)->delete();
        });

        return back()->with('status', 'Klien '.$client->name.' ('.$client->email.') beserta seluruh data cloud berhasil dihapus permanen dari Mochi Finance.');
    }

    public function deleteUpgrade(Request $request, int $id)
    {
        $reqRow = DB::table('upgrade_requests')->where('id', $id)->first();
        if (! $reqRow) {
            return back()->with('error', 'Riwayat pengajuan upgrade tidak ditemukan.');
        }

        DB::table('upgrade_requests')->where('id', $id)->delete();

        return back()->with('status', 'Riwayat pengajuan upgrade ID #'.$id.' berhasil dihapus.');
    }
}
