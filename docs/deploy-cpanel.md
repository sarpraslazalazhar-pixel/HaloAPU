# Deploy cPanel (tanpa Reverb)

cPanel tidak bisa menjalankan WebSocket server, jadi aplikasi berjalan tanpa
realtime. Semua fitur tetap bekerja, hanya pembaruannya lewat polling.

## 0. Struktur folder di hosting

Domain `haloapu.apudev.id` memakai docroot `public_html/haloapu.apudev.id`.
Karena aplikasi Laravel harus menaruh `index.php` di docroot, pakai salah satu:

**A. Isi docroot dengan isi `public/`** (paling rapi)

```
public_html/haloapu.apudev.id/          <- isi folder public/ aplikasi
    index.php  .htaccess  build/  images/  storage -> ../storage/app/public
home/USERNAME/haloapu/                  <- sisa aplikasi (app, vendor, storage, ...)
```

Lalu di `public_html/haloapu.apudev.id/index.php` sesuaikan dua baris:

```php
require __DIR__.'/../../haloapu/vendor/autoload.php';
$app = require_once __DIR__.'/../../haloapu/bootstrap/app.php';
```

**B. Upload seluruh aplikasi ke docroot** dan biarkan `.htaccess` root
mengarahkan ke `public/` (repo ini sudah punya). Cara ini paling mudah diunggah,
tapi seluruh kode (termasuk `.env`) berada di dalam docroot — pastikan
`.htaccess` root benar-benar aktif. Repo sudah memblokir `.env`, `.git`,
`composer.json`, dan `phpunit.xml`, tapi folder `app/`, `vendor/`, dan
`storage/` tetap bisa diakses lewat URL langsung. **Opsi A lebih aman.**

Kalau memakai opsi A, jalankan `php artisan storage:link` supaya `public/storage`
mengarah ke `storage/app/public`.

Jalankan `npm run build` dari root proyek sebelum mengunggah rilis. Unggah
`public/build/` (termasuk `manifest.json`) bersama kode Laravel dari rilis yang
sama. Jika rilis menambah migrasi, jalankan `php artisan migrate --force` di
hosting sebelum membuka rilis untuk pengguna.

## 1. `.env` produksi

```dotenv
APP_ENV=production
APP_URL=https://haloapu.apudev.id
BROADCAST_CONNECTION=null
QUEUE_CONNECTION=sync
CACHE_STORE=file
SESSION_DRIVER=file
```

- `BROADCAST_CONNECTION=null` mematikan siaran. Notifikasi tetap masuk ke tabel
  `notifications` dan lonceng notifikasi tetap berdetak lewat polling 15 detik.
- `QUEUE_CONNECTION=sync` karena cPanel umumnya tidak punya worker antrean yang
  hidup terus. Job dijalankan saat request, bukan ditumpuk ke tabel `jobs`.
- Jangan isi `REVERB_*`. Kalau dibiarkan, setiap siaran menunggu timeout ±2 detik.

Kalau hosting Anda punya Node.js (jarang di shared hosting), Reverb tetap bisa
dipakai: set `BROADCAST_CONNECTION=reverb` dan jalankan
`php artisan reverb:start --host=0.0.0.0 --port=8080` lewat process manager.
Tanpa itu, biarkan `null`.

## 2. Cron cPanel

Masuk **cPanel → Advanced → Cron Jobs**, pilih *Once Per Minute*, lalu isi:

```
/usr/local/bin/php /home/USERNAME/haloapu/artisan schedule:run >> /dev/null 2>&1
```

Kalau memilih opsi B (seluruh aplikasi di docroot), path-nya
`/home/USERNAME/public_html/haloapu.apudev.id/artisan`.

Ganti `/usr/local/bin/php` dengan path PHP CLI hosting Anda (cek **Select PHP
Version → PHP CLI**; sering `/opt/cpanel/ea-php83/root/usr/bin/php`).

Satu baris itu menjalankan semua jadwal di `routes/console.php`:

| Perintah | Frekuensi | Guna |
|---|---|---|
| `sla:check` | tiap menit | menandai pelanggaran & mengirim pengingat SLA |
| `booking:schedule-check` | tiap menit | status peminjaman aset |
| `reminder:booking` | 07:00 | pengingat jadwal |
| `reminder:pending` | 08:30 | pengingat tiket tertunda |
| `reminder:csat` | 09:00 | pengingat rating |
| `reminder:snooze-check` | tiap 5 menit | membangunkan notifikasi snooze |
| `tickets:auto-solve` | tiap jam | menutup tiket yang selesai otomatis |

Verifikasi setelah menambah cron:

```bash
php artisan schedule:list        # daftar jadwal yang akan jalan
php artisan schedule:run -v      # jalankan sekali, lihat outputnya
```

Kalau `schedule:run` tidak menghasilkan apa pun, biasanya path PHP salah. Uji
`/usr/local/bin/php -v` lebih dulu lewat SSH atau Terminal cPanel.

## 3. Tanpa SSH

Beberapa cPanel tidak menyediakan SSH. Cron tetap bisa diisi lewat UI di atas.

Untuk perintah satu kali (mis. `php artisan migrate`), repo ini punya
`public/run.php`. Aktifkan hanya selama setup:

1. Isi `ARTISAN_RUNNER_PIN` di `.env` dengan PIN acak panjang.
2. Buka `https://haloapu.apudev.id/run.php`, masukkan PIN.
3. Jalankan yang perlu (migrate, storage:link, optimize:clear).
4. Klik **Hapus run.php Ini Sekarang**, atau hapus filenya lewat File Manager.

Tanpa `ARTISAN_RUNNER_PIN`, file itu membalas 404 dan tidak bisa dipakai.
Perintah kustom dibatasi allowlist baca/bersih-bersih (`route:list`,
`optimize:clear`, `migrate:status`, dst). Migrasi tetap punya tombol sendiri,
tetapi `db:seed` sudah dihapus dari UI karena seeder di repo ini men-truncate
tabel referensi (`admins`, `users`, `form_fields`, `sub_units`, ...).

**Jangan pernah jalankan `db:seed --force` di server produksi.**

## 4. Yang berubah tanpa Reverb

| Fitur | Dengan Reverb | Tanpa Reverb (cPanel) |
|---|---|---|
| FAB tiket aktif | dorong langsung | polling 20 detik + saat tab kembali aktif |
| Lonceng notifikasi | dorong langsung | polling 15 detik |
| Penanda tiket perlu dinilai di menu Laporan | cek setelah navigasi/simpan dan tiap 30 detik saat tab aktif | sama, tanpa Reverb |
| Chat tiket | dorong langsung | polling 10 detik |
| Timeline tiket | dorong langsung | muat ulang halaman |
| Pengingat & pelanggaran SLA | `sla:check` tiap menit | sama, lewat cron |

Tidak ada fitur yang hilang, hanya jeda pembaruan beberapa detik.
