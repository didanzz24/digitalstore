<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block akses ke halaman front store (homepage + browse produk + halaman
 * info publik) saat admin men-non-aktifkan storefront.
 *
 * Halaman yang TETAP boleh diakses meski storefront OFF:
 *  - /invoice/*  (user buka invoice untuk bayar order — bot Telegram
 *                kasih link invoice ke customer).
 *  - /checkout/* (lanjutan dari bot/order yang sudah dibuat).
 *  - /webhooks/* (gateway/integrasi).
 *  - /admin/*    (panel Filament).
 *  - /login, /register, /lupa-password, dst.
 */
class StorefrontEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $site = SiteSetting::current();

        if ($site->storefront_enabled ?? true) {
            return $next($request);
        }

        $message = 'Toko sedang dinonaktifkan sementara oleh admin. '
            .'Pemesanan baru saat ini hanya tersedia via bot Telegram.';

        if ($request->expectsJson()) {
            return response()->json(['error' => $message], 503);
        }

        return response()->view('storefront-offline', [
            'site' => $site,
            'message' => $message,
        ], 503);
    }
}
