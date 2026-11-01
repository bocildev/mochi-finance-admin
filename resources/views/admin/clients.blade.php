@extends('layouts.admin')

@section('title', 'Manajemen Klien & Langganan')
@section('header_title', '👥 Manajemen Klien & Hak Akses Level (Free vs Pro)')
@section('header_subtitle', 'Kelola seluruh pengguna terdaftar Mochi Finance, ubah status paket Free Starter / Mochi Pro, dan sinkronkan role otomatis ke IAM-SSO-CORE.')

@section('content')
    <!-- Filter & Search Bar -->
    <div class="p-4 rounded-2xl bg-[#151221] border border-slate-800 flex flex-wrap items-center justify-between gap-4">
        <div class="flex flex-wrap items-center gap-2">
            @php
                $tabs = [
                    'all' => 'Semua Klien',
                    'pro' => '✨ Mochi Pro',
                    'free' => 'Free Starter',
                    'admin' => '👑 Platform Admin',
                    'suspended' => '🚫 Suspended',
                ];
            @endphp
            @foreach ($tabs as $key => $label)
                <a
                    href="{{ route('admin.clients', ['tier' => $key, 'q' => $search]) }}"
                    class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition {{ $tierFilter === $key ? 'bg-pink-500 text-white shadow-md shadow-pink-500/20' : 'bg-[#0d0b14] text-slate-400 hover:text-white border border-slate-800' }}"
                >
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('admin.clients') }}" class="flex items-center gap-2">
            <input type="hidden" name="tier" value="{{ $tierFilter }}">
            <input
                type="text"
                name="q"
                value="{{ $search }}"
                placeholder="Cari nama, email, atau sub..."
                class="bg-[#0d0b14] border border-slate-700 rounded-xl px-3.5 py-1.5 text-xs text-white w-64"
            >
            <button type="submit" class="px-3.5 py-1.5 rounded-xl bg-pink-500/20 border border-pink-500/30 text-pink-300 text-xs font-bold">
                Cari
            </button>
        </form>
    </div>

    <!-- Client Table -->
    <div class="p-6 rounded-2xl bg-[#151221] border border-slate-800 overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-slate-800 text-slate-400">
                    <th class="py-3 pr-4">Profil Klien & OIDC Sub</th>
                    <th class="py-3 px-4">Level Paket Saat Ini</th>
                    <th class="py-3 px-4">Masa Aktif Pro</th>
                    <th class="py-3 px-4">Pemakaian Cloud</th>
                    <th class="py-3 px-4">Status Akun</th>
                    <th class="py-3 pl-4 text-right">Tindakan Admin</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/70">
                @forelse ($clients as $c)
                    <tr class="hover:bg-slate-900/40 transition">
                        <td class="py-4 pr-4">
                            <div class="font-extrabold text-white text-sm">{{ $c->name }}</div>
                            <div class="text-slate-400">{{ $c->email }}</div>
                            <div class="text-[10px] font-mono-code text-slate-500 mt-0.5">sub: {{ $c->sub }}</div>
                        </td>

                        <td class="py-4 px-4">
                            @if ($c->plan_tier === 'admin')
                                <span class="px-2.5 py-1 rounded-full bg-amber-500/15 border border-amber-500/30 text-amber-300 text-[11px] font-mono-code font-bold">
                                    👑 PLATFORM ADMIN
                                </span>
                            @elseif ($c->plan_tier === 'pro')
                                <span class="px-2.5 py-1 rounded-full bg-pink-500/15 border border-pink-500/30 text-pink-300 text-[11px] font-mono-code font-bold">
                                    ✨ MOCHI PRO ({{ strtoupper($c->billing_cycle ?: 'MONTHLY') }})
                                </span>
                            @else
                                <span class="px-2.5 py-1 rounded-full bg-slate-800 border border-slate-700 text-slate-300 text-[11px] font-mono-code font-bold">
                                    FREE STARTER
                                </span>
                            @endif
                        </td>

                        <td class="py-4 px-4 font-mono-code text-slate-300">
                            @if ($c->plan_tier === 'admin' || $c->billing_cycle === 'lifetime')
                                <span class="text-emerald-300 font-bold">Lifetime</span>
                            @elseif ($c->plan_tier === 'pro' && $c->pro_expires_at)
                                <span>s/d {{ \Illuminate\Support\Str::limit($c->pro_expires_at, 10, '') }}</span>
                            @else
                                <span class="text-slate-500">—</span>
                            @endif
                        </td>

                        <td class="py-4 px-4 font-mono-code text-slate-300">
                            <div>{{ $c->wallets_count }} dompet · {{ $c->transactions_count }} transaksi</div>
                            <div class="text-[11px] text-slate-500">
                                Sync: {{ $c->last_sync_at ? \Illuminate\Support\Str::limit($c->last_sync_at, 16, '') : 'Belum sync' }}
                            </div>
                        </td>

                        <td class="py-4 px-4">
                            @if ($c->status === 'active')
                                <span class="px-2 py-0.5 rounded bg-emerald-500/15 text-emerald-300 text-[10px] font-mono-code font-bold">
                                    ACTIVE
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded bg-rose-500/15 text-rose-300 text-[10px] font-mono-code font-bold">
                                    SUSPENDED
                                </span>
                            @endif
                        </td>

                        <td class="py-4 pl-4 text-right">
                            @if ($c->plan_tier !== 'admin')
                                <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                    <form method="POST" action="{{ route('admin.clients.tier', $c->sub) }}" class="inline-flex items-center gap-1.5">
                                        @csrf
                                        <select name="plan_tier" class="bg-[#0d0b14] border border-slate-700 rounded-lg px-2 py-1 text-[11px] text-white font-semibold">
                                            <option value="free" @selected($c->plan_tier === 'free')>Free Starter</option>
                                            <option value="pro" @selected($c->plan_tier === 'pro')>Mochi PRO ✨</option>
                                        </select>
                                        <select name="billing_cycle" class="bg-[#0d0b14] border border-slate-700 rounded-lg px-2 py-1 text-[11px] text-white font-semibold">
                                            <option value="monthly" @selected($c->billing_cycle === 'monthly')>1 Bulan</option>
                                            <option value="yearly" @selected($c->billing_cycle === 'yearly')>1 Tahun</option>
                                            <option value="lifetime" @selected($c->billing_cycle === 'lifetime')>Lifetime</option>
                                        </select>
                                        <button type="submit" class="px-2.5 py-1 rounded-lg bg-pink-500 hover:bg-pink-600 text-white text-[11px] font-bold transition">
                                            Simpan Level
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.clients.status', $c->sub) }}" class="inline">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="px-2.5 py-1 rounded-lg text-[11px] font-bold border transition {{ $c->status === 'active' ? 'bg-rose-500/10 border-rose-500/30 text-rose-300 hover:bg-rose-500/20' : 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300 hover:bg-emerald-500/20' }}"
                                        >
                                            {{ $c->status === 'active' ? 'Suspend' : 'Aktifkan' }}
                                        </button>
                                    </form>
                                </div>
                            @else
                                <span class="text-[11px] text-amber-300/80 font-mono-code">Owner Account</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center text-slate-500">
                            Tidak ada data klien yang sesuai dengan filter pencarian.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
