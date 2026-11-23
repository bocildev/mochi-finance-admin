@extends('layouts.admin')

@section('title', 'Pengaturan AI Google Gemini')
@section('header_title', '🤖 Pengaturan AI Planner (Google Gemini Only)')
@section('header_subtitle', 'Konfigurasi API Key Google AI Studio secara terenkripsi di database dan pilih model Gemini untuk fitur analisa gaji & anggaran bulanan.')

@section('content')
    @if (! $settingsTableReady)
        <div class="p-4 rounded-2xl bg-amber-950/60 border border-amber-500/40 text-amber-200 text-xs flex flex-wrap items-center justify-between gap-3">
            <div>
                <strong>⚠️ Tabel <code>system_settings</code> belum ada di database.</strong>
                Jalankan migrasi terlebih dahulu agar API Key dapat disimpan terenkripsi di database.
            </div>
            <a href="{{ route('admin.maintenance') }}" class="px-3.5 py-2 rounded-xl bg-amber-500 text-slate-950 font-extrabold text-xs">
                🛠️ Buka Maintenance &amp; Run Migrate
            </a>
        </div>
    @endif

    <!-- Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="p-5 rounded-2xl bg-[#151221] border {{ $hasApiKey ? 'border-emerald-500/30' : 'border-rose-500/40' }} space-y-1.5">
            <div class="flex items-center justify-between text-xs font-bold {{ $hasApiKey ? 'text-emerald-300' : 'text-rose-300' }}">
                <span>Status API Key Google</span>
                <span class="px-2 py-0.5 rounded font-mono-code text-[10px] {{ $hasApiKey ? 'bg-emerald-500/15' : 'bg-rose-500/20' }}">
                    {{ strtoupper($keySource) }}
                </span>
            </div>
            <div class="text-lg font-extrabold {{ $hasApiKey ? 'text-emerald-300' : 'text-rose-300' }}">
                {{ $hasApiKey ? 'Aktif & Siap Dipakai' : 'Belum Dikonfigurasi' }}
            </div>
            <div class="text-[11px] font-mono-code text-slate-400">
                {{ $maskedKey ?: 'Tidak ada API key tersimpan' }}
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-[#151221] border border-pink-500/25 space-y-1.5">
            <div class="flex items-center justify-between text-xs text-pink-300 font-bold">
                <span>Model Default Aktif</span>
                <span class="px-2 py-0.5 rounded bg-pink-500/15 font-mono-code text-[10px]">GOOGLE ONLY</span>
            </div>
            <div class="text-lg font-extrabold text-white font-mono-code truncate">
                {{ $defaultModel }}
            </div>
            <div class="text-[11px] text-slate-400">
                Auto-fallback aktif bila model terkena limit 429
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-[#151221] border border-cyan-500/25 space-y-2">
            <div class="text-xs text-cyan-300 font-bold">Uji Koneksi Live</div>
            <p class="text-[11px] text-slate-400">Kirim ping JSON singkat ke Google Gemini API menggunakan key aktif.</p>
            <form method="POST" action="{{ route('admin.ai-settings.test') }}">
                @csrf
                <input type="hidden" name="model" value="{{ $defaultModel }}">
                <button
                    type="submit"
                    class="w-full py-2 px-3 rounded-xl bg-cyan-500/15 hover:bg-cyan-500/25 border border-cyan-500/35 text-cyan-200 text-xs font-extrabold transition"
                >
                    🔌 Tes Koneksi Gemini Sekarang
                </button>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Left: Encrypted API Key & Default Model Form -->
        <div class="lg:col-span-7 p-6 rounded-2xl bg-[#151221] border border-slate-800 space-y-5">
            <div>
                <h2 class="text-sm font-extrabold text-white">🔐 Simpan Google Gemini API Key &amp; Model</h2>
                <p class="text-xs text-slate-400 mt-0.5">
                    Key dienkripsi dengan Laravel <code>Crypt</code> di database server dan tidak pernah dikirim ke browser pengguna.
                    Bisa juga diisi lewat <code>GEMINI_API_KEY</code> di file <code>.env</code>.
                </p>
            </div>

            <form method="POST" action="{{ route('admin.ai-settings.update') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-xs font-bold text-pink-300 mb-1.5">Google AI Studio API Key (Baru / Ganti)</label>
                    <input
                        type="password"
                        name="google_api_key"
                        autocomplete="new-password"
                        placeholder="{{ $hasApiKey ? 'Biarkan kosong jika tidak ingin mengganti key tersimpan' : 'Tempel Google API Key (misal AQ.Ab8... atau AIza...)' }}"
                        class="w-full px-4 py-3 rounded-xl bg-[#0d0b14] border border-slate-700 focus:border-pink-500 focus:outline-none text-xs font-mono-code text-white"
                    >
                    @if ($hasApiKey)
                        <label class="inline-flex items-center gap-2 mt-2 text-[11px] text-rose-300 cursor-pointer">
                            <input type="checkbox" name="clear_api_key" value="1" class="accent-rose-500">
                            <span>Hapus API key tersimpan di database (gunakan fallback <code>.env</code> jika ada)</span>
                        </label>
                    @endif
                </div>

                <div>
                    <label class="block text-xs font-bold text-pink-300 mb-2">Pilih Model Default Google AI</label>
                    <div class="space-y-2">
                        @foreach ($models as $m)
                            <label class="flex items-start justify-between gap-3 p-3.5 rounded-xl border cursor-pointer transition {{ $defaultModel === $m['id'] ? 'bg-pink-500/15 border-pink-500/50' : 'bg-[#0d0b14] border-slate-800 hover:border-slate-700' }}">
                                <div class="flex items-start gap-3">
                                    <input
                                        type="radio"
                                        name="default_model"
                                        value="{{ $m['id'] }}"
                                        {{ $defaultModel === $m['id'] ? 'checked' : '' }}
                                        class="mt-1 accent-pink-500"
                                    >
                                    <div>
                                        <div class="text-xs font-extrabold text-white">{{ $m['name'] }}</div>
                                        <div class="text-[11px] text-slate-400 mt-0.5">{{ $m['description'] }}</div>
                                    </div>
                                </div>
                                <span class="px-2 py-0.5 rounded bg-slate-800 border border-slate-700 text-[10px] font-mono-code text-pink-300 shrink-0">
                                    {{ $m['badge'] }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="pt-2">
                    <button
                        type="submit"
                        class="px-5 py-3 rounded-xl bg-gradient-to-r from-pink-500 via-rose-500 to-amber-400 hover:opacity-95 text-white text-xs font-extrabold shadow-lg shadow-pink-500/20 transition"
                    >
                        💾 Simpan Pengaturan AI Gemini
                    </button>
                </div>
            </form>
        </div>

        <!-- Right: Security & Model Guide -->
        <div class="lg:col-span-5 p-6 rounded-2xl bg-[#151221] border border-slate-800 space-y-4">
            <h2 class="text-sm font-extrabold text-white">🛡️ Kebijakan Model &amp; Keamanan</h2>
            <ul class="space-y-2.5 text-xs text-slate-300 leading-relaxed">
                <li class="p-3 rounded-xl bg-[#0d0b14] border border-slate-800">
                    <strong class="text-emerald-300">100% Google First-Party:</strong> Hanya menggunakan endpoint resmi
                    <code class="font-mono-code text-pink-300">generativelanguage.googleapis.com</code> dengan header <code class="font-mono-code">x-goog-api-key</code>.
                </li>
                <li class="p-3 rounded-xl bg-[#0d0b14] border border-slate-800">
                    <strong class="text-amber-300">Auto-Fallback Cerdas:</strong> Jika model pilihan user terkena limit RPM/RPD (HTTP 429), backend otomatis mencoba model Google cadangan di daftar agar analisa tetap berjalan lancar.
                </li>
                <li class="p-3 rounded-xl bg-[#0d0b14] border border-slate-800">
                    <strong class="text-pink-300">Zero Secret Exposure:</strong> API key tidak disimpan di repository Git dan tidak pernah dikirim ke aplikasi frontend Nuxt.
                </li>
            </ul>
        </div>
    </div>
@endsection
