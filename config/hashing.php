<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Default driver untuk hashing password user. Mendukung: "argon2id" (paling
    | recommended — memory-hard), "argon", "bcrypt". Semua memakai random salt
    | per-hash secara otomatis (tidak perlu diset manual).
    |
    | Laravel menyimpan hash + salt + parameter di satu kolom, sehingga hash
    | lama (bcrypt) tetap valid setelah ganti driver — Laravel akan auto-rehash
    | password lama saat user login berikutnya (lihat User::password cast =
    | "hashed").
    |
    */

    'driver' => env('HASH_DRIVER', 'argon2id'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    |
    | Default sesuai rekomendasi OWASP 2024 untuk argon2id:
    |   memory  = 65536 KiB (64 MiB)
    |   time    = 4 iterations
    |   threads = 2
    |
    | Boleh di-override via .env kalau VPS punya RAM/CPU lebih besar.
    |
    */

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 2),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash On Login
    |--------------------------------------------------------------------------
    |
    | Saat true (default), Laravel akan otomatis re-hash password user dengan
    | parameter terbaru saat mereka login — supaya hash lama (bcrypt low-round)
    | bisa otomatis migrate ke argon2id tanpa minta user reset password.
    |
    */

    'rehash_on_login' => true,

];
