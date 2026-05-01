# Akhpremium Store

Toko digital untuk penjualan akun premium (Netflix, CapCut, Spotify, dll) dengan
auto-delivery kredensial setelah pembayaran sukses lewat **Pakasir Payment
Gateway** (QRIS, Virtual Account, E-Wallet).

Stack: Laravel 13 · Filament 5 · PHP 8.3 · Tailwind (CDN) · MySQL 8 / MariaDB 10.6+.

## Fitur

### Sisi pembeli (publik)
- Katalog dinamis dengan filter kategori + pencarian.
- Halaman detail produk dengan multi-varian (durasi/paket).
- Checkout instan tanpa keranjang (langsung "Beli Sekarang").
- Halaman invoice publik (URL via `order_code` random) yang menampilkan
  status pembayaran dan kredensial akun setelah PAID.

### Sisi admin (Filament `/admin`)
- Manajemen kategori, produk, varian, stok kredensial.
- Manajemen pesanan (orders) dengan filter status & kolom dapat di-edit.
- Dashboard ringkasan: pendapatan harian/bulanan, jumlah order, alert stok menipis.
- Audit log untuk aksi penting.

### Security
- **Password hashing** default **argon2id** (memory-hard OWASP-recommended) +
  per-hash random salt — config di `config/hashing.php`. Plaintext password
  tidak pernah ditulis ke storage. Bcrypt rounds=12 tetap supported sebagai
  fallback; hash lama auto-rehash saat user login berikutnya.
- **Password policy** terpusat di `App\Support\PasswordPolicy::default()`:
  min 10 char, huruf besar+kecil, angka, simbol, cek HIBP. Diterapkan di
  register/reset/profile + form admin.
- **Brute-force monitor** real-time (`App\Support\SecurityMonitor`):
  threshold per email (5×/10min), per IP (10×/10min), per admin (3×/10min).
  Trigger Telegram alert + audit log.
- **Audit log lengkap** di `/admin → Security → Audit Log`:
  `auth.login.success`, `auth.login.failed`, `auth.password.changed`,
  `order.*`, `webhook.*`, `security.alert.*`, dll. Sensitive keys auto-redact
  (`password`, `token`, `api_key`, dst).
- **Security dashboard widget** di admin panel: login sukses/gagal 24 jam,
  jumlah security alerts, top IP brute-force suspect.
- **Content-Security-Policy** header (default report-only, set
  `CSP_ENFORCE=true` untuk enforce penuh).
- Kredensial akun di tabel `stocks` **dienkripsi otomatis** dengan `APP_KEY`
  (AES-256-CBC via Laravel `encrypted` cast).
- `protected $fillable` eksplisit di semua model — bebas dari mass assignment.
- `is_admin` flag + `FilamentUser::canAccessPanel()` → hanya admin yang boleh
  buka `/admin`.
- Security headers global (X-Frame-Options, X-Content-Type-Options, Referrer
  Policy, Permissions Policy, HSTS untuk koneksi HTTPS).
- Webhook Pakasir diverifikasi via Transaction Detail API + idempotent
  fulfillment.
- Atomic stock assignment dengan `lockForUpdate()` — race-condition safe.
- Throttle per route (login 10/min, register 5/min, reset 2/min, checkout 10/min).
- CSRF aktif global; pengecualian hanya untuk webhook eksternal.

Detail teknis lengkap → [`docs/security.md`](docs/security.md).

## Dokumentasi

- [`docs/vps-deployment.md`](docs/vps-deployment.md) — panduan deploy VPS
  end-to-end (Nginx + PHP-FPM + MySQL + Redis + Supervisor + Let's Encrypt +
  domain pointing + UFW/fail2ban).
- [`docs/api.md`](docs/api.md) — REST API reference (auth `X-API-KEY`,
  endpoints, schema, contoh integrasi).
- [`docs/security.md`](docs/security.md) — model keamanan: password policy,
  hashing, audit logging, monitoring, CSP, dll.

## Setup

Database default sekarang **MySQL**. Pastikan MySQL 8 / MariaDB 10.6+ aktif,
lalu siapkan database & user (sesuaikan password):

```bash
mysql -uroot -p -e "CREATE DATABASE akhpremium CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -p -e "CREATE USER 'akhpremium'@'localhost' IDENTIFIED BY 'ganti_password_kuat';"
mysql -uroot -p -e "GRANT ALL PRIVILEGES ON akhpremium.* TO 'akhpremium'@'localhost'; FLUSH PRIVILEGES;"
```

Lalu install aplikasinya:

```bash
composer install
cp .env.example .env
php artisan key:generate
# isi DB_DATABASE / DB_USERNAME / DB_PASSWORD di .env sesuai kredensial di atas
php artisan migrate --seed
```

Akun admin default dari seeder:

- Email: `admin@akhpremium.test`
- Password: `password`

**Wajib ganti password admin segera setelah deploy production.**

### Konfigurasi Pakasir

Tambahkan di `.env`:

```env
PAKASIR_PROJECT=slug-project-pakasir-kamu
PAKASIR_API_KEY=api-key-dari-dashboard-pakasir
PAKASIR_QRIS_ONLY=false           # true = paksa hanya QRIS
PAKASIR_ORDER_EXPIRY_MINUTES=60
```

Di dashboard Pakasir, set Webhook URL ke:

```
https://domain-kamu.com/webhooks/pakasir
```

## Menjalankan

```bash
php artisan serve
# Atau pakai script "dev" yang menjalankan server + queue + vite + pail bersamaan:
composer dev
```

Buka:

- Frontend toko: http://127.0.0.1:8000/
- Admin Filament: http://127.0.0.1:8000/admin

## Testing

```bash
php artisan test
```

Tes feature mencakup: katalog, validasi & pembuatan order, fulfillment yang
atomik & idempotent, dan pengamanan halaman invoice (kredensial hanya muncul
setelah status `paid`).

## Lint

```bash
./vendor/bin/pint
```

## Roadmap selanjutnya

- Voucher / kode promo (validasi server-side: max usage, expiry, min purchase).
- Flashsale (countdown + quota).
- Email notifikasi order sukses (kredensial dikirim ke email pembeli).
- Filament action: import/export CSV stok.
- 2FA admin (TOTP).
- IP allowlist untuk panel `/admin`.
