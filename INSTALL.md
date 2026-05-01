# Panduan Instalasi & Setup — Akhpremium Store

Panduan lengkap setup source code ini di server Linux (Ubuntu 22.04+ / Debian 12+) untuk produksi, atau di mesin lokal untuk development.

> **Untuk deploy ke VPS + koneksi domain + HTTPS lengkap dengan Nginx,
> Supervisor, UFW & fail2ban**, lihat panduan terpisah:
> [`docs/vps-deployment.md`](docs/vps-deployment.md).
>
> File ini fokus ke aspek aplikasi (config Laravel, DB, Pakasir, Telegram,
> queue, scheduler).

> Demo: <https://deer-conditioning-superior-lawn.trycloudflare.com> · Admin: `admin@akhpremium.test` / `password`

---

## 1. Kebutuhan Sistem (Prerequisites)

| Komponen | Versi minimum | Catatan |
|---|---|---|
| PHP | 8.3.x | dengan ekstensi `mbstring`, `xml`, `bcmath`, `curl`, `mysql`, `gd`, `zip`, `intl`, `sqlite3`, `gmp` |
| Composer | 2.6+ | <https://getcomposer.org> |
| Node.js | 20.x LTS | untuk build asset frontend (Vite) |
| MySQL | 8.0+ atau MariaDB 10.6+ | (boleh SQLite untuk development) |
| Redis | 7.x (opsional, recommended) | untuk queue & cache |
| Web server | Nginx 1.24 atau Apache 2.4 | dengan HTTPS (Let's Encrypt) |
| Sistem | Linux (Ubuntu/Debian/CentOS) | utility wajib: `sqlite3`, `mysql-client`, `mysqldump`, `unzip`, `git`, `gzip`, `tar` |

Install paket sistem (Ubuntu/Debian):

```bash
sudo apt update
sudo apt install -y php8.3-cli php8.3-fpm php8.3-mbstring php8.3-xml php8.3-bcmath \
                    php8.3-curl php8.3-mysql php8.3-gd php8.3-zip php8.3-intl \
                    php8.3-sqlite3 php8.3-gmp php8.3-redis \
                    composer nodejs npm mysql-server redis-server \
                    sqlite3 mysql-client unzip git nginx supervisor cron
```

---

## 2. Clone & Install Dependency

```bash
cd /var/www
sudo git clone https://github.com/Dandutzz/digitalstore.git akhpremium
sudo chown -R $USER:www-data akhpremium
cd akhpremium

# PHP packages
composer install --no-dev --optimize-autoloader --no-interaction

# Frontend
npm ci
npm run build
```

Saat development gunakan: `composer install` (tanpa `--no-dev`) dan `npm run dev`.

---

## 3. Konfigurasi Environment (`.env`)

Salin template:

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`. Variabel **WAJIB**:

```env
APP_NAME="Akhpremium Store"
APP_ENV=production
APP_KEY=base64:...           # otomatis dari key:generate
APP_DEBUG=false
APP_URL=https://yourdomain.com
APP_TIMEZONE=Asia/Jakarta
APP_LOCALE=id

# Saat di balik tunnel/reverse-proxy HTTPS, set ini supaya semua URL pakai https://
FORCE_HTTPS=true

# ── Database (MySQL recommended utk produksi) ─────────────────────────────────
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=akhpremium
DB_USERNAME=akhpremium
DB_PASSWORD=ganti_password_kuat

# ── Queue & Cache (Redis sangat disarankan di produksi) ───────────────────────
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# ── Mail (opsional, untuk lupa password) ──────────────────────────────────────
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS="no-reply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"

# ── Pakasir (gateway QRIS) ─────────────────────────────────────────────────────
PAKASIR_BASE_URL=https://app.pakasir.com
PAKASIR_PROJECT=akhpremv2
PAKASIR_API_KEY=isi_dari_dashboard_pakasir

# ── Telegram Bot ──────────────────────────────────────────────────────────────
TELEGRAM_BOT_TOKEN=123456:ABC...           # dari @BotFather
TELEGRAM_BOT_USERNAME=Akhpremiumv2_bot
TELEGRAM_ADMIN_CHAT_ID=874572727           # chat_id admin (untuk broadcast & notifikasi backup)
TELEGRAM_WEBHOOK_SECRET=string_acak_panjang  # untuk validasi webhook (opsional tapi recommended)

# ── Backup ────────────────────────────────────────────────────────────────────
BACKUP_ARCHIVE_PASSWORD=passworz_zip_kuat   # (opsional) password ZIP untuk enkripsi backup
```

---

## 4. Database & Storage

### 4a. Buat database MySQL

```bash
sudo mysql -e "CREATE DATABASE akhpremium CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'akhpremium'@'localhost' IDENTIFIED BY 'ganti_password_kuat';"
sudo mysql -e "GRANT ALL PRIVILEGES ON akhpremium.* TO 'akhpremium'@'localhost'; FLUSH PRIVILEGES;"
```

### 4b. Migrasi & seeder

```bash
php artisan migrate --seed --force
```

Akan membuat semua tabel + data default (kategori, sample produk, FAQ, dll.).

### 4c. Buat user admin

```bash
php artisan tinker --execute="
\App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@yourdomain.com',
    'password' => bcrypt('passworz_kuat'),
    'role' => 'admin',
]);"
```

### 4d. Storage symlink & permissions

```bash
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 4e. Optimasi cache (produksi)

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan filament:optimize
```

---

## 5. Setup Pakasir (QRIS Gateway)

1. Login <https://app.pakasir.com> → **Project** → buat project baru (catat `slug` project, taruh di `PAKASIR_PROJECT`).
2. Salin **API Key** ke `PAKASIR_API_KEY`.
3. Set **Webhook URL** project ke:
   `https://yourdomain.com/webhooks/pakasir`
4. Pastikan webhook URL **publik & tanpa basic auth**, supaya Pakasir bisa `POST` ke server kamu.

> Saat sandbox, Pakasir kadang tidak konsisten case-sensitive untuk `order_id`. Codebase sudah meng-handle ini dengan fallback varian uppercase/lowercase (lihat `PakasirService::fetchTransactionDetail`).

---

## 6. Setup Bot Telegram

1. Chat dengan [@BotFather](https://t.me/BotFather) → `/newbot` → catat token, taruh di `TELEGRAM_BOT_TOKEN`.
2. Set webhook bot:
   ```bash
   curl "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/setWebhook" \
        -d "url=https://yourdomain.com/webhooks/telegram&secret_token=${TELEGRAM_WEBHOOK_SECRET}"
   ```
3. Buka chat bot, ketik `/start` di akun **admin**, ambil chat_id dari log Laravel atau dari endpoint `getUpdates`. Set `TELEGRAM_ADMIN_CHAT_ID`.
4. Test: ketik `/produk` di bot — bot kirim list produk.

> Notifikasi backup harian otomatis dikirim ke chat_id admin di atas.

---

## 7. Cron / Laravel Scheduler

Wajib aktif untuk: auto-backup harian, auto-expire pesanan pending, dll.

```bash
crontab -e
```

Tambah baris:

```cron
* * * * * cd /var/www/akhpremium && php artisan schedule:run >> /dev/null 2>&1
```

Verifikasi job terdaftar:

```bash
php artisan schedule:list
```

Akan muncul:
- `backup:clean` — daily 02:00 WIB
- `backup:run --only-db` — daily 02:05 WIB
- `backup:run` (full DB+files) — daily 02:30 WIB
- `backup:monitor` — daily 09:00 WIB

---

## 8. Queue Worker (Supervisor)

Buat file `/etc/supervisor/conf.d/akhpremium-worker.conf`:

```ini
[program:akhpremium-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/akhpremium/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/akhpremium-worker.log
stopwaitsecs=3600
```

Reload supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start akhpremium-worker:*
```

---

## 9. Web Server (Nginx + HTTPS)

`/etc/nginx/sites-available/akhpremium`:

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    root /var/www/akhpremium/public;
    index index.php index.html;

    # Let's Encrypt
    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    client_max_body_size 25M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Block dotfiles
    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/akhpremium /etc/nginx/sites-enabled/
sudo certbot --nginx -d yourdomain.com
sudo nginx -t && sudo systemctl reload nginx
```

---

## 10. Auto Backup — Pemakaian

### Lokasi backup
`storage/app/private/akhpremium-backups/Akhpremium-store/YYYY-MM-DD-HH-mm-ss.zip`

> Folder ini **tidak** dipublikasikan ke web (private disk).

### Yang dicakup oleh backup
- ✅ **Database lengkap** (semua tabel: `users`, `orders`, `order_items`, `products`, `stocks`, `vouchers`, dll.) — di-dump pakai `mysqldump` lalu di-gzip.
- ✅ **Storage upload** (`storage/app/public` & `storage/app/private`) — gambar produk, gambar pengumuman, file lain yg di-upload admin/user.
- ✅ **`.env`** — konfigurasi.
- ❌ Excluded: `vendor/`, `node_modules/`, `storage/framework/` (cache), folder backup itu sendiri.

### Jadwal otomatis (timezone Asia/Jakarta)
| Waktu | Job | Keterangan |
|---|---|---|
| 02:00 | `backup:clean` | hapus backup lama sesuai retention policy |
| 02:05 | `backup:run --only-db` | dump DB cepat (untuk RPO rendah) |
| 02:30 | `backup:run` | full backup (DB + storage + .env) |
| 09:00 | `backup:monitor` | cek kesehatan backup, kirim notif Telegram kalau bermasalah |

### Retention policy
Default (di `config/backup.php`):
- Simpan **semua** backup ≤ 7 hari
- Simpan **harian** ≤ 16 hari
- Simpan **mingguan** ≤ 8 minggu
- Simpan **bulanan** ≤ 4 bulan
- Simpan **tahunan** ≤ 2 tahun
- Hapus jika total backup > **5000 MB**

### Trigger manual
- Dari admin panel: `/admin/manage-backups` → **Backup Sekarang**.
- Dari CLI:
  ```bash
  php artisan backup:run            # full
  php artisan backup:run --only-db  # DB saja
  php artisan backup:clean          # cleanup
  php artisan backup:list           # daftar backup + status sehat
  ```

### Notifikasi Telegram
Setiap event backup (success / failed / unhealthy / cleanup failed) otomatis dikirim ke `TELEGRAM_ADMIN_CHAT_ID`. Bisa dibungkam parsial dengan menyetel `BACKUP_NOTIFICATIONS_*` di `.env` (lihat `config/backup.php`).

### Off-site (Cloud) — Recommended
Default backup tersimpan di disk lokal. Untuk redundansi off-site, edit `config/filesystems.php` dan `config/backup.php` → tambah disk S3/Dropbox/B2:

```php
// config/filesystems.php
'backups_s3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BACKUP_BUCKET'),
    'visibility' => 'private',
],
```

```php
// config/backup.php
'destination' => [
    'disks' => ['backups', 'backups_s3'],
],
```

---

## 11. Disaster Recovery (Restore)

Skenario: VPS rusak / hilang. Setup VPS baru, install dependency dan setup app dari awal (langkah 1-9 di atas), lalu **restore data**:

### Restore database

```bash
# 1. Download file ZIP backup (dari S3 / dari /admin/manage-backups di server lama / dari local)
unzip /path/ke/2026-04-30-02-30-15.zip -d /tmp/restore
cd /tmp/restore

# 2. Cari dump database
ls db-dumps/
# misal: mysql-akhpremium.sql.gz

# 3. Decompress
gunzip db-dumps/mysql-akhpremium.sql.gz

# 4. Wipe database lama (HATI-HATI di produksi)
mysql -u akhpremium -p -e "DROP DATABASE akhpremium; CREATE DATABASE akhpremium CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 5. Import
mysql -u akhpremium -p akhpremium < db-dumps/mysql-akhpremium.sql
```

### Restore file storage

```bash
# Salin balik semua isi storage/app dari backup
rsync -av /tmp/restore/storage/app/  /var/www/akhpremium/storage/app/
sudo chown -R www-data:www-data /var/www/akhpremium/storage
```

### Restore .env

Dari ZIP juga, atau buat baru dengan kredensial baru. Pastikan `APP_KEY` **sama** dengan sebelumnya — kalau tidak, semua data terenkripsi (cookies, sessions, encrypted columns) tidak bisa di-decrypt.

### Final

```bash
cd /var/www/akhpremium
php artisan storage:link
php artisan optimize:clear
php artisan optimize
sudo systemctl reload php8.3-fpm nginx
sudo supervisorctl restart akhpremium-worker:*
```

Verifikasi:
- Login admin → `/admin` → cek dashboard widget (revenue, status order).
- Cek halaman publik → produk muncul, gambar tampil.
- Trigger 1x bayar test via Pakasir → status update.

---

## 12. Pemeliharaan Rutin (Checklist)

| Frekuensi | Aksi |
|---|---|
| Harian | Cek notifikasi Telegram backup. |
| Mingguan | Login `/admin/manage-backups` → cek size backup terbaru wajar. Download 1 file ZIP & test extract di lokal. |
| Bulanan | `composer outdated` — review minor updates. `npm audit`. |
| Tahunan | Renew SSL (auto via cron) & backup off-site key rotation. |

---

## 13. Troubleshooting

### "Backup failed: mysqldump command not found"
Install: `sudo apt install -y mysql-client`.

### "sqlite3 not found" (saat dev)
Install: `sudo apt install -y sqlite3` — wajib utk `backup:run` di environment SQLite.

### Webhook Pakasir tidak masuk
- Cek URL di dashboard Pakasir = `https://yourdomain.com/webhooks/pakasir` (tanpa basic auth).
- Cek log Laravel: `tail -f storage/logs/laravel.log` saat user simulate paid.

### Bot Telegram tidak respon
```bash
curl "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/getWebhookInfo"
```
Pastikan `url` benar dan `last_error_message` kosong.

### Schedule tidak jalan
```bash
crontab -l                # pastikan baris `* * * * * ...schedule:run` ada
php artisan schedule:test # test interactive
```

---

## Selesai

Semua sistem (web, admin, bot Telegram, scheduler, queue, backup) seharusnya sudah berjalan. Jika ada masalah, cek `storage/logs/laravel.log` lalu hubungi developer.
