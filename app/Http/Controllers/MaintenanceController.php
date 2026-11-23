<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MaintenanceController extends Controller
{
    /**
     * Allowlisted Artisan commands for terminal-free cPanel operations.
     */
    private const COMMAND_ALLOWLIST = [
        'migrate' => [
            'label' => 'Run Pending Migrations (--force)',
            'command' => 'migrate',
            'params' => ['--force' => true],
            'destructive' => false,
        ],
        'migrate_pretend' => [
            'label' => 'Simulasi SQL Migrasi (--pretend)',
            'command' => 'migrate',
            'params' => ['--force' => true, '--pretend' => true],
            'destructive' => false,
        ],
        'migrate_status' => [
            'label' => 'Cek Status Migrasi (migrate:status)',
            'command' => 'migrate:status',
            'params' => [],
            'destructive' => false,
        ],
        'migrate_rollback' => [
            'label' => 'Rollback 1 Batch Terakhir (--step=1)',
            'command' => 'migrate:rollback',
            'params' => ['--step' => 1, '--force' => true],
            'destructive' => true,
        ],
        'seed_production' => [
            'label' => 'Seed Default Plans & Owner (HostingProductionSeeder)',
            'command' => 'db:seed',
            'params' => ['--class' => 'HostingProductionSeeder', '--force' => true],
            'destructive' => true,
        ],
        'optimize_clear' => [
            'label' => 'Bersihkan Semua Cache (optimize:clear)',
            'command' => 'optimize:clear',
            'params' => [],
            'destructive' => false,
        ],
        'optimize_cache' => [
            'label' => 'Cache Config, Routes & Views (cPanel Speedup)',
            'command' => 'optimize_bundle',
            'params' => [],
            'destructive' => false,
        ],
        'storage_link' => [
            'label' => 'Buat Symlink Storage (storage:link)',
            'command' => 'storage:link',
            'params' => [],
            'destructive' => false,
        ],
        'route_list' => [
            'label' => 'Inspeksi Route REST API (/api/v1/*)',
            'command' => 'route:list',
            'params' => ['--path' => 'api/v1'],
            'destructive' => false,
        ],
    ];

    private const CORE_TABLES = [
        'migrations',
        'finance_users',
        'finance_wallets',
        'finance_categories',
        'finance_transactions',
        'finance_budgets',
        'finance_saving_goals',
        'finance_recurring_bills',
        'finance_monthly_plans',
        'finance_user_ai_settings',
        'finance_inbox_items',
        'saas_plans',
        'upgrade_requests',
        'system_settings',
        'iam_webhook_events',
        'sessions',
    ];

    public function index(Request $request)
    {
        $pendingUpgradesCount = 0;
        try {
            if (Schema::hasTable('upgrade_requests')) {
                $pendingUpgradesCount = (int) DB::table('upgrade_requests')->where('status', 'PENDING')->count();
            }
        } catch (Throwable) {
            $pendingUpgradesCount = 0;
        }

        $diagnostics = $this->collectEnvironmentDiagnostics();
        $migrationOverview = $this->collectMigrationOverview();
        $tablesHealth = $this->collectTablesHealth();
        $logInfo = $this->readLaravelLogTail(120);

        return view('admin.maintenance', [
            'user' => $request->attributes->get('iam_user'),
            'pendingUpgradesCount' => $pendingUpgradesCount,
            'pwaUrl' => config('iam.pwa_url'),
            'iamIssuer' => config('iam.issuer'),
            'diagnostics' => $diagnostics,
            'migrationOverview' => $migrationOverview,
            'tablesHealth' => $tablesHealth,
            'logInfo' => $logInfo,
            'commandOutput' => $request->session()->get('maintenance_output'),
        ]);
    }

    public function runCommand(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'command_key' => 'required|string|in:'.implode(',', array_keys(self::COMMAND_ALLOWLIST)),
            'confirm_destructive' => 'nullable|boolean',
        ]);

        $key = $data['command_key'];
        $spec = self::COMMAND_ALLOWLIST[$key];

        if ($spec['destructive'] && ! $request->boolean('confirm_destructive')) {
            return redirect()
                ->route('admin.maintenance')
                ->withErrors(['maintenance' => 'Aksi sensitif ini memerlukan centang konfirmasi sebelum dieksekusi.']);
        }

        $start = microtime(true);
        $exitCode = 0;
        $outputText = '';
        $succeeded = true;

        try {
            if ($key === 'optimize_cache') {
                $chunks = [];
                foreach (['config:cache', 'route:cache', 'view:cache'] as $subCmd) {
                    $code = Artisan::call($subCmd, ['--no-ansi' => true]);
                    $out = $this->cleanOutput(Artisan::output());
                    $chunks[] = "$ php artisan {$subCmd}\n{$out}";
                    if ($code !== 0) {
                        $exitCode = $code;
                        $succeeded = false;
                    }
                }
                $outputText = implode("\n", $chunks);
            } elseif ($key === 'route_list') {
                $outputText = $this->formatApiRoutesOutput('api/v1');
            } else {
                $params = $spec['params'] + ['--no-ansi' => true];
                $exitCode = Artisan::call($spec['command'], $params);
                $outputText = $this->cleanOutput(Artisan::output());
                $succeeded = $exitCode === 0;
            }
        } catch (Throwable $e) {
            $exitCode = 1;
            $succeeded = false;
            $outputText = get_class($e).': '.$e->getMessage();
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $request->session()->flash('maintenance_output', [
            'key' => $key,
            'label' => $spec['label'],
            'exitCode' => $exitCode,
            'succeeded' => $succeeded,
            'durationMs' => $durationMs,
            'output' => $outputText !== '' ? $outputText : '(Command selesai tanpa output teks)',
            'ranAt' => now()->toDateTimeString(),
        ]);

        return redirect()
            ->route('admin.maintenance')
            ->with(
                $succeeded ? 'status' : 'error',
                $succeeded
                    ? "Command [{$spec['label']}] selesai dieksekusi dalam {$durationMs} ms."
                    : "Command [{$spec['label']}] selesai dengan error (exit code {$exitCode})."
            );
    }

    public function clearLogs(): RedirectResponse
    {
        $logPath = storage_path('logs/laravel.log');
        try {
            if (File::exists($logPath)) {
                File::put($logPath, '');
            }

            return redirect()
                ->route('admin.maintenance')
                ->with('status', 'File storage/logs/laravel.log berhasil dikosongkan.');
        } catch (Throwable $e) {
            return redirect()
                ->route('admin.maintenance')
                ->withErrors(['maintenance' => 'Gagal mengosongkan log: '.$e->getMessage()]);
        }
    }

    private function collectEnvironmentDiagnostics(): array
    {
        $dbStatus = 'Disconnected';
        $dbError = null;
        $dbLatencyMs = null;
        $dbName = (string) config('database.connections.'.config('database.default').'.database', '');

        try {
            $t0 = microtime(true);
            DB::connection()->getPdo();
            $dbLatencyMs = (int) round((microtime(true) - $t0) * 1000);
            $dbStatus = 'Connected';
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }

        $storageLinkPath = public_path('storage');
        $storageLinked = is_link($storageLinkPath) || File::exists($storageLinkPath);

        $extensions = ['pdo_mysql', 'pdo_sqlite', 'openssl', 'mbstring', 'json', 'curl', 'fileinfo'];
        $extStatus = [];
        foreach ($extensions as $ext) {
            $extStatus[$ext] = extension_loaded($ext);
        }

        return [
            'phpVersion' => PHP_VERSION,
            'laravelVersion' => app()->version(),
            'appEnv' => app()->environment(),
            'appDebug' => (bool) config('app.debug'),
            'appUrl' => (string) config('app.url'),
            'timezone' => (string) config('app.timezone'),
            'dbDriver' => (string) config('database.default'),
            'dbName' => $dbName,
            'dbStatus' => $dbStatus,
            'dbLatencyMs' => $dbLatencyMs,
            'dbError' => $dbError,
            'storageWritable' => File::isWritable(storage_path()),
            'logsWritable' => File::isWritable(storage_path('logs')),
            'bootstrapCacheWritable' => File::isWritable(base_path('bootstrap/cache')),
            'storageLinked' => $storageLinked,
            'extensions' => $extStatus,
            'configCached' => app()->configurationIsCached(),
            'routesCached' => app()->routesAreCached(),
        ];
    }

    private function collectMigrationOverview(): array
    {
        $migrationFiles = collect(File::glob(database_path('migrations/*.php')))
            ->map(fn (string $path) => pathinfo($path, PATHINFO_FILENAME))
            ->sort()
            ->values();

        $ranMap = collect();
        $dbError = null;

        try {
            if (Schema::hasTable('migrations')) {
                $ranMap = DB::table('migrations')->get()->keyBy('migration');
            }
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }

        $items = $migrationFiles->map(function (string $name) use ($ranMap) {
            $ran = $ranMap->get($name);

            return [
                'name' => $name,
                'ran' => $ran !== null,
                'batch' => $ran?->batch,
            ];
        })->values();

        $pendingCount = $items->where('ran', false)->count();

        $rawStatus = '';
        try {
            Artisan::call('migrate:status', ['--no-ansi' => true]);
            $rawStatus = $this->cleanOutput(Artisan::output());
        } catch (Throwable $e) {
            $rawStatus = 'Tidak dapat membaca status migrasi: '.$e->getMessage();
        }

        return [
            'items' => $items->all(),
            'pendingCount' => $pendingCount,
            'ranCount' => $items->where('ran', true)->count(),
            'rawStatus' => $rawStatus,
            'dbError' => $dbError,
        ];
    }

    private function collectTablesHealth(): array
    {
        $rows = [];
        foreach (self::CORE_TABLES as $table) {
            try {
                $exists = Schema::hasTable($table);
                $count = $exists ? (int) DB::table($table)->count() : 0;
                $columns = $exists ? Schema::getColumnListing($table) : [];
                $rows[] = [
                    'table' => $table,
                    'exists' => $exists,
                    'count' => $count,
                    'columns' => $columns,
                    'error' => null,
                ];
            } catch (Throwable $e) {
                $rows[] = [
                    'table' => $table,
                    'exists' => false,
                    'count' => 0,
                    'columns' => [],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $rows;
    }

    private function readLaravelLogTail(int $maxLines = 120): array
    {
        $logPath = storage_path('logs/laravel.log');
        if (! File::exists($logPath)) {
            return [
                'exists' => false,
                'sizeKb' => 0,
                'content' => 'Belum ada file storage/logs/laravel.log.',
            ];
        }

        try {
            $sizeBytes = File::size($logPath);
            $lines = @file($logPath, FILE_IGNORE_NEW_LINES) ?: [];
            $tail = array_slice($lines, -$maxLines);
            $text = implode("\n", $tail);
            $redacted = $this->redactSecrets($text);

            return [
                'exists' => true,
                'sizeKb' => round($sizeBytes / 1024, 1),
                'content' => $redacted !== '' ? $redacted : '(File log kosong)',
            ];
        } catch (Throwable $e) {
            return [
                'exists' => true,
                'sizeKb' => 0,
                'content' => 'Gagal membaca file log: '.$e->getMessage(),
            ];
        }
    }

    private function formatApiRoutesOutput(string $prefix): string
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), $prefix))
            ->sortBy('uri')
            ->values();

        if ($routes->isEmpty()) {
            return "Tidak ada route dengan prefix {$prefix}.";
        }

        $lines = [];
        foreach ($routes as $route) {
            $methods = implode('|', $route->methods());
            $uri = $route->uri();
            $name = $route->getName() ?: '-';
            $action = ltrim($route->getActionName(), '\\');
            $lines[] = sprintf('%-16s %-36s %-26s %s', $methods, $uri, $name, $action);
        }

        $lines[] = '';
        $lines[] = "Showing [{$routes->count()}] routes";

        return implode("\n", $lines);
    }

    private function cleanOutput(string $text): string
    {
        $stripped = preg_replace('/\e\[[0-9;]*m/', '', $text) ?? $text;

        return trim($stripped);
    }

    private function redactSecrets(string $text): string
    {
        $patterns = [
            '/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i' => 'Bearer <REDACTED>',
            '/(APP_KEY|DB_PASSWORD|IAM_WEBHOOK_SECRET)=[^\s\r\n]+/i' => '$1=<REDACTED>',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }
}
