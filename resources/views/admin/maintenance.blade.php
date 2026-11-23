@extends('layouts.admin')

@section('title', 'Maintenance & Debugger')
@section('header_title', '🛠️ cPanel Maintenance & Debugger Console')
@section('header_subtitle', 'Jalankan migrasi database, bersihkan cache Laravel, periksa skema tabel, dan baca log error langsung tanpa terminal SSH.')

@section('content')
    <!-- 1. System & Environment Diagnostics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-5 rounded-2xl bg-[#151221] border border-pink-500/25 space-y-1.5">
            <div class="flex items-center justify-between text-xs text-pink-300 font-bold">
                <span>Runtime &amp; Mode</span>
                <span class="px-2 py-0.5 rounded font-mono-code text-[10px] {{ $diagnostics['appDebug'] ? 'bg-amber-500/20 text-amber-300' : 'bg-emerald-500/15 text-emerald-300' }}">
                    {{ strtoupper($diagnostics['appEnv']) }}
                </span>
            </div>
            <div class="text-lg font-extrabold text-white font-mono-code">
                PHP {{ $diagnostics['phpVersion'] }}
            </div>
            <div class="text-[11px] text-slate-400">
                Laravel <strong class="text-slate-200">{{ $diagnostics['laravelVersion'] }}</strong> ·
                Debug: <strong class="{{ $diagnostics['appDebug'] ? 'text-amber-300' : 'text-slate-300' }}">{{ $diagnostics['appDebug'] ? 'ON' : 'OFF' }}</strong>
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-[#151221] border {{ $diagnostics['dbStatus'] === 'Connected' ? 'border-emerald-500/25' : 'border-rose-500/40' }} space-y-1.5">
            <div class="flex items-center justify-between text-xs font-bold {{ $diagnostics['dbStatus'] === 'Connected' ? 'text-emerald-300' : 'text-rose-300' }}">
                <span>Koneksi Database</span>
                <span class="px-2 py-0.5 rounded font-mono-code text-[10px] {{ $diagnostics['dbStatus'] === 'Connected' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-rose-500/20 text-rose-300' }}">
                    {{ strtoupper($diagnostics['dbDriver']) }}
                </span>
            </div>
            <div class="text-lg font-extrabold {{ $diagnostics['dbStatus'] === 'Connected' ? 'text-emerald-300' : 'text-rose-300' }}">
                {{ $diagnostics['dbStatus'] }}
                @if ($diagnostics['dbLatencyMs'] !== null)
                    <span class="text-xs font-mono-code text-slate-400">({{ $diagnostics['dbLatencyMs'] }} ms)</span>
                @endif
            </div>
            <div class="text-[11px] text-slate-400 truncate font-mono-code">
                {{ $diagnostics['dbError'] ? $diagnostics['dbError'] : 'DB: ' . $diagnostics['dbName'] }}
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-[#151221] border {{ $migrationOverview['pendingCount'] > 0 ? 'border-amber-500/40' : 'border-cyan-500/25' }} space-y-1.5">
            <div class="flex items-center justify-between text-xs font-bold {{ $migrationOverview['pendingCount'] > 0 ? 'text-amber-300' : 'text-cyan-300' }}">
                <span>Status Migrasi</span>
                <span class="px-2 py-0.5 rounded font-mono-code text-[10px] {{ $migrationOverview['pendingCount'] > 0 ? 'bg-amber-500/20 text-amber-300' : 'bg-cyan-500/15 text-cyan-300' }}">
                    {{ $migrationOverview['pendingCount'] > 0 ? 'PERLU MIGRATE' : 'UP TO DATE' }}
                </span>
            </div>
            <div class="text-lg font-extrabold text-white">
                {{ $migrationOverview['pendingCount'] }} Pending
                <span class="text-xs font-normal text-slate-400">/ {{ $migrationOverview['ranCount'] }} Ran</span>
            </div>
            <div class="text-[11px] text-slate-400">
                Total file migrasi: <strong class="text-slate-200">{{ count($migrationOverview['items']) }}</strong>
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-[#151221] border border-purple-500/25 space-y-1.5">
            <div class="flex items-center justify-between text-xs text-purple-300 font-bold">
                <span>Folder &amp; Cache Status</span>
                <span class="px-2 py-0.5 rounded bg-purple-500/15 font-mono-code text-[10px]">HOSTING</span>
            </div>
            <div class="text-xs text-slate-200 font-mono-code space-y-0.5 pt-0.5">
                <div>storage/: <strong class="{{ $diagnostics['storageWritable'] ? 'text-emerald-300' : 'text-rose-400' }}">{{ $diagnostics['storageWritable'] ? 'Writable' : 'Read-Only' }}</strong></div>
                <div>bootstrap/cache: <strong class="{{ $diagnostics['bootstrapCacheWritable'] ? 'text-emerald-300' : 'text-rose-400' }}">{{ $diagnostics['bootstrapCacheWritable'] ? 'Writable' : 'Read-Only' }}</strong></div>
            </div>
            <div class="text-[11px] text-slate-400">
                Config Cache: <strong>{{ $diagnostics['configCached'] ? 'ON' : 'OFF' }}</strong> ·
                Route Cache: <strong>{{ $diagnostics['routesCached'] ? 'ON' : 'OFF' }}</strong>
            </div>
        </div>
    </div>

    <!-- 2. Terminal Output Console (Shown after running any command) -->
    @if ($commandOutput)
        <div class="rounded-2xl bg-[#090710] border {{ $commandOutput['succeeded'] ? 'border-emerald-500/40' : 'border-rose-500/50' }} overflow-hidden shadow-xl">
            <div class="px-5 py-3 bg-[#151221] border-b border-slate-800 flex flex-wrap items-center justify-between gap-2 text-xs">
                <div class="flex items-center gap-2 font-bold">
                    <span class="w-2.5 h-2.5 rounded-full {{ $commandOutput['succeeded'] ? 'bg-emerald-400' : 'bg-rose-400' }}"></span>
                    <span class="text-white">{{ $commandOutput['label'] }}</span>
                </div>
                <div class="flex items-center gap-3 font-mono-code text-[11px] text-slate-400">
                    <span>Exit: <strong class="{{ $commandOutput['succeeded'] ? 'text-emerald-300' : 'text-rose-300' }}">{{ $commandOutput['exitCode'] }}</strong></span>
                    <span>Durasi: <strong class="text-pink-300">{{ $commandOutput['durationMs'] }} ms</strong></span>
                    <span>{{ $commandOutput['ranAt'] }}</span>
                </div>
            </div>
            <pre class="p-5 text-xs font-mono-code text-slate-200 overflow-x-auto leading-relaxed whitespace-pre-wrap">{{ $commandOutput['output'] }}</pre>
        </div>
    @endif

    <!-- 3. Action Panel: Migrations & Cache/Server Tools -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Left: Database Migrations Actions & Status (7 cols) -->
        <div class="lg:col-span-7 p-6 rounded-2xl bg-[#151221] border border-slate-800 space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-extrabold text-white">🗄️ Database Migration Runner</h2>
                    <p class="text-xs text-slate-400">Eksekusi <code class="font-mono-code text-pink-300">php artisan migrate --force</code> langsung di server cPanel.</p>
                </div>
                <span class="px-2.5 py-1 rounded-full text-[10px] font-mono-code font-bold {{ $migrationOverview['pendingCount'] > 0 ? 'bg-amber-500/20 border border-amber-500/40 text-amber-300' : 'bg-emerald-500/15 border border-emerald-500/30 text-emerald-300' }}">
                    {{ $migrationOverview['pendingCount'] }} Pending
                </span>
            </div>

            <!-- Safe Quick Actions -->
            <div class="flex flex-wrap gap-2.5">
                <form method="POST" action="{{ route('admin.maintenance.run') }}">
                    @csrf
                    <input type="hidden" name="command_key" value="migrate">
                    <button
                        type="submit"
                        class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 hover:opacity-95 text-slate-950 text-xs font-extrabold shadow-md shadow-emerald-500/20 transition"
                    >
                        🚀 Run Migrate (--force)
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.maintenance.run') }}">
                    @csrf
                    <input type="hidden" name="command_key" value="migrate_pretend">
                    <button
                        type="submit"
                        class="px-4 py-2.5 rounded-xl bg-cyan-500/15 hover:bg-cyan-500/25 border border-cyan-500/35 text-cyan-200 text-xs font-bold transition"
                    >
                        🔍 Simulasi SQL (--pretend)
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.maintenance.run') }}">
                    @csrf
                    <input type="hidden" name="command_key" value="migrate_status">
                    <button
                        type="submit"
                        class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-bold transition"
                    >
                        📋 Refresh Status
                    </button>
                </form>
            </div>

            <!-- Migration List Table -->
            <div class="overflow-x-auto rounded-xl border border-slate-800">
                <table class="w-full text-left text-xs">
                    <thead class="bg-[#0d0b14] text-slate-400">
                        <tr>
                            <th class="py-2.5 px-3">File Migrasi</th>
                            <th class="py-2.5 px-3 text-center">Batch</th>
                            <th class="py-2.5 px-3 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/70">
                        @forelse ($migrationOverview['items'] as $mig)
                            <tr class="hover:bg-slate-800/30">
                                <td class="py-2.5 px-3 font-mono-code text-[11px] text-slate-200">{{ $mig['name'] }}</td>
                                <td class="py-2.5 px-3 font-mono-code text-center text-slate-400">{{ $mig['batch'] ?? '—' }}</td>
                                <td class="py-2.5 px-3 text-right">
                                    @if ($mig['ran'])
                                        <span class="px-2 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-[10px] font-mono-code font-bold">
                                            RAN
                                        </span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full bg-amber-500/20 border border-amber-500/40 text-amber-300 text-[10px] font-mono-code font-bold animate-pulse">
                                            PENDING
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-4 px-3 text-center text-slate-500">Tidak ada file migrasi ditemukan.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Sensitive Actions (Rollback & Seeder with Explicit Checkbox) -->
            <div class="p-4 rounded-xl bg-rose-950/20 border border-rose-500/25 space-y-3">
                <div class="text-xs font-extrabold text-rose-300">⚠️ Aksi Sensitif Database (Wajib Konfirmasi)</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <form method="POST" action="{{ route('admin.maintenance.run') }}" class="p-3 rounded-xl bg-[#0d0b14] border border-slate-800 space-y-2.5">
                        @csrf
                        <input type="hidden" name="command_key" value="migrate_rollback">
                        <div class="text-[11px] font-bold text-slate-200">Rollback 1 Batch Terakhir</div>
                        <label class="flex items-center gap-2 text-[11px] text-slate-400 cursor-pointer">
                            <input type="checkbox" name="confirm_destructive" value="1" required class="accent-rose-500">
                            <span>Saya yakin rollback 1 batch</span>
                        </label>
                        <button type="submit" class="w-full py-2 px-3 rounded-lg bg-rose-500/15 hover:bg-rose-500/25 border border-rose-500/35 text-rose-300 text-[11px] font-extrabold transition">
                            ⏪ Rollback (--step=1)
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.maintenance.run') }}" class="p-3 rounded-xl bg-[#0d0b14] border border-slate-800 space-y-2.5">
                        @csrf
                        <input type="hidden" name="command_key" value="seed_production">
                        <div class="text-[11px] font-bold text-slate-200">Seed Default Plans &amp; Owner</div>
                        <label class="flex items-center gap-2 text-[11px] text-slate-400 cursor-pointer">
                            <input type="checkbox" name="confirm_destructive" value="1" required class="accent-amber-500">
                            <span>Jalankan HostingProductionSeeder</span>
                        </label>
                        <button type="submit" class="w-full py-2 px-3 rounded-lg bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/35 text-amber-300 text-[11px] font-extrabold transition">
                            🌱 Seed Hosting Production
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right: Cache, Route, Storage & Extensions (5 cols) -->
        <div class="lg:col-span-5 space-y-6">
            <div class="p-6 rounded-2xl bg-[#151221] border border-slate-800 space-y-4">
                <div>
                    <h2 class="text-sm font-extrabold text-white">⚡ Cache, Routing &amp; Storage cPanel</h2>
                    <p class="text-xs text-slate-400">Bersihkan cache setelah upload kode atau periksa daftar endpoint REST API.</p>
                </div>

                <div class="space-y-2.5">
                    <form method="POST" action="{{ route('admin.maintenance.run') }}">
                        @csrf
                        <input type="hidden" name="command_key" value="optimize_clear">
                        <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-pink-500/15 hover:bg-pink-500/25 border border-pink-500/35 text-pink-200 text-xs font-extrabold text-left flex items-center justify-between transition">
                            <span>🧹 Bersihkan Semua Cache (optimize:clear)</span>
                            <span class="font-mono-code text-[10px] text-pink-300">AMAN</span>
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.maintenance.run') }}">
                        @csrf
                        <input type="hidden" name="command_key" value="optimize_cache">
                        <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-purple-500/15 hover:bg-purple-500/25 border border-purple-500/35 text-purple-200 text-xs font-extrabold text-left flex items-center justify-between transition">
                            <span>⚡ Cache Config, Route &amp; View</span>
                            <span class="font-mono-code text-[10px] text-purple-300">PROD</span>
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.maintenance.run') }}">
                        @csrf
                        <input type="hidden" name="command_key" value="route_list">
                        <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-cyan-500/15 hover:bg-cyan-500/25 border border-cyan-500/35 text-cyan-200 text-xs font-extrabold text-left flex items-center justify-between transition">
                            <span>🧭 Inspeksi Daftar Route API (/api/v1/*)</span>
                            <span class="font-mono-code text-[10px] text-cyan-300">DEBUG</span>
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.maintenance.run') }}">
                        @csrf
                        <input type="hidden" name="command_key" value="storage_link">
                        <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-bold text-left flex items-center justify-between transition">
                            <span>🔗 Buat Symlink Storage (storage:link)</span>
                            <span class="font-mono-code text-[10px] {{ $diagnostics['storageLinked'] ? 'text-emerald-300' : 'text-amber-300' }}">
                                {{ $diagnostics['storageLinked'] ? 'TERPASANG' : 'BELUM' }}
                            </span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- PHP Extensions & App Info -->
            <div class="p-6 rounded-2xl bg-[#151221] border border-slate-800 space-y-3">
                <h2 class="text-sm font-extrabold text-white">🧩 Ekstensi PHP &amp; Konfigurasi</h2>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($diagnostics['extensions'] as $ext => $loaded)
                        <span class="px-2.5 py-1 rounded-lg text-[10px] font-mono-code font-bold border {{ $loaded ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' : 'bg-rose-500/15 border-rose-500/30 text-rose-300' }}">
                            {{ $loaded ? '✓' : '✕' }} {{ $ext }}
                        </span>
                    @endforeach
                </div>
                <div class="pt-2 border-t border-slate-800 text-[11px] text-slate-400 space-y-1 font-mono-code">
                    <div class="truncate">APP_URL: <span class="text-slate-200">{{ $diagnostics['appUrl'] }}</span></div>
                    <div>Timezone: <span class="text-slate-200">{{ $diagnostics['timezone'] }}</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. Database Schema & Row Counts Inspector -->
    <div class="p-6 rounded-2xl bg-[#151221] border border-slate-800 space-y-4">
        <div>
            <h2 class="text-sm font-extrabold text-white">🔍 Inspeksi Tabel &amp; Kolom Database</h2>
            <p class="text-xs text-slate-400">Verifikasi cepat apakah tabel dan kolom terbaru sudah terbentuk sempurna setelah migrasi.</p>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="w-full text-left text-xs">
                <thead class="bg-[#0d0b14] text-slate-400">
                    <tr>
                        <th class="py-2.5 px-3">Nama Tabel</th>
                        <th class="py-2.5 px-3">Status</th>
                        <th class="py-2.5 px-3 text-right">Total Baris</th>
                        <th class="py-2.5 px-3">Daftar Kolom</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/70">
                    @foreach ($tablesHealth as $tbl)
                        <tr class="hover:bg-slate-800/30 align-top">
                            <td class="py-2.5 px-3 font-mono-code font-bold text-white whitespace-nowrap">{{ $tbl['table'] }}</td>
                            <td class="py-2.5 px-3 whitespace-nowrap">
                                @if ($tbl['exists'])
                                    <span class="px-2 py-0.5 rounded-full bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 text-[10px] font-mono-code font-bold">ADA</span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full bg-rose-500/20 border border-rose-500/35 text-rose-300 text-[10px] font-mono-code font-bold">BELUM ADA</span>
                                @endif
                            </td>
                            <td class="py-2.5 px-3 text-right font-mono-code text-pink-300 font-bold">{{ $tbl['count'] }}</td>
                            <td class="py-2.5 px-3 font-mono-code text-[11px] text-slate-400">
                                @if ($tbl['error'])
                                    <span class="text-rose-300">{{ $tbl['error'] }}</span>
                                @elseif (!empty($tbl['columns']))
                                    {{ implode(', ', $tbl['columns']) }}
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- 5. Laravel Log Tail Viewer (Secret Redacted) -->
    <div class="p-6 rounded-2xl bg-[#151221] border border-slate-800 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-extrabold text-white">📜 Laravel Error Log Viewer (120 Baris Terakhir)</h2>
                <p class="text-xs text-slate-400">
                    File: <code class="font-mono-code text-pink-300">storage/logs/laravel.log</code>
                    ({{ $logInfo['sizeKb'] }} KB · token &amp; credential otomatis disensor)
                </p>
            </div>
            <div class="flex items-center gap-2">
                <a
                    href="{{ route('admin.maintenance') }}"
                    class="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-bold transition"
                >
                    🔄 Refresh Log
                </a>
                @if ($logInfo['exists'])
                    <form method="POST" action="{{ route('admin.maintenance.logs.clear') }}">
                        @csrf
                        <button
                            type="submit"
                            class="px-3.5 py-2 rounded-xl bg-rose-500/15 hover:bg-rose-500/25 border border-rose-500/35 text-rose-300 text-xs font-bold transition"
                        >
                            🗑️ Kosongkan Log
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <pre class="p-4 rounded-xl bg-[#090710] border border-slate-800 text-[11px] font-mono-code text-slate-300 max-h-96 overflow-auto whitespace-pre-wrap leading-relaxed">{{ $logInfo['content'] }}</pre>
    </div>
@endsection
