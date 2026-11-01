<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akses Terbatas · Khusus Administrator Mochi Finance</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #0d0b14; color: #f1f5f9; }
        .font-mono-code { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-[#151221] border border-rose-500/30 rounded-3xl p-8 text-center shadow-2xl space-y-5">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-rose-500/15 border border-rose-500/30 text-3xl">
            🔒
        </div>
        <div class="space-y-1.5">
            <span class="px-3 py-1 rounded-full bg-rose-500/15 text-rose-300 text-xs font-bold font-mono-code">
                403 · OWNER BACKOFFICE ONLY
            </span>
            <h1 class="text-xl font-extrabold text-white pt-1">Halaman Khusus Administrator</h1>
            <p class="text-xs text-slate-400 leading-relaxed">
                Halo <strong class="text-white">{{ $user['name'] }}</strong> (<span class="font-mono-code text-pink-300">{{ $user['email'] }}</span>), akun Anda terdaftar sebagai <strong class="text-emerald-300 uppercase">{{ $user['plan_tier'] }} Client</strong>.
                Halaman administrasi ini hanya dapat diakses oleh Pemilik Platform Mochi Finance.
            </p>
        </div>

        <div class="space-y-2.5 pt-2">
            <a
                href="{{ $pwaUrl }}"
                class="w-full py-3 px-4 rounded-2xl bg-gradient-to-r from-pink-500 to-purple-600 text-white font-extrabold text-xs block shadow-lg shadow-pink-500/20"
            >
                🍡 Buka Aplikasi Mochi Finance PWA
            </a>

            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button
                    type="submit"
                    class="w-full py-2.5 px-4 rounded-2xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs transition"
                >
                    Ganti Akun SSO
                </button>
            </form>
        </div>
    </div>
</body>
</html>
