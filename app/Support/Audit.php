<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class Audit
{
    /**
     * Field yang TIDAK PERNAH boleh masuk ke audit_logs.changes — meskipun
     * caller secara accidental melempar lewat. Sentinel filter terakhir
     * supaya tidak ada plain-text password / token / api-key yang ke-log.
     */
    public const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'remember_token',
        'token',
        'api_key',
        'api_secret',
        'secret',
        'authorization',
        'cookie',
        'card_number',
        'cvv',
        'pin',
    ];

    /**
     * Log sebuah event ke audit_logs.
     *
     * @param  array<string,mixed>|null  $changes
     */
    public static function log(
        string $event,
        ?Model $subject = null,
        ?array $changes = null,
    ): void {
        AuditLog::create([
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $subject?->getMorphClass(),
            'auditable_id' => $subject?->getKey(),
            'changes' => self::sanitize($changes),
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500),
        ]);
    }

    /**
     * Strip semua key sensitif dari array changes secara rekursif. Kalau
     * value-nya non-scalar (array), ikut di-filter. Mask jadi "[REDACTED]"
     * supaya kelihatan kalau ada usaha ngirim sensitive data ke audit.
     *
     * @param  array<string,mixed>|null  $payload
     * @return array<string,mixed>|null
     */
    public static function sanitize(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $clean = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $clean[$key] = '[REDACTED]';

                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::sanitize($value);

                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    protected static function isSensitiveKey(string $key): bool
    {
        $needle = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($needle, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
