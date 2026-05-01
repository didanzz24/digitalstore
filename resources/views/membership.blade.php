@extends('layouts.app')

@section('title', $label.' — Akses API Key')

@section('content')
<section class="py-10 md:py-14">
    <div class="max-w-2xl mx-auto px-4">
        <div class="text-center">
            <span class="inline-block px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-xs font-bold uppercase tracking-wider">Membership API</span>
            <h1 class="mt-3 text-3xl md:text-4xl font-extrabold tracking-tight text-slate-900">{{ $label }}</h1>
            <p class="mt-2 text-slate-500">
                Bayar sekali untuk membuka akses generate API key dan integrasi pihak ke-3.
            </p>
            <p class="mt-1 text-slate-500">
                Rp <span class="font-bold text-slate-900">{{ number_format($price, 0, ',', '.') }}</span>
                <span class="text-sm">/ {{ $durationDays }} hari</span>
            </p>
        </div>

        <div class="mt-8 bg-white rounded-2xl border border-slate-200 p-6 shadow-card">
            @if (session('success'))
                <div class="rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm mb-4">{{ session('success') }}</div>
            @endif
            @if (session('info'))
                <div class="rounded-xl bg-sky-50 border border-sky-200 text-sky-800 px-4 py-3 text-sm mb-4">{{ session('info') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-4 py-3 text-sm mb-4">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @if (! empty($site->membership_benefits_html))
                <div class="prose prose-sm max-w-none">
                    {!! $site->membership_benefits_html !!}
                </div>
            @else
                <ul class="space-y-2 text-sm text-slate-700">
                    <li>✅ <b>Generate API Key sendiri</b> — 1 key per akun, bisa di-rotate kapan saja.</li>
                    <li>✅ <b>Akses /api/v1/*</b> — endpoint produk, kategori, stok untuk integrasi.</li>
                    <li>✅ <b>Dokumentasi Lengkap</b> — base URL, contoh curl, error code.</li>
                    <li>✅ <b>Rate Limit & IP Whitelist</b> — diatur admin per akun.</li>
                </ul>
            @endif

            @auth
                @php $u = auth()->user(); @endphp
                @if ($u->is_member && $u->member_expires_at && $u->member_expires_at->isFuture())
                    <div class="mt-6 rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
                        Akses API key kamu <b>aktif</b> sampai {{ $u->member_expires_at->format('d M Y H:i') }}.
                        Pembayaran berikutnya akan memperpanjang masa aktif.
                    </div>
                    <a href="{{ route('account.api-key') }}" class="mt-4 inline-flex items-center justify-center w-full rounded-xl btn-brand font-extrabold py-3 text-base">
                        Buka Halaman API Key
                    </a>
                @endif

                <form method="POST" action="{{ route('membership.subscribe') }}" class="mt-6 space-y-4">
                    @csrf
                    @include('_partials.gateway-selector', [
                        'availableGateways' => $gateways ?? [],
                        'defaultGateway' => $defaultGateway ?? null,
                        'walletEligible' => true,
                        'walletBalance' => (int) $u->balance,
                        'walletAmountRequired' => (int) $price,
                        'walletTopupEnabled' => (bool) ($site->wallet_topup_enabled ?? true),
                    ])

                    <button type="submit" class="w-full rounded-xl btn-brand font-extrabold py-3 text-base">
                        Bayar &amp; Buka Akses API Key
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" class="mt-6 inline-block rounded-xl btn-brand font-extrabold py-3 px-6 text-base">
                    Login dulu untuk Buka Akses API Key
                </a>
            @endauth
        </div>
    </div>
</section>
@endsection
