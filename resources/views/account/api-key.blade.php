@extends('layouts.app')

@section('title', 'API Key — '.$site->store_name)

@section('content')
@section('account_content')
    <div class="rounded-2xl bg-gradient-to-br from-violet-700 to-indigo-700 text-white p-6 md:p-8 mb-6 shadow-card">
        <p class="text-sm text-white/60">Membership API</p>
        <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight mt-1">Kelola API Key</h1>
        <p class="text-sm text-white/70 mt-1">
            Aktif sampai
            <span class="font-bold text-white">{{ $user->member_expires_at?->format('d M Y H:i') ?? '—' }}</span>
            • Durasi paket {{ $durationDays }} hari.
        </p>
    </div>

    @if (! empty($newRawKey))
        <div class="mb-6 rounded-2xl bg-amber-50 border border-amber-200 p-5">
            <p class="text-amber-900 text-sm font-bold">Salin API Key sekarang juga — key tidak akan ditampilkan lagi.</p>
            <div class="mt-3 flex items-center gap-2">
                <input
                    type="text"
                    value="{{ $newRawKey }}"
                    readonly
                    class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 font-mono text-sm"
                    onclick="this.select();"
                >
                <button
                    type="button"
                    class="rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-bold px-4 py-2 text-sm whitespace-nowrap"
                    onclick="navigator.clipboard.writeText('{{ $newRawKey }}'); this.textContent='Tersalin!';"
                >Copy</button>
            </div>
        </div>
    @endif

    <div class="grid md:grid-cols-2 gap-4 mb-6">
        <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-card">
            <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Base URL</p>
            <div class="mt-2 flex items-center gap-2">
                <input type="text" value="{{ $baseUrl }}" readonly class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm" onclick="this.select();">
                <button type="button" onclick="navigator.clipboard.writeText('{{ $baseUrl }}'); this.textContent='Tersalin!';" class="rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold px-3 py-2 text-xs whitespace-nowrap">Copy</button>
            </div>
            <p class="mt-2 text-xs text-slate-500">Header auth: <code class="bg-slate-100 px-1.5 py-0.5 rounded">X-API-KEY: ak_…</code></p>
        </div>

        <div class="rounded-2xl bg-white border border-slate-200 p-5 shadow-card">
            <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">Dokumentasi API</p>
            <a href="{{ $docsUrl }}" target="_blank" class="mt-2 inline-flex items-center gap-1.5 text-violet-600 hover:text-violet-800 font-bold text-sm">
                Buka /api-docs
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
            </a>
            <p class="mt-2 text-xs text-slate-500">Endpoint produk, kategori, contoh curl, error code.</p>
        </div>
    </div>

    <div class="rounded-2xl bg-white border border-slate-200 p-6 shadow-card">
        <div class="flex items-start justify-between gap-4 flex-wrap">
            <div class="min-w-0">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">API Key Kamu</p>
                @if ($client)
                    <p class="mt-1 font-mono text-sm text-slate-700 break-all">
                        {{ $client->api_key_prefix }}{{ str_repeat('•', 24) }}
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        Status:
                        @if ($client->is_active)
                            <span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-[10px] font-bold">AKTIF</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 text-[10px] font-bold">NONAKTIF</span>
                        @endif
                        • Rate Limit: <b>{{ $client->rate_limit_per_minute }}</b> req/menit
                        @if ($client->last_used_at)
                            • Terakhir dipakai {{ $client->last_used_at->diffForHumans() }}
                        @endif
                    </p>
                @else
                    <p class="mt-1 text-sm text-slate-500">Belum punya API key. Klik tombol di samping untuk generate.</p>
                @endif
            </div>

            <form method="POST" action="{{ route('account.api-key.generate') }}">
                @csrf
                <button
                    type="submit"
                    onclick="return confirm('{{ $client ? 'Generate ulang akan menonaktifkan key lama. Lanjut?' : 'Generate API key baru?' }}');"
                    class="rounded-xl btn-brand font-bold px-5 py-2.5 text-sm whitespace-nowrap"
                >
                    {{ $client ? 'Regenerate Key' : 'Generate Key Baru' }}
                </button>
            </form>
        </div>

        @if ($client && ! empty($client->allowed_ips))
            <div class="mt-4 pt-4 border-t border-slate-100">
                <p class="text-xs uppercase tracking-wider text-slate-500 font-bold">IP Whitelist</p>
                <pre class="mt-2 bg-slate-50 rounded-lg p-3 text-xs text-slate-700 font-mono overflow-x-auto">{{ $client->allowed_ips }}</pre>
                <p class="text-xs text-slate-400 mt-1">Hanya IP di atas yang boleh hit API. Hubungi admin untuk perubahan.</p>
            </div>
        @endif
    </div>

    <p class="mt-4 text-xs text-slate-400 text-center">
        Akses API key otomatis nonaktif kalau membership expired. Perpanjang via halaman <a href="{{ route('membership.show') }}" class="underline">Membership</a>.
    </p>
@endsection

@include('account._layout')
@endsection
