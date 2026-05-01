<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\ApiClient;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FonnteWhatsApp;
use App\Services\WalletService;
use App\Support\Audit;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount(['orders'])
                    ->withSum(['orders as paid_total' => fn ($q) => $q->where('status', 'paid')], 'amount')
                    ->with(['apiClient']);
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->description(fn (User $r) => $r->email),
                TextColumn::make('phone')
                    ->label('HP')
                    ->toggleable(),
                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean(),
                IconColumn::make('is_banned')
                    ->label('Banned')
                    ->boolean()
                    ->trueIcon('heroicon-o-no-symbol')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success'),
                TextColumn::make('orders_count')
                    ->label('Order')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('paid_total')
                    ->label('Total Spend')
                    ->money('IDR', locale: 'id')
                    ->sortable()
                    ->placeholder('Rp 0'),
                TextColumn::make('balance')
                    ->label('Saldo')
                    ->money('IDR', locale: 'id')
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label('Last Login')
                    ->since()
                    ->placeholder('Belum pernah')
                    ->sortable(),
                TextColumn::make('telegram_username')
                    ->label('Telegram')
                    ->prefix('@')
                    ->placeholder('—')
                    ->toggleable()
                    ->description(fn (User $r) => $r->telegram_chat_id ? 'ID: '.$r->telegram_chat_id : null),
                TextColumn::make('apiClient.api_key_prefix')
                    ->label('API Key')
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (User $r) => $r->apiClient && $r->apiClient->is_active ? 'success' : 'gray')
                    ->toggleable(),
                IconColumn::make('is_member')
                    ->label('Member')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->trueColor('warning')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Bergabung')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_admin')->label('Role Admin'),
                TernaryFilter::make('is_banned')->label('Banned'),
                TernaryFilter::make('is_member')->label('Member Aktif'),
                TernaryFilter::make('has_api_key')
                    ->label('Punya API Key')
                    ->placeholder('Semua')
                    ->trueLabel('Sudah generate')
                    ->falseLabel('Belum generate')
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('apiClient'),
                        false: fn (Builder $q) => $q->whereDoesntHave('apiClient'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('orderHistory')
                    ->label('Order History')
                    ->icon('heroicon-o-list-bullet')
                    ->color('info')
                    ->modalHeading(fn (User $r) => "Order History — {$r->name}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(function (User $r) {
                        $orders = $r->orders()->latest()->limit(50)->get();
                        $rows = $orders->map(function (Order $o) {
                            $color = match ($o->status) {
                                'paid' => 'green',
                                'pending' => 'amber',
                                'cancelled' => 'red',
                                default => 'slate',
                            };
                            $amt = number_format((int) $o->amount, 0, ',', '.');

                            return '<tr class="border-b">'.
                                '<td class="py-2 px-3 font-mono text-xs">'.e($o->order_code).'</td>'.
                                '<td class="py-2 px-3"><span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-'.$color.'-100 text-'.$color.'-700">'.e(strtoupper($o->status)).'</span></td>'.
                                '<td class="py-2 px-3 text-right font-bold">Rp '.$amt.'</td>'.
                                '<td class="py-2 px-3 text-xs text-slate-500">'.$o->created_at->format('d M Y H:i').'</td>'.
                                '</tr>';
                        })->implode('');
                        if (empty($rows)) {
                            $rows = '<tr><td colspan="4" class="py-6 text-center text-slate-400">Belum ada order</td></tr>';
                        }
                        $html = '<div class="overflow-x-auto"><table class="min-w-full text-sm">'.
                            '<thead><tr class="border-b bg-slate-50"><th class="py-2 px-3 text-left">Kode</th><th class="py-2 px-3 text-left">Status</th><th class="py-2 px-3 text-right">Total</th><th class="py-2 px-3 text-left">Tanggal</th></tr></thead>'.
                            '<tbody>'.$rows.'</tbody></table></div>';

                        return new HtmlString($html);
                    }),

                Action::make('topup')
                    ->label('Top-up Saldo')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->modalHeading(fn (User $r) => "Top-up Saldo — {$r->name}")
                    ->schema([
                        TextInput::make('amount')
                            ->label('Nominal')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Textarea::make('note')
                            ->label('Catatan')
                            ->rows(2),
                    ])
                    ->action(function (array $data, User $r) {
                        $tx = WalletService::credit(
                            user: $r,
                            amount: (int) $data['amount'],
                            type: WalletTransaction::TYPE_ADMIN_TOPUP,
                            note: $data['note'] ?? null,
                            adminId: auth()->id(),
                        );
                        Notification::make()
                            ->success()
                            ->title('Saldo bertambah')
                            ->body('Rp '.number_format($tx->amount, 0, ',', '.').'. Saldo baru: Rp '.number_format($tx->balance_after, 0, ',', '.'))
                            ->send();
                    }),

                Action::make('deduct')
                    ->label('Kurangi Saldo')
                    ->icon('heroicon-o-minus-circle')
                    ->color('warning')
                    ->visible(fn (User $r) => $r->balance > 0)
                    ->modalHeading(fn (User $r) => "Kurangi Saldo — {$r->name}")
                    ->schema([
                        TextInput::make('amount')
                            ->label('Nominal')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Textarea::make('note')
                            ->label('Alasan')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (array $data, User $r) {
                        try {
                            $tx = WalletService::debit(
                                user: $r,
                                amount: (int) $data['amount'],
                                type: WalletTransaction::TYPE_ADMIN_DEDUCT,
                                note: $data['note'] ?? null,
                                adminId: auth()->id(),
                            );
                            Notification::make()
                                ->success()
                                ->title('Saldo dikurangi')
                                ->body('Rp '.number_format(abs($tx->amount), 0, ',', '.').'. Saldo baru: Rp '.number_format($tx->balance_after, 0, ',', '.'))
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal')->body($e->getMessage())->send();
                        }
                    }),

                Action::make('apiKeyManage')
                    ->label('API Key')
                    ->icon('heroicon-o-key')
                    ->color('primary')
                    ->modalHeading(fn (User $r) => "Kelola API Key — {$r->name}")
                    ->modalSubmitActionLabel('Simpan')
                    ->modalCancelActionLabel('Tutup')
                    ->fillForm(function (User $r) {
                        $client = $r->apiClient;

                        return [
                            'is_active' => $client?->is_active ?? false,
                            'rate_limit_per_minute' => $client?->rate_limit_per_minute ?? 60,
                            'allowed_ips' => $client?->allowed_ips ?? '',
                        ];
                    })
                    ->schema(fn (User $r) => [
                        Toggle::make('is_active')
                            ->label('API Key Aktif')
                            ->helperText($r->apiClient
                                ? 'Toggle off untuk men-disable key tanpa menghapusnya.'
                                : 'User belum punya API key — pakai tombol "Reset Key" untuk generate.')
                            ->disabled(! $r->apiClient),
                        TextInput::make('rate_limit_per_minute')
                            ->label('Rate Limit (req/menit)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10000)
                            ->required()
                            ->helperText('Default 60. Max 10000.'),
                        Textarea::make('allowed_ips')
                            ->label('IP Whitelist')
                            ->rows(4)
                            ->placeholder("203.0.113.10\n198.51.100.0")
                            ->helperText('Pisah dengan baris baru atau koma. Kosongkan = izinkan semua IP.'),
                    ])
                    ->action(function (array $data, User $r) {
                        if (! $r->apiClient) {
                            Notification::make()
                                ->warning()
                                ->title('Belum ada key')
                                ->body('User belum generate API key. Pakai tombol "Reset Key" untuk generate.')
                                ->send();

                            return;
                        }

                        $r->apiClient->forceFill([
                            'is_active' => (bool) ($data['is_active'] ?? false),
                            'rate_limit_per_minute' => max(1, (int) ($data['rate_limit_per_minute'] ?? 60)),
                            'allowed_ips' => trim((string) ($data['allowed_ips'] ?? '')) ?: null,
                        ])->save();

                        Audit::log('api_key.admin_updated', $r->apiClient, [
                            'admin_id' => auth()->id(),
                            'user_id' => $r->id,
                        ]);

                        Notification::make()->success()->title('Pengaturan API Key disimpan')->send();
                    }),

                Action::make('apiKeyReset')
                    ->label(fn (User $r) => $r->apiClient ? 'Reset Key' : 'Generate Key')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $r) => $r->apiClient
                        ? "Regenerate API Key — {$r->name}"
                        : "Generate API Key — {$r->name}")
                    ->modalDescription('Key lama akan langsung tidak valid. Salin key baru sekarang — tidak bisa dilihat lagi setelah ini.')
                    ->action(function (User $r) {
                        $issued = ApiClient::issueForUser($r);

                        Audit::log('api_key.admin_issued', $issued['client'], [
                            'admin_id' => auth()->id(),
                            'user_id' => $r->id,
                            'prefix' => $issued['client']->api_key_prefix,
                        ]);

                        Notification::make()
                            ->success()
                            ->title('API Key di-generate')
                            ->body(new HtmlString(
                                '<div class="space-y-1">'
                                .'<p class="text-xs text-slate-600">Salin sekarang — tidak akan ditampilkan lagi:</p>'
                                .'<code class="block px-2 py-1 bg-slate-100 rounded text-xs break-all">'
                                .e($issued['raw'])
                                .'</code></div>'
                            ))
                            ->persistent()
                            ->send();
                    }),

                Action::make('toggleBan')
                    ->label(fn (User $r) => $r->is_banned ? 'Unban' : 'Ban')
                    ->icon(fn (User $r) => $r->is_banned ? 'heroicon-o-shield-check' : 'heroicon-o-no-symbol')
                    ->color(fn (User $r) => $r->is_banned ? 'success' : 'danger')
                    ->modalHeading(fn (User $r) => $r->is_banned ? "Unban — {$r->name}" : "Ban — {$r->name}")
                    ->schema(fn (User $r) => $r->is_banned
                        ? []
                        : [
                            Textarea::make('reason')
                                ->label('Alasan ban')
                                ->required()
                                ->rows(2),
                        ])
                    ->action(function (array $data, User $r) {
                        if ($r->is_banned) {
                            $r->update([
                                'is_banned' => false,
                                'banned_at' => null,
                                'ban_reason' => null,
                            ]);
                            Notification::make()->success()->title('User di-unban')->send();
                        } else {
                            $r->update([
                                'is_banned' => true,
                                'banned_at' => now(),
                                'ban_reason' => $data['reason'] ?? 'Tanpa alasan',
                            ]);
                            Notification::make()->warning()->title('User di-ban')->body($data['reason'] ?? '')->send();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('broadcast')
                        ->label('Broadcast WA')
                        ->icon('heroicon-o-megaphone')
                        ->color('info')
                        ->schema([
                            Textarea::make('message')
                                ->label('Isi Pesan')
                                ->required()
                                ->rows(5)
                                ->helperText('Boleh pakai placeholder {nama} untuk personalisasi.'),
                        ])
                        ->action(function (array $data, Collection $records) {
                            $wa = app(FonnteWhatsApp::class);
                            $sent = 0;
                            $failed = 0;
                            $skipped = 0;
                            foreach ($records as $user) {
                                if (! $user->phone) {
                                    $skipped++;

                                    continue;
                                }
                                $msg = str_replace('{nama}', $user->name, $data['message']);
                                $ok = $wa->send(null, $user->phone, $msg);
                                if ($ok) {
                                    $sent++;
                                } else {
                                    $failed++;
                                }
                            }
                            Notification::make()
                                ->success()
                                ->title('Broadcast selesai')
                                ->body("Terkirim: {$sent} | Gagal: {$failed} | Skip (no HP): {$skipped}")
                                ->send();
                        }),

                    BulkAction::make('bulkBan')
                        ->label('Ban Selected')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->schema([
                            Textarea::make('reason')->label('Alasan')->required()->rows(2),
                        ])
                        ->action(function (array $data, Collection $records) {
                            $count = 0;
                            foreach ($records as $u) {
                                if ($u->is_admin) {
                                    continue; // jangan ban admin lewat bulk
                                }
                                $u->update([
                                    'is_banned' => true,
                                    'banned_at' => now(),
                                    'ban_reason' => $data['reason'],
                                ]);
                                $count++;
                            }
                            Notification::make()->success()->title("{$count} user di-ban")->send();
                        }),
                ]),
            ]);
    }
}
