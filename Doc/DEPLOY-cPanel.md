# Deploy ke cPanel (Tanpa SSH)

## 1. Persiapan Lokal

```bash
composer install --no-dev --optimize-autoloader
npm run build
```

**Sementara** edit `.gitignore` — hapus line `/vendor` dan `/public/build`. Lalu:

```bash
git add -f vendor public/build
git commit -m "chore: include vendor & build for deploy"
git push
```

Kembalikan `.gitignore` setelah push (biar dev biasa不乱).

---

## 2. Setup cPanel

| Langkah | Perintah |
|---|---|
| **Buat DB MySQL** | MySQL Database Wizard → bikin DB + user, catet nama/user/pass |
| **Clone repo** | Git Version Control → Create → paste URL repo GitHub/GitLab → clone ke folder (misal: `halo-apu`) |
| **Set document root** | Domains → pilih domain → Document Root → `halo-apu/public` |
| **PHP version** | Select PHP Version → pilih 8.2+ → centang extension: `bcmath`, `ctype`, `fileinfo`, `json`, `mbstring`, `openssl`, `PDO`, `pdo_mysql`, `tokenbar`, `xml` |

---

## 3. `.env` via File Manager

Buka folder `halo-apu` → copy `.env.example` → rename ke `.env` → edit:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domainkamu.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=nama_database
DB_USERNAME=user_database
DB_PASSWORD=password_database
```

---

## 4. One-Time Setup

Buka browser → akses `https://domainkamu.com/setup.php`

File ini akan menjalankan: `key:generate`, `migrate`, `storage:link`, `config:cache`, `route:cache`, `view:cache`.

**Setelah selesai → HAPUS `setup.php` dari server.**

---

## 5. Update Selanjutnya

### Opsi A — Via Git (cepat)

Push update biasa → di cPanel Git Version Control klik **Pull**.

### Opsi B — Upload vendor via FTP

Setelah deploy pertama, `vendor/` bisa di-ignore dari git lagi.
Update vendor: `composer install --no-dev` di lokal → upload folder `vendor/` via FTP.

**Kenapa vendor ikut commit pertama?** Karena tanpa SSH, tidak ada `composer install` di server. Vendor harus sudah ada.

---

## Troubleshooting

| Error | Solusi |
|---|---|
| 500 Server Error | Cek PHP version & extension di Select PHP Version |
| Class not found | `vendor/` tidak terupload — upload via FTP atau commit dengan git |
| SQLSTATE[HY000] | Cek kredensial DB di `.env` |
| Storage not writable | `chmod 755 storage/` via File Manager (rekursif) |
| 404 selain homepage | `php artisan route:cache` belum jalan — jalankan ulang via browser |
