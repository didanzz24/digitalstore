<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use App\Support\SecurityMonitor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Listener tunggal untuk semua event auth Laravel (digunakan oleh AuthController
 * user-facing maupun Filament admin login). Mencatat audit log + memicu
 * SecurityMonitor untuk failed login.
 *
 * Static flags memastikan event yang dipicu lebih dari sekali per request
 * (mis. Auth::attempt + Auth::login internal Filament) hanya menulis 1 entry.
 */
class AuthAuditListener
{
    private static bool $loginLogged = false;

    private static bool $logoutLogged = false;

    public function handleLogin(Login $event): void
    {
        if (self::$loginLogged) {
            return;
        }
        $user = $event->user instanceof User ? $event->user : null;
        if (! $user) {
            return;
        }
        self::$loginLogged = true;

        $isAdmin = (bool) ($user->is_admin ?? false);
        $event_name = $isAdmin ? 'auth.admin.login.success' : 'auth.login.success';

        Audit::log($event_name, $user, [
            'guard' => $event->guard,
            'remember' => (bool) $event->remember,
            'email' => SecurityMonitor::maskEmail((string) $user->email),
        ]);

        SecurityMonitor::clearFailedCounters((string) $user->email, request()->ip() ?? '');
    }

    public function handleFailed(Failed $event): void
    {
        $email = (string) ($event->credentials['email'] ?? '');
        if ($email === '') {
            return;
        }

        // SecurityMonitor::recordFailedLogin sudah panggil Audit::log
        // ('auth.login.failed') sendiri + cek threshold + Telegram alert.
        SecurityMonitor::recordFailedLogin($email, request()->ip() ?? '');
    }

    public function handleLogout(Logout $event): void
    {
        if (self::$logoutLogged) {
            return;
        }
        $user = $event->user instanceof User ? $event->user : null;
        if (! $user) {
            return;
        }
        self::$logoutLogged = true;

        Audit::log('auth.logout', $user, [
            'guard' => $event->guard,
            'email' => SecurityMonitor::maskEmail((string) $user->email),
        ]);
    }

    public function handleLockout(Lockout $event): void
    {
        // Laravel built-in throttle lockout — log untuk visibility.
        $email = (string) (
            $event->request->input('email')
            ?? $event->request->input('username')
            ?? ''
        );

        Audit::log('security.alert.auth.lockout', null, [
            'email' => $email !== '' ? SecurityMonitor::maskEmail($email) : null,
            'ip' => request()->ip(),
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;
        if (! $user) {
            return;
        }

        Audit::log('auth.password.reset_success', $user, [
            'email' => SecurityMonitor::maskEmail((string) $user->email),
        ]);
    }
}
