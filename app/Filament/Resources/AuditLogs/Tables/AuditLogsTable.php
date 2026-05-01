<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y, H:i:s')
                    ->sortable(),
                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'security.alert.') => 'danger',
                        str_starts_with($state, 'auth.bruteforce.') => 'danger',
                        str_starts_with($state, 'auth.login.failed') => 'warning',
                        str_starts_with($state, 'auth.') => 'info',
                        str_starts_with($state, 'order.') => 'success',
                        str_starts_with($state, 'webhook.') => 'gray',
                        default => 'primary',
                    })
                    ->searchable(),
                TextColumn::make('user.email')
                    ->label('User')
                    ->placeholder('— guest —')
                    ->searchable(),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->searchable(),
                TextColumn::make('auditable_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state, AuditLog $record): string => $state
                        ? class_basename($state).'#'.$record->auditable_id
                        : '—'
                    )
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('user_agent')
                    ->label('User-Agent')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('event_group')
                    ->label('Kategori Event')
                    ->options([
                        'auth' => 'Auth (login/register/password)',
                        'security' => 'Security alerts',
                        'order' => 'Order events',
                        'stock' => 'Stock events',
                        'webhook' => 'Webhook events',
                        'user' => 'User actions',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }
                        $prefix = $data['value'].'.';

                        return $query->where('event', 'like', $prefix.'%');
                    }),
                Filter::make('failed_login')
                    ->label('Hanya login gagal')
                    ->toggle()
                    ->query(fn (Builder $q): Builder => $q->where('event', 'auth.login.failed')),
                Filter::make('alerts_only')
                    ->label('Hanya security alerts')
                    ->toggle()
                    ->query(fn (Builder $q): Builder => $q
                        ->where('event', 'like', 'security.alert.%')
                        ->orWhere('event', 'like', 'auth.bruteforce.%')),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Detail')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (AuditLog $record) => 'Audit Entry #'.$record->id)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (AuditLog $record) => new HtmlString(
                        '<dl class="space-y-2 text-sm">'
                        .'<div><dt class="font-semibold">Event</dt><dd><code>'.e($record->event).'</code></dd></div>'
                        .'<div><dt class="font-semibold">Waktu</dt><dd>'.e($record->created_at?->format('d M Y, H:i:s')).'</dd></div>'
                        .'<div><dt class="font-semibold">User</dt><dd>'.e($record->user?->email ?? '— guest —').'</dd></div>'
                        .'<div><dt class="font-semibold">IP</dt><dd>'.e($record->ip_address ?? '—').'</dd></div>'
                        .'<div><dt class="font-semibold">User-Agent</dt><dd class="break-all">'.e($record->user_agent ?? '—').'</dd></div>'
                        .'<div><dt class="font-semibold">Subject</dt><dd>'
                        .e($record->auditable_type ? class_basename($record->auditable_type).'#'.$record->auditable_id : '—').'</dd></div>'
                        .'<div><dt class="font-semibold">Changes / Context</dt>'
                        .'<dd><pre class="bg-slate-900 text-slate-50 text-xs rounded p-3 overflow-x-auto">'
                        .e(json_encode($record->changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '—').'</pre></dd></div>'
                        .'</dl>'
                    )),
            ]);
    }
}
