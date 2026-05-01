<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasang security headers global ke semua response. Header-nya:
 *
 * - X-Frame-Options       : block clickjacking (deny iframe lintas origin).
 * - X-Content-Type-Options: cegah MIME sniffing.
 * - Referrer-Policy       : kirim referrer hanya untuk origin yang sama (HTTPS).
 * - Permissions-Policy    : matikan API browser yang gak dipakai (geo, mic, dll.).
 * - X-XSS-Protection      : legacy XSS filter (untuk browser tua).
 * - Strict-Transport-Security : HSTS, paksa HTTPS 1 tahun (kalau koneksi sudah HTTPS).
 * - Content-Security-Policy : whitelist source script/style/font/img — block
 *   inline script kecuali dari origin yang dipercaya. Pakai `report-only`
 *   default supaya tidak break Filament/Tailwind CDN; admin bisa enforce
 *   penuh setelah verify lewat env CSP_ENFORCE=true.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=()',
            'X-XSS-Protection' => '1; mode=block',
        ];

        foreach ($headers as $key => $value) {
            if (! $response->headers->has($key)) {
                $response->headers->set($key, $value);
            }
        }

        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // CSP: whitelist Tailwind CDN + self. 'unsafe-inline' di style/script
        // masih dibutuhkan oleh Filament 5 (Alpine.js) & beberapa inline event
        // handler — opsi yang lebih ketat (nonce-based) bisa di-enable nanti.
        $cspHeader = (bool) env('CSP_ENFORCE', false)
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        if (! $response->headers->has($cspHeader) && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set($cspHeader, $this->buildCsp());
        }

        return $response;
    }

    protected function buildCsp(): string
    {
        $directives = [
            "default-src 'self'",
            "img-src 'self' data: https: blob:",
            "font-src 'self' https://fonts.bunny.net https://fonts.gstatic.com data:",
            "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://fonts.bunny.net https://fonts.googleapis.com",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com",
            "connect-src 'self' https://api.telegram.org https://app.pakasir.com",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }
}
