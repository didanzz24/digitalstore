# Akhpremium Store — REST API Reference

API publik untuk integrasi pihak ketiga (reseller, integrator, mobile app).
Semua endpoint mengembalikan **JSON** dan menggunakan otentikasi `X-API-KEY`.

- **Base URL produksi:** `https://store.example.com/api`
- **Versi:** `v1`
- **Format:** JSON (charset UTF-8)
- **Time zone:** semua timestamp dalam ISO-8601 UTC (`Z` suffix)

> **Catatan:** Endpoint internal (admin Filament, webhook Pakasir, halaman web)
> tidak terdokumentasi di sini — itu untuk dashboard internal saja.

---

## 1. Otentikasi

Setiap request **harus** menyertakan header:

```
X-API-KEY: ak_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

API key dibuat di:

- **Admin panel:** `/admin → Public API → Api Clients → New` (untuk integrator
  global, opsional dengan IP whitelist).
- **User dashboard:** `/akun/api-key` — hanya tersedia untuk member aktif. Key
  ini auto-generate per user dan bisa di-revoke.

Setiap API key memiliki:

| Atribut         | Default | Keterangan                                                |
| --------------- | ------- | --------------------------------------------------------- |
| `rate_limit`    | 120/min | Berlaku per-key, tetap di-cap dengan throttle global juga |
| `ip_whitelist`  | kosong  | CSV IPv4. Kosong = tidak dibatasi.                        |
| `is_active`     | `true`  | Set `false` untuk revoke instan.                          |
| `expires_at`    | null    | Optional. Setelah lewat, request ditolak 401.             |

### Response error otentikasi

| Status | `error.code`       | Kapan                                                            |
| ------ | ------------------ | ---------------------------------------------------------------- |
| 401    | `missing_api_key`  | Header `X-API-KEY` tidak diisi                                   |
| 401    | `invalid_api_key`  | Key tidak ditemukan / sudah di-revoke / sudah expired            |
| 403    | `ip_not_allowed`   | IP request tidak ada di `ip_whitelist`                           |
| 429    | `rate_limited`     | Melewati `rate_limit` per key (header `Retry-After` disertakan)  |

Contoh body error:

```json
{
  "error": {
    "code": "invalid_api_key",
    "message": "API key tidak valid atau sudah dinonaktifkan."
  }
}
```

---

## 2. Rate limiting

Ada 2 layer:

1. **Per IP (Laravel global):** 120 request / menit untuk seluruh `/api/v1/*`.
2. **Per key (per `ApiClient.rate_limit`):** default 120 / menit, customizable.

Header response selalu menyertakan:

```
X-RateLimit-Limit: 120
X-RateLimit-Remaining: 117
```

Saat 429, juga ada:

```
Retry-After: 42
```

---

## 3. Endpoints

### 3.1 List products

Mengembalikan daftar produk dengan paginasi.

```
GET /api/v1/products
```

**Query params**

| Param      | Tipe    | Default | Keterangan                                  |
| ---------- | ------- | ------- | ------------------------------------------- |
| `q`        | string  | —       | Filter berdasarkan nama (LIKE)              |
| `category` | int     | —       | Filter berdasarkan `category.id`            |
| `per_page` | int     | 20      | Maks 100                                    |
| `page`     | int     | 1       | Halaman (1-based)                           |

**Contoh request**

```bash
curl -sS https://store.example.com/api/v1/products \
  -H "X-API-KEY: $API_KEY" \
  -G --data-urlencode "q=netflix" --data-urlencode "per_page=10"
```

**Contoh response 200**

```json
{
  "data": [
    {
      "id": 12,
      "name": "Netflix Premium",
      "short_description": "1 user, 4K UHD",
      "lowest_price": 25000,
      "is_best_seller": true,
      "category": { "id": 3, "name": "Streaming" },
      "image_url": "https://store.example.com/storage/products/netflix.png",
      "sold_count": 482,
      "variants": [
        {
          "id": 24,
          "name": "1 Bulan Privat",
          "price": 50000,
          "list_price": 60000,
          "stock_available": 12,
          "warranty_days": 30,
          "share_type": "private",
          "is_auto_send": true
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "last_page": 5,
    "total": 47
  }
}
```

### 3.2 Get product detail

```
GET /api/v1/products/{id}
```

Sama seperti list tapi 1 produk + field tambahan: `description` (HTML),
`terms_html`.

**Contoh request**

```bash
curl -sS https://store.example.com/api/v1/products/12 \
  -H "X-API-KEY: $API_KEY"
```

**Response error 404**

```json
{ "error": "Product not found" }
```

### 3.3 List categories

```
GET /api/v1/categories
```

**Contoh response**

```json
{
  "data": [
    { "id": 1, "name": "Streaming", "created_at": "2025-09-12T03:14:00Z" },
    { "id": 2, "name": "Productivity", "created_at": "2025-09-12T03:15:00Z" }
  ]
}
```

### 3.4 Cek status invoice (no auth)

Endpoint khusus untuk polling status pembayaran dari halaman invoice. Tidak
perlu API key, tetapi `order_code` 18-char unguessable + dilindungi rate-limit
60 req/menit per IP.

```
GET /api/invoice/{orderCode}/check
```

**Contoh request**

```bash
curl -sS https://store.example.com/api/invoice/AKH-2026-7HQ29F1L0/check
```

**Contoh response**

```json
{ "paid": false, "status": "pending" }
```

Setelah pembayaran masuk:

```json
{ "paid": true, "status": "paid" }
```

Status valid: `pending`, `paid`, `cancelled`, `expired`, `refunded`, `failed`.

---

## 4. Schema referensi

### 4.1 `Product`

| Field               | Tipe          | Catatan                                          |
| ------------------- | ------------- | ------------------------------------------------ |
| `id`                | int           | Primary key                                      |
| `name`              | string        |                                                  |
| `short_description` | string        | Untuk preview list                               |
| `lowest_price`      | int (rupiah)  | Harga terendah dari semua varian aktif           |
| `is_best_seller`    | bool          |                                                  |
| `category`          | object\|null  | `{id, name}`                                     |
| `image_url`         | string\|null  | URL absolut                                      |
| `sold_count`        | int           | Counter penjualan (display saja, bisa di-skew)   |
| `variants`          | array<Variant>|                                                  |
| `description`       | string (HTML) | **Hanya di endpoint detail**                     |
| `terms_html`        | string (HTML) | **Hanya di endpoint detail**                     |

### 4.2 `Variant`

| Field             | Tipe          | Catatan                                                            |
| ----------------- | ------------- | ------------------------------------------------------------------ |
| `id`              | int           |                                                                    |
| `name`            | string        | Mis. "1 Bulan Privat"                                              |
| `price`           | int (rupiah)  | Harga aktual yang dipakai (sudah cek flashsale, dll.)              |
| `list_price`      | int (rupiah)  | Harga tanpa diskon (untuk strikethrough display)                   |
| `stock_available` | int           | Jumlah stok ready untuk varian ini                                 |
| `warranty_days`   | int           | Garansi (hari) — 0 = tanpa garansi                                 |
| `share_type`      | string        | `private` (akun privat) / `share` (sharing)                        |
| `is_auto_send`    | bool          | Kalau true, kredensial dikirim otomatis ke buyer setelah PAID      |

### 4.3 `Category`

| Field        | Tipe   | Catatan       |
| ------------ | ------ | ------------- |
| `id`         | int    | Primary key   |
| `name`       | string |               |
| `created_at` | string | ISO-8601 UTC  |

---

## 5. Konvensi error

Selain `404 Product not found` dan format auth (lihat §1), endpoint generik
mengikuti konvensi Laravel:

| Status | Kapan                                      | Body                                          |
| ------ | ------------------------------------------ | --------------------------------------------- |
| 422    | Validasi parameter gagal                   | `{ "message": "...", "errors": {...} }`       |
| 429    | Rate limit dilampaui                       | `{ "message": "Too Many Attempts." }` + header|
| 500    | Bug server                                 | `{ "message": "Server Error" }` (debug=false) |

Aplikasi **tidak pernah** mengembalikan stack trace dalam respons publik
(`APP_DEBUG=false` di production).

---

## 6. Webhook (incoming)

Webhook untuk callback eksternal (Pakasir, Fonnte, Telegram). Webhook ini
**tidak** terbuka untuk integrator pihak ketiga — dipanggil oleh provider
sendiri.

| Path                          | Provider | Auth                                                    |
| ----------------------------- | -------- | ------------------------------------------------------- |
| `POST /webhooks/pakasir`      | Pakasir  | Sig signature di body, dicocokkan dengan `PAKASIR_API_KEY` |
| `POST /webhooks/fonnte`       | Fonnte   | Token di header `Authorization` = `FONNTE_INCOMING_TOKEN` |
| `POST /webhooks/telegram/{secret}` | Telegram | Path secret = `TELEGRAM_WEBHOOK_SECRET`                |

CSRF di-bypass khusus untuk path ini (configured di `bootstrap/app.php`).
Webhook log di-store di tabel `webhook_logs` & bisa dilihat di
`/admin → Public API → Webhook Logs`.

---

## 7. SDK & contoh integrasi

### 7.1 cURL

```bash
export API_KEY="ak_live_xxx"
export BASE="https://store.example.com/api/v1"

# List produk pertama 5
curl -sS "$BASE/products?per_page=5" -H "X-API-KEY: $API_KEY" | jq

# Detail produk id 12
curl -sS "$BASE/products/12" -H "X-API-KEY: $API_KEY" | jq
```

### 7.2 Node.js (fetch)

```js
const BASE = 'https://store.example.com/api/v1';
const KEY  = process.env.AKHPREMIUM_API_KEY;

async function listProducts(query) {
  const url = new URL(`${BASE}/products`);
  if (query) url.searchParams.set('q', query);

  const res = await fetch(url, {
    headers: { 'X-API-KEY': KEY, 'Accept': 'application/json' },
  });
  if (!res.ok) {
    throw new Error(`API ${res.status} ${await res.text()}`);
  }
  return res.json();
}
```

### 7.3 PHP (Guzzle)

```php
$client = new \GuzzleHttp\Client([
    'base_uri' => 'https://store.example.com/api/',
    'headers'  => [
        'X-API-KEY' => $_ENV['AKHPREMIUM_API_KEY'],
        'Accept'    => 'application/json',
    ],
    'timeout'  => 10,
]);

$res = $client->get('v1/products', ['query' => ['q' => 'netflix']]);
$data = json_decode((string) $res->getBody(), true);
```

---

## 8. Versioning & deprecation

- Path `/api/v1/*` di-stabilize sejak rilis 1.0. Field baru di response
  bersifat additive (tidak breaking).
- Bila ada breaking change, akan dirilis ke `/api/v2/*` paralel selama 90 hari
  sebelum `/v1` di-deprecate.
- Deprecation note akan di-blast lewat email user pemilik API key + halaman
  changelog di `/admin → Public API → Api Clients` (info banner).

---

Lihat juga: [`docs/security.md`](security.md) untuk model keamanan aplikasi
secara keseluruhan.
