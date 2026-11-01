<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk · Mochi Finance Backoffice</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #13111c; color: #f1f5f9; }
        .font-mono-code { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-pink-950/40 via-[#13111c] to-[#0a0910]">
    <div class="max-w-md w-full bg-[#1c1929]/95 backdrop-blur-xl border border-pink-500/25 rounded-3xl p-8 shadow-2xl space-y-6">
        <div class="text-center space-y-2">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-tr from-pink-500 via-rose-400 to-amber-300 text-3xl shadow-lg shadow-pink-500/30 mb-1">
                🍡
            </div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-500/15 border border-amber-500/30 text-amber-300 text-xs font-bold">
                <span>👑 Owner Backoffice Console</span>
            </div>
            <h1 class="text-2xl font-extrabold text-white tracking-tight">
                Mochi<span class="text-pink-400">Finance</span> SaaS
            </h1>
            <p class="text-xs text-slate-400 leading-relaxed">
                Masuk dengan akun Administrator Platform Anda untuk mengelola klien Free &amp; Pro.
            </p>
        </div>

        @if ($errors->any())
            <div class="p-3.5 rounded-2xl bg-rose-950/60 border border-rose-500/40 text-xs text-rose-200 space-y-1">
                <div class="font-bold text-rose-300">Gagal Masuk</div>
                @foreach ($errors->all() as $err)
                    <div>{{ $err }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('login.submit') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-xs font-bold text-slate-300 mb-1.5">Email Administrator</label>
                <input
                    type="email"
                    name="email"
                    value="{{ old('email', 'athallahrich@gmail.com') }}"
                    required
                    autofocus
                    placeholder="athallahrich@gmail.com"
                    class="w-full px-4 py-3 rounded-xl bg-[#13111c] border border-slate-700/80 text-sm text-white focus:outline-none focus:border-pink-500 transition"
                >
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-300 mb-1.5">Password / Password Sakti</label>
                <input
                    type="password"
                    name="password"
                    required
                    placeholder="••••••••"
                    class="w-full px-4 py-3 rounded-xl bg-[#13111c] border border-slate-700/80 text-sm text-white focus:outline-none focus:border-pink-500 transition"
                >
            </div>
            <button
                type="submit"
                class="w-full py-3.5 px-5 rounded-2xl bg-gradient-to-r from-pink-500 via-rose-500 to-amber-400 hover:opacity-95 text-white font-extrabold text-sm shadow-lg shadow-pink-500/25 transition"
            >
                Masuk ke Backoffice
            </button>
        </form>

        <div class="pt-3 border-t border-slate-800/80 flex items-center justify-between text-xs text-slate-400">
            <a href="{{ $pwaUrl }}" target="_blank" class="hover:text-pink-400 transition font-semibold">
                ↗ Buka Aplikasi Mochi Finance
            </a>
            <a href="{{ route('auth.login') }}" class="hover:text-cyan-400 transition font-semibold">
                🔄 Cek Sesi Otomatis
            </a>
        </div>
    </div>
</body>
</html>
