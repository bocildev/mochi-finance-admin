@extends('layouts.admin')

@section('title', 'Ringkasan & Metrik SaaS')
@section('header_title', '📊 Ringkasan Ekosistem & Pendapatan SaaS')
@section('header_subtitle', 'Pantau pertumbuhan pengguna Free vs Pro, estimasi pendapatan berulang (MRR), dan antrean aktivasi langganan.')

@section('content')
    <!-- 4 SaaS KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-5 rounded-2xl bg-[#151221] border border-pink-500/25 space-y-1.5">
            <div class="flex items-center justify-between text-xs text-pink-300 font-bold">
                <span>Estimasi MRR (Bulanan)</span>
                <span class="px-2 py-0.5 rounded bg-pink-500/15 font-mono-code text-[10px]">PRO REVENUE</span>
            </div>
            <div class="text-2xl font-extrabold text-white">
                Rp {{ number_format($stats['mrr'], 0, ',', '.') }}
            </div>
            <div class="text-[11px] text-slate-400">
                ARR Tahunan: <strong class="text-slate-200">Rp {{ number_format($stats['arr'], 0, ',', '.') }}</strong>
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-[#151221] border border-amber-500/25 space-y-1.5">
            <div class="flex items-center justify-between text-xs text-amber-300 font-bold">
                <span>Pelanggan Mochi PRO ✨</span>
                <span class="px-2 py-0.5 rounded bg-amber-500/15 font-mono-code text-[10px]">{{ $stats['conversionRate'] }}% KONVERSI</span>
            </div>
            <div class="text-2xl font-extrabold text-amber-300">
                {{ $stats['proClients'] }} <span class="text-sm font-semibold text-slate-400">Akun Pro</span>
            </div>
            <div class="text-[11px] text-slate-400">
                Menikmati Cloud Sync & Smart Inbox
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-[#151221] border border-cyan-500/25 space-y-1.5">
            <div class="flex items-center justify-between text-xs text-cyan-300 font-bold">
                <span>Pengguna Free Starter</span>
                <span class="px-2 py-0.5 rounded bg-cyan-500/15 font-mono-code text-[10px]">FREEMIUM</span>
            </div>
            <div class="text-2xl font-extrabold text-cyan-300">
                {{ $stats['freeClients'] }} <span class="text-sm font-semibold text-slate-400">Akun Free</span>
            </div>
            <div class="text-[11px] text-slate-400">
                Total seluruh klien: <strong class="text-slate-200">{{ $stats['totalClients'] }} user</strong>
            </div>
        </div>

        <div class="p-5 rounded-2xl bg-[#151221] border border-emerald-500/25 space-y-1.5">
            <div class="flex items-center justify-between text-xs text-emerald-300 font-bold">
                <span>Permintaan Upgrade Pro</span>
                <span class="px-2 py-0.5 rounded bg-emerald-500/15 font-mono-code text-[10px]">APPROVAL</span>
            </div>
            <div class="text-2xl font-extrabold text-emerald-300">
                {{ $pendingUpgradesCount }} <span class="text-sm font-semibold text-slate-400">Menunggu</span>
            </div>
            <div class="text-[11px] text-slate-400">
                {{ $stats['totalSyncedWallets'] }} dompet & {{ $stats['totalSyncedTx'] }} tx di Cloud
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Tabel Klien Terbaru (7 Cols) -->
        <div class="lg:col-span-7 p-6 rounded-2xl bg-[#151221] border border-slate-800 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-extrabold text-white">👥 Klien Terdaftar Terbaru</h2>
                    <p class="text-xs text-slate-400">Daftar pengguna yang mendaftar atau login melalui IAM-SSO-CORE.</p>
                </div>
                <a href="{{ route('admin.clients') }}" class="px-3 py-1.5 rounded-xl bg-pink-500/15 hover:bg-pink-500/25 text-pink-300 text-xs font-bold transition">
                    Kelola Semua Klien →
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-slate-800 text-slate-400">
                            <th class="py-2.5 pr-3">Klien</th>
                            <th class="py-2.5 px-3">Level Paket</th>
                            <th class="py-2.5 px-3">Data Cloud</th>
                            <th class="py-2.5 pl-3 text-right">Ubah Level Cepat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/70">
                        @forelse ($recentClients as $c)
                            <tr>
                                <td class="py-3 pr-3">
                                    <div class="font-bold text-white">{{ $c->name }}</div>
                                    <div class="text-[11px] text-slate-400">{{ $c->email }}</div>
                                </td>
                                <td class="py-3 px-3">
                                    @if ($c->plan_tier === 'admin')
                                        <span class="px-2.5 py-1 rounded-full bg-amber-500/15 border border-amber-500/30 text-amber-300 text-[10px] font-mono-code font-bold">
                                            👑 OWNER ADMIN
                                        </span>
                                    @elseif ($c->plan_tier === 'pro')
                                        <span class="px-2.5 py-1 rounded-full bg-pink-500/15 border border-pink-500/30 text-pink-300 text-[10px] font-mono-code font-bold">
                                            ✨ MOCHI PRO ({{ strtoupper($c->billing_cycle ?: 'MONTHLY') }})
                                        </span>
                                    @else
                                        <span class="px-2.5 py-1 rounded-full bg-slate-800 border border-slate-700 text-slate-300 text-[10px] font-mono-code font-bold">
                                            FREE STARTER
                                        </span>
                                    @endif
                                </td>
                                <td class="py-3 px-3 font-mono-code text-slate-300">
                                    {{ $c->wallets_count }} dompet · {{ $c->transactions_count }} tx
                                </td>
                                <td class="py-3 pl-3 text-right">
                                    @if ($c->plan_tier !== 'admin')
                                        @if ($c->plan_tier === 'free')
                                            <form method="POST" action="{{ route('admin.clients.tier', $c->sub) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="plan_tier" value="pro">
                                                <input type="hidden" name="billing_cycle" value="monthly">
                                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/25 border border-emerald-500/30 text-emerald-300 text-[11px] font-bold">
                                                    ↑ Upgrade PRO
                                                </button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.clients.tier', $c->sub) }}" class="inline">
                                                @csrf
                                                <input type="hidden" name="plan_tier" value="free">
                                                <button type="submit" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-[11px] font-bold">
                                                    ↓ Set Free
                                                </button>
                                            </form>
                                        @endif
                                    @else
                                        <span class="text-[11px] text-slate-500 font-mono-code">Protected</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-slate-500">Belum ada klien terdaftar.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Antrean Permintaan Upgrade Pro & Webhook IAM (5 Cols) -->
        <div class="lg:col-span-5 space-y-6">
            <div class="p-6 rounded-2xl bg-[#151221] border border-slate-800 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-extrabold text-white">💎 Pengajuan Upgrade Pro Terbaru</h2>
                    <a href="{{ route('admin.upgrades') }}" class="text-xs text-pink-400 hover:underline font-bold">Lihat Semua</a>
                </div>

                <div class="space-y-2.5">
                    @forelse ($recentUpgrades as $up)
                        <div class="p-3.5 rounded-xl bg-[#0d0b14] border border-slate-800 flex items-center justify-between gap-3">
                            <div>
                                <div class="text-xs font-bold text-white">{{ $up->user_name }}</div>
                                <div class="text-[11px] text-slate-400">
                                    {{ strtoupper(str_replace('_', ' ', $up->requested_plan)) }} · Rp {{ number_format((float) $up->amount, 0, ',', '.') }} ({{ $up->payment_method }})
                                </div>
                            </div>
                            @if ($up->status === 'PENDING')
                                <form method="POST" action="{{ route('admin.upgrades.review', $up->id) }}">
                                    @csrf
                                    <input type="hidden" name="action" value="APPROVE">
                                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-emerald-500/20 hover:bg-emerald-500/30 border border-emerald-500/30 text-emerald-300 text-xs font-bold">
                                        ✓ Aktifkan Pro
                                    </button>
                                </form>
                            @else
                                <span class="px-2 py-0.5 rounded text-[10px] font-mono-code font-bold {{ $up->status === 'APPROVED' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-rose-500/15 text-rose-300' }}">
                                    {{ $up->status }}
                                </span>
                            @endif
                        </div>
                    @empty
                        <div class="text-xs text-slate-500 py-4 text-center">
                            Belum ada pengajuan upgrade Pro dari pengguna Free.
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="p-6 rounded-2xl bg-[#151221] border border-slate-800 space-y-3">
                <h2 class="text-sm font-extrabold text-white">🔔 Log Sinkronisasi Webhook IAM</h2>
                <div class="space-y-2">
                    @forelse ($webhookEvents as $ev)
                        <div class="p-3 rounded-xl bg-[#0d0b14] border border-slate-800 flex items-center justify-between text-xs">
                            <span class="font-mono-code text-cyan-300">{{ $ev->event_type }}</span>
                            <span class="font-mono-code text-slate-500 text-[11px]">{{ $ev->processed_at }}</span>
                        </div>
                    @empty
                        <div class="text-xs text-slate-500 py-2">Sinkronisasi real-time via HMAC `X-IAM-Signature` siap menerima event.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
