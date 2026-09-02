# PLAN FASE 6: Notifikasi Pengajuan & Fitur CSAT

Fase ini berfokus pada penambahan notifikasi (melalui Email dan WhatsApp) untuk aktivitas krusial tiket serta menyediakan mekanisme pengumpulan *Customer Satisfaction* (CSAT) setelah tiket diselesaikan.

## 1. Deskripsi Kebutuhan

Berdasarkan diskusi dengan *stakeholder*, terdapat kebutuhan baru yang diidentifikasi:
1. **Notifikasi Tiket Baru**: Sistem harus mengirimkan pemberitahuan ketika Pelapor berhasil mengajukan tiket. Notifikasi dikirim kepada:
   - Pelapor (sebagai konfirmasi).
   - Admin / Operator unit terkait (sebagai *alert* untuk ditindaklanjuti).
2. **Form CSAT (Customer Satisfaction)**: Saat tiket ditutup (status `solve`), sistem harus mengirim notifikasi kepada Pelapor yang berisi link ke form penilaian CSAT.
3. **Mekanisme Form CSAT**: 
   - Dibangun secara *in-house* di dalam aplikasi (bukan form eksternal seperti Google Forms).
   - Menggunakan sistem *Star Rating* (Bintang 1 hingga 5).
   - Menyediakan teks area opsional untuk ulasan atau komentar.
   - Link CSAT dapat diakses oleh Pelapor dengan otentikasi standar (harus login, tetapi memanfaatkan "Remember Me" jika sesi masih aktif di perangkat pengguna).

---

## 2. Struktur Database

### `tickets` table (Update)
- Tambahkan kolom `csat_rating` (integer, nullable).
- Tambahkan kolom `csat_comment` (text, nullable).

*Catatan: Alternatifnya adalah membuat tabel terpisah `ticket_csats`, namun mengingat relasi yang strictly 1-to-1, menaruhnya di tabel `tickets` sudah cukup efisien.*

---

## 3. Komponen Sistem (Backend)

### 3.1 Notifikasi Class
Akan dibuat 2 notifikasi baru menggunakan fitur Laravel Notification:

1. `TicketSubmittedNotification`
   - **Trigger**: `TicketWizardController@store`
   - **Recipient**: `Pelapor` (User pembuat tiket) & `Admin` terkait.
   - **Channels**: `mail`, `whatsapp` (Gateway).
   - **Content**: Nomor tiket, ringkasan, dan tautan untuk melacak status.

2. `TicketCompletedNotification`
   - **Trigger**: `Admin/TicketController@updateStatus` (saat `$newStatus === 'solve'`)
   - **Recipient**: `Pelapor`.
   - **Channels**: `mail`, `whatsapp` (Gateway).
   - **Content**: Pemberitahuan tiket selesai dan **Tautan form CSAT**.

### 3.2 Controllers & Routes
1. **Web Routes (`routes/web.php`)**:
   - `GET /tiket/{ticket}/csat` -> `User\TicketHistoryController@showCsatForm`
   - `POST /tiket/{ticket}/csat` -> `User\TicketHistoryController@submitCsat`
   - *Rute ini harus diamankan dengan middleware `auth`.*
2. **`User\TicketHistoryController.php`**:
   - `showCsatForm`: Menampilkan halaman CSAT (Inertia Render).
   - `submitCsat`: Menerima `rating` (1-5, required) dan `komentar` (string, nullable), lalu menyimpan nilainya ke tabel `tickets`.
3. **Model `Ticket.php`**:
   - Update `$fillable` menambahkan `csat_rating` dan `csat_comment`.

---

## 4. Komponen Sistem (Frontend - React/Inertia)

### 4.1 Halaman Form CSAT (`resources/js/Pages/User/Tiket/CsatForm.tsx`)
- Form yang interaktif untuk Pelapor.
- Komponen *Star Rating* yang merespons sentuhan/klik.
- Textarea untuk komentar pengalaman.
- Tombol "Kirim Ulasan".

### 4.2 Halaman Detail Tiket Admin (`resources/js/Pages/Admin/Tiketing/Detail.tsx`)
- Jika `csat_rating` sudah terisi, tampilkan informasi tersebut (berupa ikon bintang dan komentar Pelapor) di panel detail tiket.
- Jika belum diisi, dapat disembunyikan atau ditampilkan label "Belum dinilai".

---

## 5. Prasyarat & Asumsi
- **Modul Konfigurasi WA (Fase 5)**: Dianggap sudah berjalan atau sedang disiapkan. Jika API WA belum disambung, Notifikasi WhatsAppChannel harus didesain agar tidak merusak sistem (*graceful degradation*), cukup melakukan *log* kegagalan.
- Fitur Queue (Worker): Pengiriman email dan WA wajib dilempar ke antrean (*queue*) dengan driver `database` atau `redis` agar tidak menghambat response time pengguna saat membuat tiket.
