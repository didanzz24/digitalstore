<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Centralized password policy. Dipakai di register, reset, profile update,
 * dan admin User Filament resource — supaya rule-nya konsisten satu tempat.
 *
 * Aturan:
 * - Minimal 10 karakter
 * - Wajib ada huruf besar + huruf kecil
 * - Wajib ada angka
 * - Wajib ada simbol
 * - Tidak boleh password yang sudah pernah bocor di Have I Been Pwned
 *   (cek via k-anonymity API — hanya 5 char hash prefix yang dikirim).
 *
 * Saat APP_ENV=testing & APP_ENV=local, uncompromised() di-skip supaya
 * test-suite tidak hit jaringan eksternal.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    public static function default(): Password
    {
        $rule = Password::min(self::MIN_LENGTH)
            ->mixedCase()
            ->numbers()
            ->symbols();

        if (! app()->environment(['testing', 'local'])) {
            $rule->uncompromised();
        }

        return $rule;
    }

    /**
     * Versi human-readable untuk ditampilkan ke user di form register/reset.
     */
    public static function description(): string
    {
        return 'Min. '.self::MIN_LENGTH.' karakter, kombinasi huruf besar, huruf kecil, angka, dan simbol.';
    }
}
