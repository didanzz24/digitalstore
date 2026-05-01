# Security Model — Akhpremium Store

Dokumen ini merangkum semua kontrol keamanan yang sudah diterapkan, beserta
referensi file yang relevan. Audience: developer yang me-maintain repo, admin
yang men-deploy, atau auditor pihak ketiga.

Daftar isi:

1. [Authentication & password](#1-authentication--password)
2. [Brute-force / anomaly monitoring](#2-brute-force--anomaly-monitoring)
3. [Input validation & anti-injection](#3-input-validation--anti-injection)
4. [Output encoding & anti-XSS](#4-output-encoding--anti-xss)
5. [CSRF protection](#5-csrf-protection)
6. [Audit logging & monitoring di admin panel](#6-audit-logging--monitoring-di-admin-panel)
7. [Security headers (CSP, HSTS, dll.)](#7-security-headers-csp-hsts-dll)
8. [API key management](#8-api-key-management)
9. [Webhook authentication & idempotency](#9-webhook-authentication--idempotency)
10. [Recommended operational practices](#10-recommended-operational-practices)

---

## 1. Authentication & password

### 1.1 Hashing

- **Default driver:** `argon2id` (OWASP-recommended memory-hard, default
  parameter `memory=65536 KiB, time=4, threads=2`). Konfigurasi di
  [`config/hashing.php`](../config/hashing.php).
- **Override via env:** `HASH_DRIVER=argon2id|bcrypt|argon`,
  `ARGON_MEMORY`, `ARGON_TIME`, `ARGON_THREADS`, `BCRYPT_ROUNDS`.
- **Salt:** otomatis di-generate per-hash oleh PHP (tidak perlu konfigurasi).
- **Plain-text password** tidak pernah di-store. Semua write password lewat
  `Hash::make()` (Laravel) — User model juga punya cast `'password' => 'hashed'`.
- **Auto-rehash:** saat user login, kalau hash lama (mis. bcrypt rounds=10)
  tidak match parameter sekarang, Laravel auto-rehash dengan parameter terbaru.
  Tidak perlu force user reset password.

### 1.2 Password policy

Diaplikasikan via [`App\Support\PasswordPolicy::default()`](../app/Support/PasswordPolicy.php):

- Minimal **10 karakter**.
- Wajib ada huruf besar **dan** huruf kecil.
- Wajib ada angka.
- Wajib ada simbol.
- Cek **HIBP** (Have I Been Pwned) via k-anonymity API — password yang sudah
  pernah bocor di breach dataset publik akan ditolak. Hanya 5 char hash prefix
  yang dikirim ke `api.pwnedpasswords.com`. Skip pada `APP_ENV=testing|local`.

Policy ini **dipakai di**:

- `POST /register` — [`AuthController::register`](../app/Http/Controllers/AuthController.php)
- `POST /reset-password` — [`AuthController::resetPassword`](../app/Http/Controllers/AuthController.php)
- `POST /akun/profile` (ganti password) — [`AccountController::updateProfile`](../app/Http/Controllers/AccountController.php)
- Filament admin `/admin → User Management → Edit User` — [`UserForm`](../app/Filament/Resources/Users/Schemas/UserForm.php)

### 1.3 Session & cookie

- `SESSION_DRIVER=redis` (rekomendasi prod). Cookie `HttpOnly` + `SameSite=lax`.
- `SESSION_SECURE_COOKIE=true` saat HTTPS aktif.
- Session **regenerate** setiap kali login sukses (mitigasi session fixation).
- Session **invalidate** di logout (token regen, cookie clear).

### 1.4 User-account hardening lain

- Mass-assignment-protected: `User::$fillable` whitelist, `is_admin` dan
  `is_banned` **tidak** di `$fillable` — admin status **tidak bisa** di-set
  via form publik.
- Banned user di-cek di login + checkout (force logout kalau session sudah hidup).
- Email verification flow tersedia (Filament panel resource Users).

---

## 2. Brute-force / anomaly monitoring

[`App\Support\SecurityMonitor`](../app/Support/SecurityMonitor.php) memantau
failed login attempts dengan counter di cache (Redis):

| Threshold | Window | Aksi                                                  |
| --------- | ------ | ----------------------------------------------------- |
| 5× per email   | 10 menit | Audit log `security.alert.auth.bruteforce.email` + Telegram alert |
| 10× per IP     | 10 menit | Audit log `security.alert.auth.bruteforce.ip` + Telegram alert    |
| 3× per email admin | 10 menit | Audit log `security.alert.auth.bruteforce.admin` + Telegram alert (high-priority) |

Counter di-reset otomatis saat user login sukses. Telegram alert
membutuhkan `TELEGRAM_BOT_TOKEN` + `TELEGRAM_ADMIN_CHAT_ID` di `.env`.

### Layer di OS (rekomendasi)

Selain monitor di aplikasi, di VPS juga pasang **fail2ban** (lihat
[`docs/vps-deployment.md`](vps-deployment.md#7-hardening-firewall-ufw--fail2ban))
untuk block IP brute-force di level firewall — defense in depth.

### Rate-limit per route

Selain brute-force monitor, semua endpoint sensitif punya throttle middleware:

| Route                       | Limit       |
| --------------------------- | ----------- |
| `POST /login`               | 10 / menit  |
| `POST /register`            | 5 / menit   |
| `POST /lupa-password`       | 2 / menit   |
| `POST /reset-password`      | 5 / menit   |
| `POST /checkout`            | 10 / menit  |
| `GET /api/v1/*`             | 120 / menit (per IP) + per-key limit |
| `GET /api/invoice/.../check`| 60 / menit  |

---

## 3. Input validation & anti-injection

### 3.1 Eloquent ORM = parametrized query

Seluruh query database lewat Eloquent / Query Builder. **Tidak ada string
concatenation SQL** di codebase. Search:

```bash
grep -rn 'DB::raw\|DB::statement' app/
```

Hasil: hanya beberapa `DB::raw('SUM(...)')` di widget dashboard yang aman
(literal SQL, no user input).

### 3.2 Form Request validation

Semua endpoint POST/PUT melewati `$request->validate([...])` dengan whitelist
rule. Mass-assignment dilindungi via `$fillable` di tiap model.

### 3.3 File upload

- Upload image lewat `Filament` resource → tervalidasi `image|max:2048|mimes:jpg,png,webp`.
- File disimpan di `storage/app/public/products/` lewat `Storage::disk('public')`,
  nama file di-generate UUID (tidak pakai original filename).

### 3.4 Path traversal

Tidak ada user-controlled file path. Storage path & view path semua di-resolve
lewat helper Laravel (`storage_path`, `resource_path`, `view()`).

### 3.5 Command injection

Tidak ada `exec()`/`shell_exec()`/`proc_open()` user-input di app code.

---

## 4. Output encoding & anti-XSS

### 4.1 Blade auto-escape

Semua interpolasi pakai `{{ $var }}` (auto-escape via `htmlspecialchars`).
`{!! ... !!}` hanya dipakai untuk konten HTML yang **diketahui aman**:

- Konten Markdown yang sudah di-render `Str::markdown` (Commonmark sanitize).
- HTML editor admin (`description`, `terms_html`) yang di-sanitize via
  `Filament\RichEditor` di sisi admin sebelum disimpan.

### 4.2 Filament admin

Filament v5 default-nya escape semua state. Custom render via
`HtmlString` di-review manual.

### 4.3 JSON API

Response API selalu `application/json`. Tidak ada inline HTML di response.

### 4.4 Content-Security-Policy

Header CSP dipasang oleh [`SecurityHeaders` middleware](../app/Http/Middleware/SecurityHeaders.php):

- Default mode: **report-only** (`Content-Security-Policy-Report-Only`) supaya
  bisa observe dulu sebelum enforce.
- Set `CSP_ENFORCE=true` di `.env` untuk enforce penuh.
- Whitelist: `'self'`, Tailwind CDN, fonts.bunny.net, Telegram API.
- `'unsafe-inline'`/`'unsafe-eval'` di script-src masih needed oleh Filament
  (Alpine.js). Long-term: migrate ke nonce-based CSP setelah upgrade.

---

## 5. CSRF protection

- Default Laravel CSRF middleware aktif untuk **semua** request POST/PUT/DELETE
  yang melalui `web` middleware group.
- Form di Blade pakai directive `@csrf` (atau `csrf_field()` helper).
- Pengecualian (di [`bootstrap/app.php`](../bootstrap/app.php)) hanya untuk path
  webhook eksternal yang tidak punya akses ke session token:
  - `webhooks/pakasir`
  - `webhooks/fonnte`
  - `webhooks/telegram/*`

  Webhook ini di-otentikasi via signature/secret tersendiri (lihat §9).

---

## 6. Audit logging & monitoring di admin panel

### 6.1 Helper `App\Support\Audit::log()`

Setiap event penting di-log lewat:

```php
\App\Support\Audit::log('event.name', $modelInstance, $context);
```

Fields tabel `audit_logs`:

| Kolom            | Tipe       | Keterangan                                  |
| ---------------- | ---------- | ------------------------------------------- |
| `user_id`        | bigint     | Auto dari `Auth::id()` (null untuk guest)   |
| `event`          | string     | Mis. `auth.login.success`, `order.paid`     |
| `auditable_type` | string     | Class polymorphic (e.g. `App\Models\Order`) |
| `auditable_id`   | bigint     | PK subject                                  |
| `changes`        | json       | Konteks event — **disanitize**              |
| `ip_address`     | string(45) | IPv4/IPv6                                   |
| `user_agent`     | string(500)| Truncated                                   |
| `created_at`     | timestamp  |                                             |

### 6.2 Sanitisasi (anti-leak)

[`Audit::sanitize()`](../app/Support/Audit.php) **selalu** strip key sensitif
sebelum simpan ke DB (mask jadi `[REDACTED]`):

```
password, password_confirmation, current_password, new_password,
remember_token, token, api_key, api_secret, secret, authorization,
cookie, card_number, cvv, pin
```

Filter berlaku **rekursif** pada nested array. Email user di-mask juga
(`bu**@d*******.com`) sebelum di-log untuk privacy.

### 6.3 Event yang sudah di-log

- **Auth:** `auth.login.success`, `auth.login.failed`, `auth.logout`,
  `auth.register.success`, `auth.password.reset_requested`,
  `auth.password.reset_success`, `auth.password.changed`.
- **Security alerts:** `security.alert.auth.bruteforce.email`,
  `security.alert.auth.bruteforce.ip`, `security.alert.auth.bruteforce.admin`.
- **Order/transaction:** `order.created`, `order.paid`, `order.cancelled`,
  `order.refunded`, `order.fulfilled`, `webhook.pakasir.received`, dll.
- **Admin actions:** `user.created`, `user.banned`, `user.unbanned`,
  `stock.imported`, `voucher.created`, dll. (lihat `app/Filament/`)

### 6.4 Dashboard di admin

- **Audit Log Resource:** `/admin → Security → Audit Log` —
  [`AuditLogResource`](../app/Filament/Resources/AuditLogs/AuditLogResource.php)
  read-only, filter by kategori event, hanya security alerts, hanya failed
  logins. Klik "Detail" untuk lihat full JSON `changes`.
- **Security Overview Widget:** di dashboard admin
  ([`SecurityOverviewWidget`](../app/Filament/Widgets/SecurityOverviewWidget.php))
  menampilkan:
  - Login sukses / gagal 24 jam terakhir
  - Jumlah security alerts 24 jam terakhir (warna merah kalau >0)
  - Top IP login gagal (24 jam)
- **Telegram alert:** brute-force trigger juga kirim push ke admin via
  `TelegramBotService::notifyAdmin()` real-time.

---

## 7. Security headers (CSP, HSTS, dll.)

[`SecurityHeaders` middleware](../app/Http/Middleware/SecurityHeaders.php)
dipasang global di `bootstrap/app.php`:

| Header                       | Value                                                    | Catatan                                |
| ---------------------------- | -------------------------------------------------------- | -------------------------------------- |
| `X-Frame-Options`            | `SAMEORIGIN`                                             | Anti-clickjacking                      |
| `X-Content-Type-Options`     | `nosniff`                                                | Anti MIME sniff                        |
| `Referrer-Policy`            | `strict-origin-when-cross-origin`                        |                                        |
| `Permissions-Policy`         | `geolocation=(), microphone=(), camera=(), payment=()`   | Disable API browser yang tidak dipakai |
| `X-XSS-Protection`           | `1; mode=block`                                          | Legacy untuk browser tua               |
| `Strict-Transport-Security`  | `max-age=31536000; includeSubDomains` (saat HTTPS)       |                                        |
| `Content-Security-Policy[-Report-Only]` | (lihat §4.4)                                | Default report-only                    |

---

## 8. API key management

- Storage: tabel `api_clients` — kolom `key_hash` (SHA-256) + `key_prefix`
  (8 karakter pertama, untuk display di admin & audit). Plaintext key
  ditampilkan **sekali** saat generate, tidak pernah disimpan plaintext.
- IP whitelist optional per-key.
- Per-key rate limit (default 120 / menit).
- Revoke instan dari `/admin → Public API → Api Clients` atau `/akun/api-key`
  (untuk member).
- Setiap request berhasil meng-update `last_used_at` untuk tracking.

---

## 9. Webhook authentication & idempotency

### 9.1 Pakasir

- Verifikasi via `payment_method/amount/api_key` signature di body.
- API key dicocokkan dengan `PAKASIR_API_KEY` (atau site setting).
- Idempotent: pakai `OrderFulfillment::markPaidAndAssignStock()` yang **hanya**
  transition pending → paid sekali. Re-delivery webhook → no-op.
- Semua incoming webhook di-store di `webhook_logs` table (incl. raw body) untuk
  audit & replay.

### 9.2 Fonnte (WhatsApp)

- Token di header `Authorization` dicocokkan dengan `FONNTE_INCOMING_TOKEN`.

### 9.3 Telegram

- Path secret di URL: `/webhooks/telegram/{secret}` di-cocokkan dengan
  `TELEGRAM_WEBHOOK_SECRET`. Secret di-generate random 64-char saat install.

---

## 10. Recommended operational practices

- **Rotasi password admin** tiap 90 hari (manual; reminder di Telegram bisa
  dibuat opsional).
- **Backup harian** ke object storage off-VPS (`rclone` ke S3/R2/Wasabi).
  BackupService sudah scheduled di `routes/console.php` — sync ke remote
  storage di luar app via cron.
- **Patch berkala**: `composer outdated --direct`, `npm outdated`,
  `apt list --upgradable`. Subscribe Laravel security advisories.
- **Monitor** `/admin → Audit Log → Hanya security alerts` minimal sekali
  sehari.
- **2FA admin**: belum diimplementasikan (di-roadmap). Sementara, pastikan
  password admin kuat (≥16 char) + email recovery hanya ke akun tepercaya.
- **VPS hardening**: lihat checklist di [`docs/vps-deployment.md`](vps-deployment.md#7-hardening-firewall-ufw--fail2ban):
  UFW deny default, SSH key only, fail2ban, automatic security updates
  (`unattended-upgrades`).
- **Pen-test sederhana** sebelum go-live: scan dengan
  [`nikto`](https://github.com/sullo/nikto) atau
  [OWASP ZAP](https://www.zaproxy.org/) Baseline.

---

## Lampiran: cara kontribusi security fix

Kalau menemukan vulnerability:

1. **Jangan** open PR publik dulu.
2. Email ke owner repo (lihat `composer.json` author / Site Settings),
   atau Telegram admin.
3. Sertakan PoC + impact assessment.
4. Tunggu konfirmasi sebelum disclose.

PR security fix di-prioritize di review queue.
