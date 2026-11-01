@extends('layouts.admin')

@section('title', 'Permintaan Upgrade Pro')
@section('header_title', '💎 Antrean Permintaan Upgrade ke Mochi Pro ✨')
@section('header_subtitle', 'Verifikasi dan aktifkan pengajuan langganan Pro dari pengguna Free Starter.')

@section('content')
    <div class="flex items-center gap-2">
        @foreach (['ALL' => 'Semua Status', 'PENDING' => '⏳ Menunggu Persetujuan', 'APPROVED' => '✓ Disetujui', 'REJECTED' => '✕ Ditolak'] as $st => $label)
            <a
                href="{{ route('admin.upgrades', ['status' => $st]) }}"
                class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition {{ $statusFilter === $st ? 'bg-pink-500 text-white' : 'bg-[#151221] text-slate-400 hover:text-white border border-slate-800' }}"
            >
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="p-6 rounded-2xl bg-[#151221] border border-slate-800 overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="border-b border-slate-800 text-slate-400">
                    <th class="py-3 pr-4">Waktu Pengajuan</th>
                    <th class="py-3 px-4">Klien</th>
                    <th class="py-3 px-4">Paket & Nominal</th>
                    <th class="py-3 px-4">Metode & Catatan</th>
                    <th class="py-3 px-4">Status</th>
                    <th class="py-3 pl-4 text-right">Keputusan Admin</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800/70">
                @forelse ($requests as $r)
                    <tr>
                        <td class="py-4 pr-4 font-mono-code text-slate-400">
                            {{ $r->created_at }}
                        </td>
                        <td class="py-4 px-4">
                            <div class="font-bold text-white">{{ $r->user_name }}</div>
                            <div class="text-slate-400">{{ $r->user_email }}</div>
                        </td>
                        <td class="py-4 px-4">
                            <div class="font-extrabold text-pink-300 uppercase font-mono-code">{{ str_replace('_', ' ', $r->requested_plan) }}</div>
                            <div class="text-emerald-300 font-bold">Rp {{ number_format((float) $r->amount, 0, ',', '.') }}</div>
                        </td>
                        <td class="py-4 px-4">
                            <span class="px-2 py-0.5 rounded bg-purple-500/15 text-purple-300 font-mono-code font-bold text-[10px]">{{ $r->payment_method }}</span>
                            <div class="text-slate-400 mt-1">{{ $r->notes ?: '-' }}</div>
                        </td>
                        <td class="py-4 px-4">
                            <span class="px-2.5 py-1 rounded-full text-[10px] font-mono-code font-bold {{ $r->status === 'PENDING' ? 'bg-amber-500/15 text-amber-300' : ($r->status === 'APPROVED' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-rose-500/15 text-rose-300') }}">
                                {{ $r->status }}
                            </span>
                        </td>
                        <td class="py-4 pl-4 text-right">
                            @if ($r->status === 'PENDING')
                                <div class="inline-flex items-center gap-2">
                                    <form method="POST" action="{{ route('admin.upgrades.review', $r->id) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="APPROVE">
                                        <button type="submit" class="px-3 py-1.5 rounded-xl bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs transition">
                                            ✓ Setujui & Aktifkan Pro
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.upgrades.review', $r->id) }}">
                                        @csrf
                                        <input type="hidden" name="action" value="REJECT">
                                        <button type="submit" class="px-3 py-1.5 rounded-xl bg-rose-500/20 hover:bg-rose-500/30 border border-rose-500/30 text-rose-300 font-bold text-xs transition">
                                            ✕ Tolak
                                        </button>
                                    </form>
                                </div>
                            @else
                                <span class="text-[11px] text-slate-500 font-mono-code">
                                    Oleh {{ $r->reviewed_by ?: 'Admin' }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center text-slate-500">
                            Belum ada permintaan upgrade Pro. Saat pengguna Free mengklik tombol "Upgrade ke Mochi Pro" di PWA Nuxt, datanya akan muncul di sini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
