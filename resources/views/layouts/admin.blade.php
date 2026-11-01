<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'SaaS Backoffice') · Mochi Finance Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0d0b14; color: #f1f5f9; }
        .font-mono-code { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="min-h-screen bg-[#0d0b14] text-slate-100 flex">
    <!-- Left SaaS Sidebar -->
    <aside class="w-72 bg-[#151221] border-r border-pink-500/15 flex flex-col justify-between shrink-0 min-h-screen sticky top-0">
        <div class="p-6 space-y-6">
            <!-- Brand Header -->
            <div class="flex items-center gap-3.5 pb-5 border-b border-slate-800/80">
                <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-pink-500 via-rose-400 to-amber-300 flex items-center justify-center text-2xl shadow-lg shadow-pink-500/25 shrink-0">
                    🍡
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5">
                        <span class="text-base font-extrabold text-white tracking-tight truncate">
                            Mochi<span class="text-pink-400">Finance</span>
                        </span>
                        <span class="px-1.5 py-0.5 rounded bg-pink-500/20 border border-pink-500/30 text-pink-300 text-[10px] font-mono-code font-bold">
                            SaaS
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-400 font-medium truncate">
                        Owner Backoffice Console
                    </p>
                </div>
            </div>

            <!-- Owner Identity Card -->
            <div class="p-3.5 rounded-2xl bg-gradient-to-br from-pink-950/40 via-[#1d182d] to-purple-950/30 border border-pink-500/25 space-y-1.5">
                <div class="flex items-center justify-between">
                    <span class="px-2 py-0.5 rounded-full bg-amber-500/15 border border-amber-500/30 text-amber-300 text-[10px] font-mono-code font-bold">
                        👑 PLATFORM OWNER
                    </span>
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                </div>
                <div class="text-sm font-extrabold text-white truncate">{{ $user['name'] }}</div>
                <div class="text-[11px] text-slate-400 font-mono-code truncate">{{ $user['email'] }}</div>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-1.5">
                <div class="px-3 pb-1 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                    Manajemen SaaS
                </div>

                <a
                    href="{{ route('admin.dashboard') }}"
                    class="flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition {{ request()->routeIs('admin.dashboard') ? 'bg-pink-500/15 text-pink-300 border border-pink-500/30' : 'text-slate-400 hover:text-white hover:bg-slate-800/50' }}"
                >
                    <span class="flex items-center gap-2.5">
                        <span>📊</span>
                        <span>Ringkasan & Metrik SaaS</span>
                    </span>
                </a>

                <a
                    href="{{ route('admin.clients') }}"
                    class="flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition {{ request()->routeIs('admin.clients*') ? 'bg-pink-500/15 text-pink-300 border border-pink-500/30' : 'text-slate-400 hover:text-white hover:bg-slate-800/50' }}"
                >
                    <span class="flex items-center gap-2.5">
                        <span>👥</span>
                        <span>Manajemen Klien (Free/Pro)</span>
                    </span>
                </a>

                <a
                    href="{{ route('admin.upgrades') }}"
                    class="flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition {{ request()->routeIs('admin.upgrades*') ? 'bg-pink-500/15 text-pink-300 border border-pink-500/30' : 'text-slate-400 hover:text-white hover:bg-slate-800/50' }}"
                >
                    <span class="flex items-center gap-2.5">
                        <span>💎</span>
                        <span>Permintaan Upgrade Pro</span>
                    </span>
                    @if (($pendingUpgradesCount ?? 0) > 0)
                        <span class="px-2 py-0.5 rounded-full bg-rose-500 text-white text-[10px] font-extrabold">
                            {{ $pendingUpgradesCount }}
                        </span>
                    @endif
                </a>

                <a
                    href="{{ route('admin.plans') }}"
                    class="flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-bold transition {{ request()->routeIs('admin.plans*') ? 'bg-pink-500/15 text-pink-300 border border-pink-500/30' : 'text-slate-400 hover:text-white hover:bg-slate-800/50' }}"
                >
                    <span class="flex items-center gap-2.5">
                        <span>⚙️</span>
                        <span>Paket Harga & Limit Fitur</span>
                    </span>
                </a>
            </nav>
        </div>

        <!-- Bottom Ecosystem Links & Logout -->
        <div class="p-5 border-t border-slate-800/80 space-y-2.5 bg-[#110e1b]">
            <div class="grid grid-cols-2 gap-2">
                <a
                    href="{{ $pwaUrl }}"
                    target="_blank"
                    class="px-3 py-2 rounded-xl bg-pink-500/10 hover:bg-pink-500/20 border border-pink-500/25 text-pink-300 text-[11px] font-bold text-center transition"
                >
                    🍡 Aplikasi PWA
                </a>
                <a
                    href="{{ $iamIssuer }}/admin"
                    target="_blank"
                    class="px-3 py-2 rounded-xl bg-cyan-500/10 hover:bg-cyan-500/20 border border-cyan-500/25 text-cyan-300 text-[11px] font-bold text-center transition"
                >
                    🛡️ IAM Console
                </a>
            </div>

            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-xl bg-rose-500/15 hover:bg-rose-500/25 border border-rose-500/30 text-rose-300 text-xs font-bold transition"
                >
                    Logout dari Backoffice
                </button>
            </form>
        </div>
    </aside>

    <!-- Main Content Column -->
    <div class="flex-1 flex flex-col min-w-0">
        <!-- Top Header -->
        <header class="h-16 bg-[#151221]/80 backdrop-blur-md border-b border-slate-800/80 px-8 flex items-center justify-between sticky top-0 z-20">
            <div>
                <h1 class="text-base font-extrabold text-white">@yield('header_title', 'SaaS Backoffice')</h1>
                <p class="text-xs text-slate-400">@yield('header_subtitle', 'Kelola pengguna terdaftar, langganan Free vs Pro, dan konfigurasi fitur aplikasi Mochi Finance.')</p>
            </div>
            <div class="flex items-center gap-3 text-xs font-mono-code">
                <span class="px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/25 text-emerald-300">
                    OIDC Client: app_mochi_finance
                </span>
            </div>
        </header>

        <main class="p-8 max-w-7xl w-full mx-auto space-y-6">
            @if (session('status'))
                <div class="p-4 rounded-2xl bg-emerald-950/70 border border-emerald-500/40 text-emerald-200 text-xs font-bold flex items-center justify-between">
                    <span>✨ {{ session('status') }}</span>
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 rounded-2xl bg-rose-950/70 border border-rose-500/40 text-rose-200 text-xs space-y-1">
                    @foreach ($errors->all() as $err)
                        <div>⚠️ {{ $err }}</div>
                    @endforeach
                </div>
            @endif

            @yield('content')
        </main>
    </div>
</body>
</html>
