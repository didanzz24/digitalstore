<?php

namespace App\Filament\Widgets;

use App\Models\AuditLog;
use App\Support\SecurityMonitor;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Stats keamanan singkat untuk dashboard admin: login sukses/gagal 24 jam,
 * jumlah alert security terbaru, top IP brute-force suspect.
 */
class SecurityOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected ?string $pollingInterval = '60s';

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $stats = SecurityMonitor::recentStats(24);

        $topIp = '—';
        if (! empty($stats['top_failed_ips'])) {
            $entries = collect($stats['top_failed_ips']);
            $first = $entries->keys()->first();
            $count = $entries->first();
            $topIp = $first ? $first.' ('.$count.'×)' : '—';
        }

        $criticalRecent = AuditLog::query()
            ->where('event', 'like', 'security.alert.%')
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->count();

        return [
            Stat::make('Login Sukses 24j', (string) $stats['login_success'])
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            Stat::make('Login Gagal 24j', (string) $stats['login_failed'])
                ->description($stats['login_failed'] > 0 ? 'Pantau brute-force' : 'Aman')
                ->descriptionIcon($stats['login_failed'] > 50 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-shield-check')
                ->color($stats['login_failed'] > 50 ? 'danger' : ($stats['login_failed'] > 10 ? 'warning' : 'success')),
            Stat::make('Security Alerts 24j', (string) $criticalRecent)
                ->description($criticalRecent > 0 ? 'Cek tab Audit Log' : 'Tidak ada anomali')
                ->descriptionIcon($criticalRecent > 0 ? 'heroicon-m-bell-alert' : 'heroicon-m-shield-check')
                ->color($criticalRecent > 0 ? 'danger' : 'success'),
            Stat::make('Top IP Login Gagal', $topIp)
                ->description('Window: 24 jam')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('warning'),
        ];
    }
}
