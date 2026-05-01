# Panduan Deploy Akhpremium Store ke VPS + Domain

Panduan end-to-end mendeploy `digitalstore` ke VPS Linux (Ubuntu 22.04 LTS atau
Debian 12) dengan Nginx sebagai reverse proxy ke PHP-FPM, MySQL untuk
penyimpanan, Redis untuk cache & queue, Supervisor untuk worker, dan Let's
Encrypt untuk HTTPS.

> **Spesifikasi minimum VPS:** 1 vCPU, 2 GB RAM, 30 GB SSD.
> **Direkomendasikan untuk produksi:** 2 vCPU, 4 GB RAM, 50 GB SSD + automated backup.

Daftar isi:

1. [Persiapan VPS dasar](#1-persiapan-vps-dasar)
2. [Install software stack](#2-install-software-stack)
3. [Setup database](#3-setup-database)
4. [Clone & konfigurasi aplikasi](#4-clone--konfigurasi-aplikasi)
5. [Setup queue worker (Supervisor) & cron scheduler](#5-setup-queue-worker-supervisor--cron-scheduler)
6. [Setup Nginx + domain + HTTPS (Let's Encrypt)](#6-setup-nginx--domain--https-lets-encrypt)
7. [Hardening firewall (UFW + fail2ban)](#7-hardening-firewall-ufw--fail2ban)
8. [Backup otomatis](#8-backup-otomatis)
9. [Update / deploy ulang](#9-update--deploy-ulang)
10. [Troubleshooting](#10-troubleshooting)

---

## 1. Persiapan VPS dasar

```bash
# Login ke VPS sebagai root, lalu buat user non-root.
adduser akhpremium
usermod -aG sudo akhpremium

# Salin SSH key root → user supaya bisa login langsung sebagai user.
rsync --archive --chown=akhpremium:akhpremium ~/.ssh /home/akhpremium

# Disable login root via SSH (rekomendasi).
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart ssh

# Set timezone (sesuaikan).
timedatectl set-timezone Asia/Jakarta
```

Login ulang sebagai `akhpremium`, lalu update sistem:

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y curl wget git unzip software-properties-common ca-certificates
```

## 2. Install software stack

### 2.1 PHP 8.3 + ekstensi

```bash
sudo add-apt-repository -y ppa:ondrej/php   # Ubuntu — di Debian: pakai sury.org
sudo apt update
sudo apt install -y \
    php8.3-fpm php8.3-cli \
    php8.3-mysql php8.3-mbstring php8.3-xml php8.3-bcmath \
    php8.3-curl php8.3-gd php8.3-intl php8.3-zip php8.3-gmp \
    php8.3-redis php8.3-sqlite3
```

### 2.2 Composer 2.x

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version    # pastikan 2.6+
```

### 2.3 Node.js 20 LTS

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash -
sudo apt install -y nodejs
node -v && npm -v
```

### 2.4 MySQL 8 (atau MariaDB 10.6+)

```bash
sudo apt install -y mysql-server
sudo mysql_secure_installation        # set root password, hapus user anonim
```

### 2.5 Redis 7 (cache + queue)

```bash
sudo apt install -y redis-server
sudo systemctl enable --now redis-server
redis-cli ping     # → PONG
```

### 2.6 Supervisor (untuk queue worker)

```bash
sudo apt install -y supervisor
```

### 2.7 Nginx + Certbot

```bash
sudo apt install -y nginx
sudo apt install -y certbot python3-certbot-nginx
```

## 3. Setup database

```bash
sudo mysql -uroot -p
```

Di prompt MySQL:

```sql
CREATE DATABASE akhpremium CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'akhpremium'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_KUAT_DI_SINI';
GRANT ALL PRIVILEGES ON akhpremium.* TO 'akhpremium'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

> **Catatan:** Password DB tidak perlu kuat seperti password admin (DB tidak
> exposed ke internet), tapi minimal 16 karakter random.

## 4. Clone & konfigurasi aplikasi

```bash
# Direktori web. /var/www adalah konvensi standar.
sudo mkdir -p /var/www
sudo chown akhpremium:akhpremium /var/www
cd /var/www
git clone https://github.com/Dandutzz/digitalstore.git akhpremium
cd akhpremium

# Install dependency PHP & build asset frontend.
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# Setup .env.
cp .env.example .env
php artisan key:generate

# Edit .env — minimal:
# APP_ENV=production
# APP_DEBUG=false
# APP_URL=https://store.example.com
# DB_HOST=127.0.0.1
# DB_DATABASE=akhpremium
# DB_USERNAME=akhpremium
# DB_PASSWORD=<password tadi>
# CACHE_STORE=redis
# QUEUE_CONNECTION=redis
# SESSION_DRIVER=redis
# HASH_DRIVER=argon2id          # default — recommended
# MAIL_MAILER=smtp              # set kalau pakai SMTP eksternal
# Pakasir / Eqris / Telegram credentials sesuai akun masing-masing.

nano .env

# Migrasi & seed.
php artisan migrate --seed --force

# Buat symlink storage → public.
php artisan storage:link

# Optimize untuk production.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components
```

Permission folder yang harus writable oleh PHP-FPM (`www-data` di Ubuntu):

```bash
sudo chown -R akhpremium:www-data storage bootstrap/cache
sudo chmod -R 0775 storage bootstrap/cache
```

Buat akun admin pertama lewat artisan tinker:

```bash
php artisan tinker
>>> \App\Models\User::create([
...     'name' => 'Admin',
...     'email' => 'admin@store.example.com',
...     'password' => \Illuminate\Support\Facades\Hash::make('GantiPasswordKuat#2025!'),
...     'is_admin' => true,
... ]);
>>> exit
```

> **Penting:** Password admin **harus** memenuhi
> [PasswordPolicy](../app/Support/PasswordPolicy.php) (min 10 karakter, huruf
> besar+kecil, angka, simbol). Hash akan otomatis pakai argon2id sesuai
> `HASH_DRIVER`.

## 5. Setup queue worker (Supervisor) & cron scheduler

### 5.1 Cron scheduler

Pasang Laravel scheduler ke crontab user `akhpremium`:

```bash
crontab -u akhpremium -e
```

Tambahkan baris:

```
* * * * * cd /var/www/akhpremium && php artisan schedule:run >> /dev/null 2>&1
```

### 5.2 Supervisor untuk queue worker

```bash
sudo nano /etc/supervisor/conf.d/akhpremium-worker.conf
```

Isi:

```ini
[program:akhpremium-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/akhpremium/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=akhpremium
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/akhpremium-worker.log
stopwaitsecs=3600
```

Aktifkan:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start akhpremium-worker:*
sudo supervisorctl status
```

## 6. Setup Nginx + domain + HTTPS (Let's Encrypt)

### 6.1 Pointing domain ke VPS

Login ke registrar / Cloudflare, buat DNS record:

| Type | Name              | Value (IP VPS) | TTL  |
| ---- | ----------------- | -------------- | ---- |
| A    | `store`           | 203.0.113.10   | Auto |
| A    | `www.store`       | 203.0.113.10   | Auto |

> Tunggu propagasi (biasanya < 5 menit kalau pakai Cloudflare; max 24 jam DNS
> tradisional). Verifikasi dengan `dig +short store.example.com`.

### 6.2 Konfig Nginx

```bash
sudo nano /etc/nginx/sites-available/akhpremium
```

Isi:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name store.example.com www.store.example.com;
    root /var/www/akhpremium/public;
    index index.php;

    # Security headers (selain yang diset oleh aplikasi)
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    server_tokens off;

    # Larang akses ke hidden files & sources sensitif
    location ~ /\.(?!well-known) { deny all; }
    location ~* \.(env|git|md|lock|json|toml|yml|yaml)$ { deny all; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_read_timeout 60s;
    }

    # Static asset caching
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|woff2?|svg|webp)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    client_max_body_size 16M;
    access_log /var/log/nginx/akhpremium.access.log;
    error_log /var/log/nginx/akhpremium.error.log;
}
```

Aktifkan:

```bash
sudo ln -s /etc/nginx/sites-available/akhpremium /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

### 6.3 HTTPS via Let's Encrypt

```bash
sudo certbot --nginx -d store.example.com -d www.store.example.com --redirect --agree-tos -m admin@store.example.com -n
```

Certbot akan:

1. Terbitkan sertifikat gratis dari Let's Encrypt.
2. Edit otomatis konfig Nginx untuk `listen 443 ssl http2;`.
3. Pasang redirect 80 → 443.
4. Pasang systemd timer `certbot.timer` untuk auto-renew tiap 60 hari.

Verifikasi:

```bash
sudo systemctl list-timers | grep certbot
curl -I https://store.example.com   # → 200 OK + HSTS header dari aplikasi
```

### 6.4 Update `.env` setelah HTTPS aktif

```env
APP_URL=https://store.example.com
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=store.example.com
TRUSTED_PROXIES=*           # kalau di belakang Cloudflare/load balancer
```

Lalu:

```bash
php artisan config:cache
```

## 7. Hardening firewall (UFW + fail2ban)

```bash
# UFW: hanya buka port yang perlu.
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'   # 80 + 443
sudo ufw enable

# Fail2ban: block IP yang brute-force SSH/Nginx.
sudo apt install -y fail2ban
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local
sudo systemctl enable --now fail2ban
sudo fail2ban-client status sshd
```

> Aplikasi sendiri **sudah punya rate-limit per IP + brute-force monitor**
> (lihat <code>app/Support/SecurityMonitor.php</code>). Fail2ban menjadi
> lapisan di OS, di bawah aplikasi.

## 8. Backup otomatis

Aplikasi sudah punya `BackupService` + scheduler harian di `routes/console.php`.
Untuk backup eksternal, tambahkan rclone ke object storage (S3, R2, Wasabi):

```bash
sudo apt install -y rclone
rclone config       # konfigurasi remote, mis. nama "r2"
```

Tambahkan cronjob harian:

```cron
30 2 * * * rclone copy /var/www/akhpremium/storage/app/backups r2:akhpremium-backups --max-age 7d >> /var/log/akhpremium-backup-rclone.log 2>&1
```

## 9. Update / deploy ulang

```bash
cd /var/www/akhpremium
git fetch origin
git pull --ff-only origin main      # atau branch produksi
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components
sudo supervisorctl restart akhpremium-worker:*
```

Untuk zero-downtime, pakai workflow blue/green via folder release + symlink `current`.

## 10. Troubleshooting

| Masalah                                 | Cek dulu                                                                                                  |
| --------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| 502 Bad Gateway                         | `sudo systemctl status php8.3-fpm` — restart kalau perlu.                                                 |
| 500 Server Error                        | `tail -n 100 storage/logs/laravel.log`. Pastikan `storage/` writable oleh `www-data`.                     |
| Asset (CSS/JS) 404                      | Lupa `npm run build`, atau `php artisan storage:link` belum jalan.                                        |
| Login Filament admin "URL signature"    | `APP_URL` di `.env` harus match domain HTTPS persis. Setelah ubah, `php artisan config:cache`.            |
| Webhook Pakasir tidak masuk             | Cek `/admin → Webhook Logs`. Pastikan domain bisa diakses publik via HTTPS dan path `/webhooks/pakasir`.  |
| Queue worker tidak jalan                | `sudo supervisorctl status akhpremium-worker:*`. Restart: `sudo supervisorctl restart akhpremium-worker:*`. |
| Telegram bot tidak push                 | Cek `TELEGRAM_BOT_TOKEN`, `TELEGRAM_ADMIN_CHAT_ID` di `.env`. Test: `php artisan telegram:test-notify`. |
| Brute-force alert tidak masuk           | Lihat `/admin → Audit Log` (filter "Hanya security alerts"). Pastikan Telegram bot configured di `.env`.  |
| Browser warning "Mixed Content"         | `APP_URL` masih `http://`. Update ke `https://` + `php artisan config:cache`.                              |

Untuk dokumentasi keamanan & audit lebih lengkap, lihat
[`docs/security.md`](security.md). Untuk dokumentasi API publik, lihat
[`docs/api.md`](api.md).
