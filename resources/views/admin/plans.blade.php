@extends('layouts.admin')

@section('title', 'Paket Harga & Limit Fitur')
@section('header_title', '⚙️ Konfigurasi Paket Langganan & Feature Gate (Free vs Pro)')
@section('header_subtitle', 'Atur batasan kuota dompet/anggaran/celengan serta hak akses fitur untuk level Gratisan dan Pro. Perubahan langsung dibaca oleh PWA Nuxt.')

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        @foreach ($plans as $plan)
            <form method="POST" action="{{ route('admin.plans.update', $plan->key) }}" class="p-6 rounded-3xl bg-[#151221] border {{ $plan->key === 'pro' ? 'border-pink-500/40 shadow-xl shadow-pink-500/5' : 'border-slate-800' }} space-y-5">
                @csrf
                <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                    <div>
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-mono-code font-bold {{ $plan->key === 'pro' ? 'bg-pink-500/20 text-pink-300 border border-pink-500/30' : 'bg-slate-800 text-slate-300' }}">
                            LEVEL: {{ strtoupper($plan->key) }}
                        </span>
                        <h2 class="text-lg font-extrabold text-white mt-2">{{ $plan->name }}</h2>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-slate-400">Harga Bulanan</div>
                        <div class="text-xl font-extrabold {{ $plan->key === 'pro' ? 'text-pink-400' : 'text-slate-200' }}">
                            Rp {{ number_format((float) $plan->monthly_price, 0, ',', '.') }}
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block text-slate-400 mb-1 font-semibold">Nama Paket</label>
                        <input type="text" name="name" value="{{ $plan->name }}" required class="w-full bg-[#0d0b14] border border-slate-700 rounded-xl px-3 py-2 text-white font-semibold">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1 font-semibold">Label Badge di PWA</label>
                        <input type="text" name="badge" value="{{ $plan->badge }}" required class="w-full bg-[#0d0b14] border border-slate-700 rounded-xl px-3 py-2 text-white font-mono-code">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 text-xs">
                    <div>
                        <label class="block text-slate-400 mb-1 font-semibold">Harga Bulanan (Rp)</label>
                        <input type="number" name="monthly_price" value="{{ (int) $plan->monthly_price }}" required class="w-full bg-[#0d0b14] border border-slate-700 rounded-xl px-3 py-2 text-white">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1 font-semibold">Harga Tahunan (Rp)</label>
                        <input type="number" name="yearly_price" value="{{ (int) $plan->yearly_price }}" required class="w-full bg-[#0d0b14] border border-slate-700 rounded-xl px-3 py-2 text-white">
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-[#0d0b14] border border-slate-800 space-y-3 text-xs">
                    <div class="font-bold text-slate-300">Kuota Maksimum (Isi <code class="text-pink-300">-1</code> untuk Unlimited)</div>
                    <div class="grid grid-cols-3 gap-3">
                        <div>
                            <label class="block text-slate-400 mb-1">Maks. Dompet</label>
                            <input type="number" name="max_wallets" value="{{ $plan->max_wallets }}" required class="w-full bg-[#151221] border border-slate-700 rounded-xl px-3 py-2 text-white font-mono-code">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">Maks. Anggaran</label>
                            <input type="number" name="max_budgets" value="{{ $plan->max_budgets }}" required class="w-full bg-[#151221] border border-slate-700 rounded-xl px-3 py-2 text-white font-mono-code">
                        </div>
                        <div>
                            <label class="block text-slate-400 mb-1">Maks. Celengan</label>
                            <input type="number" name="max_goals" value="{{ $plan->max_goals }}" required class="w-full bg-[#151221] border border-slate-700 rounded-xl px-3 py-2 text-white font-mono-code">
                        </div>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-[#0d0b14] border border-slate-800 space-y-2.5 text-xs">
                    <div class="font-bold text-slate-300 mb-1">Hak Akses Fitur (Feature Gates)</div>

                    <label class="flex items-center justify-between py-1 cursor-pointer">
                        <span class="text-slate-300">☁️ Cloud Sync & Multi-Device Backup (`finance.sync`)</span>
                        <input type="checkbox" name="allow_cloud_sync" value="1" @checked($plan->allow_cloud_sync) class="w-4 h-4 accent-pink-500">
                    </label>

                    <label class="flex items-center justify-between py-1 cursor-pointer">
                        <span class="text-slate-300">📲 Smart Inbox Otomatis Mutasi Bank/E-Wallet (`finance.smart_inbox`)</span>
                        <input type="checkbox" name="allow_smart_inbox" value="1" @checked($plan->allow_smart_inbox) class="w-4 h-4 accent-pink-500">
                    </label>

                    <label class="flex items-center justify-between py-1 cursor-pointer">
                        <span class="text-slate-300">📊 Grafik & Analitik Cashflow Mendalam (`finance.analytics`)</span>
                        <input type="checkbox" name="allow_analytics" value="1" @checked($plan->allow_analytics) class="w-4 h-4 accent-pink-500">
                    </label>

                    <label class="flex items-center justify-between py-1 cursor-pointer">
                        <span class="text-slate-300">📥 Ekspor Laporan CSV Excel (`finance.export`)</span>
                        <input type="checkbox" name="allow_csv_export" value="1" @checked($plan->allow_csv_export) class="w-4 h-4 accent-pink-500">
                    </label>
                </div>

                <button type="submit" class="w-full py-3 rounded-2xl font-extrabold text-xs transition {{ $plan->key === 'pro' ? 'bg-gradient-to-r from-pink-500 to-purple-600 text-white shadow-lg shadow-pink-500/20' : 'bg-slate-800 hover:bg-slate-700 text-slate-200' }}">
                    Simpan Konfigurasi Paket {{ strtoupper($plan->key) }}
                </button>
            </form>
        @endforeach
    </div>
@endsection
