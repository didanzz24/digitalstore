<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TelegramBotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Brute-force / anomalous activity detector. Dipanggil dari AuthController
 * tiap kali login failed; kalau threshold tercapai, log alert + kirim
 * notifikasi Telegram ke admin.
 *
 * Counter disimpan di cache (TTL = window) supaya tidak perlu query
 * audit_logs setiap login attempt.
 */
class SecurityMonitor
{
    /** Window deteksi (menit). */
    public const WINDOW_MINUTES = 10;

    /** Threshold per-IP per window (semua user). */
    public const IP_THRESHOLD = 10;

    /** Threshold per-email per window. */
    public const EMAIL_THRESHOLD = 5;

    /** Threshold khusus admin login fail per window. */
    public const ADMIN_THRESHOLD = 3;

    /**
     * Catat 1 failed login attempt + cek apakah threshold tercapai. Caller
     * cukup panggil ini di AuthController::login (cabang gagal).
     *
     * Return true kalau alert dipicu (untuk testing).
     */
    public static function recordFailedLogin(string $email, string $ip): bool
    {
        $window = self::WINDOW_MINUTES * 60;

        $ipKey = self::ipKey($ip);
        $emailKey = self::emailKey($email);

        $ipCount = (int) Cache::get($ipKey, 0) + 1;
        $emailCount = (int) Cache::get($emailKey, 0) + 1;

        Cache::put($ipKey, $ipCount, $window);
        Cache::put($emailKey, $emailCount, $window);

        // Audit log normal (selalu, untuk forensic) — tanpa password tentu saja.
        Audit::log('auth.login.failed', null, [
            'email' => self::maskEmail($email),
            'ip_count_window' => $ipCount,
            'email_count_window' => $emailCount,
        ]);

        $alerted = false;
        if ($emailCount === self::EMAIL_THRESHOLD) {
            self::alert(
                'auth.bruteforce.email',
                '🔐 Brute-force suspect: email <code>'.self::maskEmail($email)."</code> gagal login {$emailCount}× dalam ".self::WINDOW_MINUTES." menit dari IP <code>{$ip}</code>.",
                ['email' => self::maskEmail($email), 'count' => $emailCount, 'ip' => $ip],
            );
            $alerted = true;
        }
        if ($ipCount === self::IP_THRESHOLD) {
            self::alert(
                'auth.bruteforce.ip',
                "🚨 Brute-force suspect: IP <code>{$ip}</code> gagal login {$ipCount}× dalam ".self::WINDOW_MINUTES.' menit (lintas akun).',
                ['ip' => $ip, 'count' => $ipCount],
            );
            $alerted = true;
        }

        // Khusus admin: threshold lebih ketat. Cek apakah email pernah
        // terdaftar sebagai admin — kalau ya, alert lebih cepat.
        if ($emailCount >= self::ADMIN_THRESHOLD && self::isAdminEmail($email)) {
            // Pakai cache flag supaya tidak spam alert per attempt.
            $adminFlag = "sec:admin_alerted:{$email}";
            if (! Cache::has($adminFlag)) {
                Cache::put($adminFlag, 1, $window);
                self::alert(
                    'auth.bruteforce.admin',
                    '🚨🚨 ADMIN brute-force suspect: email admin <code>'.self::maskEmail($email)."</code> gagal login {$emailCount}× dalam ".self::WINDOW_MINUTES." menit dari IP <code>{$ip}</code>. Cek/segera ganti password.",
                    ['email' => self::maskEmail($email), 'count' => $emailCount, 'ip' => $ip],
                );
                $alerted = true;
            }
        }

        return $alerted;
    }

    /** Reset counter saat login sukses (supaya gak ke-trigger setelah series of failed→success). */
    public static function clearFailedCounters(string $email, string $ip): void
    {
        Cache::forget(self::emailKey($email));
        Cache::forget(self::ipKey($ip));
    }

    /**
     * Statistik untuk dashboard admin.
     *
     * @return array<string,mixed>
     */
    public static function recentStats(int $hours = 24): array
    {
        $since = Carbon::now()->subHours($hours);

        $base = AuditLog::query()->where('created_at', '>=', $since);

        $failed = (clone $base)->where('event', 'auth.login.failed')->count();
        $success = (clone $base)->where('event', 'auth.login.success')->count();
        $alerts = (clone $base)
            ->where('event', 'like', 'security.alert.%')
            ->orWhere('event', 'like', 'auth.bruteforce.%')
            ->where('created_at', '>=', $since)
            ->count();

        $topIps = (clone $base)
            ->where('event', 'auth.login.failed')
            ->whereNotNull('ip_address')
            ->selectRaw('ip_address, COUNT(*) as c')
            ->groupBy('ip_address')
            ->orderByDesc('c')
            ->limit(5)
            ->pluck('c', 'ip_address')
            ->all();

        return [
            'window_hours' => $hours,
            'login_success' => $success,
            'login_failed' => $failed,
            'alerts' => $alerts,
            'top_failed_ips' => $topIps,
        ];
    }

    /** Kirim alert: log + notify admin via Telegram (best-effort). */
    protected static function alert(string $event, string $messageHtml, array $context): void
    {
        Audit::log('security.alert.'.$event, null, $context);

        try {
            app(TelegramBotService::class)->notifyAdmin($messageHtml);
        } catch (Throwable $e) {
            // Notifikasi best-effort; jangan ganggu request user.
            report($e);
        }
    }

    protected static function ipKey(string $ip): string
    {
        return 'sec:fail_login:ip:'.md5($ip);
    }

    protected static function emailKey(string $email): string
    {
        return 'sec:fail_login:email:'.md5(strtolower($email));
    }

    protected static function isAdminEmail(string $email): bool
    {
        try {
            return User::where('email', $email)->where('is_admin', true)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** Mask email untuk log (privacy): bu**@d**.com */
    public static function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);
        $maskLocal = strlen($local) <= 2
            ? str_repeat('*', strlen($local))
            : substr($local, 0, 2).str_repeat('*', max(1, strlen($local) - 2));
        $domainParts = explode('.', $domain);
        $first = $domainParts[0] ?? '';
        $maskFirst = strlen($first) <= 1
            ? str_repeat('*', strlen($first))
            : substr($first, 0, 1).str_repeat('*', max(1, strlen($first) - 1));
        $rest = count($domainParts) > 1 ? '.'.implode('.', array_slice($domainParts, 1)) : '';

        return $maskLocal.'@'.$maskFirst.$rest;
    }
}
