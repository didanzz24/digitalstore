@extends('layouts.app')

@section('title', 'Toko Sedang Tidak Aktif')

@section('content')
<section class="py-16 md:py-24">
    <div class="max-w-xl mx-auto px-4 text-center">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-amber-100 text-amber-700 text-3xl">
            🛠️
        </div>
        <h1 class="mt-6 text-3xl md:text-4xl font-extrabold tracking-tight text-slate-900">
            Toko Sedang Tidak Aktif
        </h1>
        <p class="mt-3 text-slate-600">
            {{ $message }}
        </p>

        @if (! empty($site->telegram_url))
            <a href="{{ $site->telegram_url }}"
               class="mt-6 inline-flex items-center gap-2 rounded-xl bg-sky-500 hover:bg-sky-600 text-white font-bold px-6 py-3">
                Buka Bot Telegram
            </a>
        @endif

        @auth
            <div class="mt-4 text-sm">
                <a href="{{ route('account.index') }}" class="text-slate-500 hover:text-slate-700 underline">
                    Buka Akun Saya
                </a>
            </div>
        @endauth
    </div>
</section>
@endsection
