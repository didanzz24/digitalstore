<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profil User')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Nomor HP / WhatsApp')
                            ->tel(),
                        TextInput::make('password')
                            ->label('Reset Password (kosongkan = tetap)')
                            ->password()
                            ->revealable()
                            ->dehydrated(fn ($state) => filled($state))
                            ->minLength(6),
                    ]),

                Section::make('Role & Status')
                    ->columns(3)
                    ->schema([
                        Toggle::make('is_admin')
                            ->label('Admin (akses panel)')
                            ->helperText('Hati-hati: admin bisa kelola seluruh sistem.'),
                        Toggle::make('is_banned')
                            ->label('Banned')
                            ->helperText('User banned tidak bisa login & checkout.'),
                        TextInput::make('balance')
                            ->label('Saldo Wallet')
                            ->prefix('Rp')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Edit saldo lewat aksi "Top-up" / "Kurangi" di list user.'),
                    ]),

                Section::make('Telegram Bot Connection')
                    ->description('Bot platform di-set global di Site Settings; section ini menampilkan chat user yang sudah link ke bot tersebut.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('telegram_username')
                            ->label('Telegram Username')
                            ->placeholder('—')
                            ->prefix('@')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('telegram_chat_id')
                            ->label('Telegram Chat ID')
                            ->placeholder('—')
                            ->disabled()
                            ->dehydrated(false),
                        Placeholder::make('telegram_bot_in_use')
                            ->label('Bot Token Platform yang Dipakai')
                            ->content(fn () => new HtmlString(
                                config('services.telegram.bot_token')
                                    ? '<code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">'
                                        .e(self::maskToken((string) config('services.telegram.bot_token'))).'</code>'
                                        .' <span class="text-xs text-slate-500">(global, dari TELEGRAM_BOT_TOKEN env)</span>'
                                    : '<span class="text-xs text-rose-600">Bot Telegram belum dikonfigurasi.</span>'
                            ))
                            ->columnSpanFull(),
                        Placeholder::make('telegram_link_token_active')
                            ->label('Token Link Telegram Aktif')
                            ->content(function (?User $record) {
                                if (! $record) {
                                    return '—';
                                }
                                $token = $record->activeTelegramLinkToken;
                                if (! $token) {
                                    return new HtmlString('<span class="text-xs text-slate-500">Tidak ada token aktif.</span>');
                                }

                                return new HtmlString(
                                    '<code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">'.e($token->token).'</code>'
                                    .' <span class="text-xs text-slate-500">(expired '.e(optional($token->expires_at)?->diffForHumans() ?? '—').')</span>'
                                );
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make('Membership API & API Key')
                    ->description('Membership di-unlock per pembayaran. Atur rate limit, whitelist, atau on/off API key user dari aksi "API Key" di list user.')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('membership_status')
                            ->label('Status Membership')
                            ->content(function (?User $record) {
                                if (! $record) {
                                    return '—';
                                }
                                if ($record->isActiveMember()) {
                                    return new HtmlString(
                                        '<span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">AKTIF</span>'
                                        .' sampai '.e($record->member_expires_at?->format('d M Y H:i') ?? '—')
                                    );
                                }

                                return new HtmlString('<span class="px-2 py-0.5 rounded-full bg-slate-200 text-slate-700 text-xs font-bold">NON-MEMBER</span>');
                            })
                            ->columnSpanFull(),
                        Placeholder::make('api_key_prefix')
                            ->label('API Key Prefix')
                            ->content(function (?User $record) {
                                if (! $record || ! $record->apiClient) {
                                    return new HtmlString('<span class="text-xs text-slate-500">Belum di-generate user.</span>');
                                }

                                return new HtmlString('<code class="px-1.5 py-0.5 bg-slate-100 rounded text-xs">'.e($record->apiClient->api_key_prefix).'</code>');
                            }),
                        Placeholder::make('api_key_status')
                            ->label('Status API Key')
                            ->content(function (?User $record) {
                                if (! $record || ! $record->apiClient) {
                                    return '—';
                                }
                                $client = $record->apiClient;
                                $status = $client->is_active
                                    ? '<span class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">AKTIF</span>'
                                    : '<span class="px-2 py-0.5 rounded-full bg-rose-100 text-rose-700 text-xs font-bold">NONAKTIF</span>';
                                $rate = '<span class="text-xs text-slate-500"> • '.(int) $client->rate_limit_per_minute.' req/menit</span>';
                                $used = $client->last_used_at
                                    ? '<span class="text-xs text-slate-500"> • dipakai '.e($client->last_used_at->diffForHumans()).'</span>'
                                    : '';

                                return new HtmlString($status.$rate.$used);
                            }),
                        Placeholder::make('api_key_whitelist_view')
                            ->label('IP Whitelist')
                            ->content(function (?User $record) {
                                $ips = $record?->apiClient?->allowed_ips;
                                if (empty($ips)) {
                                    return new HtmlString('<span class="text-xs text-slate-500">Semua IP diizinkan.</span>');
                                }

                                return new HtmlString('<pre class="bg-slate-50 rounded p-2 text-xs text-slate-700 font-mono">'.e($ips).'</pre>');
                            })
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (?User $record) => $record !== null),
            ]);
    }

    /** Mask token Telegram seperti `1234567890:••••••XYZ`. */
    protected static function maskToken(string $token): string
    {
        if (strlen($token) < 12) {
            return str_repeat('•', max(0, strlen($token)));
        }
        $colon = strpos($token, ':');
        if ($colon === false) {
            return substr($token, 0, 4).str_repeat('•', max(0, strlen($token) - 8)).substr($token, -4);
        }
        $head = substr($token, 0, $colon + 1);
        $tail = substr($token, -4);

        return $head.str_repeat('•', max(0, strlen($token) - strlen($head) - 4)).$tail;
    }
}
