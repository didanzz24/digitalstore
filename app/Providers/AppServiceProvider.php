<?php

namespace App\Providers;

use App\Listeners\AuthAuditListener;
use App\Listeners\NotifyTelegramOnBackupEvent;
use App\Models\SiteSetting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Force HTTPS scheme saat dijalankan via tunnel/reverse-proxy.
        // Aktifkan dengan FORCE_HTTPS=true di .env (diset hanya di environment
        // tunnel/produksi, jangan untuk development biasa).
        if (filter_var(env('FORCE_HTTPS', false), FILTER_VALIDATE_BOOLEAN)) {
            URL::forceScheme('https');
        }

        // Bagikan SiteSetting (singleton) ke semua view sebagai $site.
        // View::composer('*') tetap fire per partial, tapi SiteSetting::current()
        // sekarang punya cache statis per-request (lihat SiteSetting model) jadi
        // hanya 1 query DB per request meski composer fire banyak kali.
        // Aman terhadap state pre-migration (saat install).
        View::composer('*', function ($view) {
            $site = null;
            try {
                if (Schema::hasTable('site_settings')) {
                    $site = SiteSetting::current();
                }
            } catch (\Throwable $e) {
                $site = null;
            }
            $view->with('site', $site);
        });

        // Telegram notif untuk event backup (sukses/gagal/cleanup/health)
        Event::subscribe(NotifyTelegramOnBackupEvent::class);

        // Audit log untuk semua event auth (user-facing /login + Filament admin
        // pakai event yang sama).
        Event::listen(Login::class, [AuthAuditListener::class, 'handleLogin']);
        Event::listen(Failed::class, [AuthAuditListener::class, 'handleFailed']);
        Event::listen(Logout::class, [AuthAuditListener::class, 'handleLogout']);
        Event::listen(Lockout::class, [AuthAuditListener::class, 'handleLockout']);
        Event::listen(PasswordReset::class, [AuthAuditListener::class, 'handlePasswordReset']);
    }
}
