# Laporan Implementasi: Modul Login & Autentikasi (Admin & User)

**Proyek:** Halo APU v2  
**Tanggal Laporan:** 9 Juli 2026  
**Fase:** 1 (Setup, Auth, Master Data)

---

## 1. Arsitektur Multi-Auth

Sistem Halo APU v2 menggunakan kapabilitas _multi-guard_ bawaan Laravel untuk memisahkan sesi pengguna reguler (User) dan administrator (Admin). Hal ini diatur di dalam `config/auth.php`.

*   **Guard Web (User):** Digunakan oleh pengguna biasa (Staff, Karyawan) yang akan membuat tiket. Model yang digunakan adalah `App\Models\User`.
*   **Guard Admin:** Digunakan oleh staf IT/Helpdesk dan manajer yang mengelola _master data_ serta menindaklanjuti tiket. Model yang digunakan adalah `App\Models\Admin`.

---

## 2. Struktur Database

Tabel yang digunakan untuk autentikasi dan otorisasi:

1.  **`users`**: Menyimpan kredensial pengguna biasa, terintegrasi dengan struktur organisasi (`divisi_id`, `org_unit_id`, `jabatan_id`).
2.  **`admins`**: Menyimpan kredensial administrator.
3.  **`password_reset_tokens`**: Menyimpan token bagi User yang melakukan permintaan reset password.
4.  **`admin_password_reset_tokens`**: (Opsional/Tersedia) Untuk mereset password khusus Admin.

---

## 3. Komponen Frontend (Inertia.js + React + Tailwind CSS)

Tampilan login dan manajemen akun dibangun menggunakan **React** dengan sentuhan **Tailwind CSS** untuk desain visual (*UI/UX*) yang seragam dan elegan (bertemakan *Back Office System* dengan aksen warna `#0088cc`).

### Halaman yang Tersedia:
*   **`resources/js/Pages/Auth/UserLogin.tsx`**: Form masuk untuk pengguna reguler (mengarah ke route `/login`). Dilengkapi dengan tombol ke halaman Lupa Password dan Hubungi Admin.
*   **`resources/js/Pages/Auth/AdminLogin.tsx`**: Form masuk eksklusif untuk Admin (mengarah ke route `/admin/login`). Tampilan visual dibedakan dengan _badge_ bertuliskan "Administrator Panel".
*   **`resources/js/Pages/Auth/Register.tsx`**: Karena sistem internal tidak melayani pendaftaran bebas (_self-registration_), halaman ini berfungsi sebagai *landing page* informasi (mengarah ke route `/register`) yang mengarahkan pengguna untuk menghubungi pihak Administrator/IT.
*   **`resources/js/Pages/Auth/ForgotPassword.tsx`**: Form bagi User untuk memasukkan email pemulihan akun.
*   **`resources/js/Pages/Auth/ResetPassword.tsx`**: Form bagi User untuk mengetikkan *password* baru setelah mengklik tautan dari email.

---

## 4. Komponen Backend (Laravel Controllers)

Logika pemrosesan data (backend) dipisahkan secara rapi menggunakan _Controller_:

### A. Login & Logout
*   **`UserLoginController`**: Menangani otentikasi _guard_ `web`. 
    *   *Method Login*: Memvalidasi kredensial (mendukung login via email ataupun username) menggunakan `Auth::attempt()`.
    *   *Method Logout*: Menghentikan sesi (`Auth::logout()`), membatalkan (invalidate) _session_, dan memperbarui token CSRF.
*   **`AdminLoginController`**: Menangani otentikasi _guard_ `admin`. Sama seperti *user*, namun secara spesifik memanggil `Auth::guard('admin')->attempt()`.

### B. Lupa & Reset Password
*   **`ForgotPasswordController`**:
    *   `sendResetLink()`: Meminta `Password::broker('users')` untuk men-*generate* token aman dan mengirimkannya ke email tujuan.
    *   `resetPassword()`: Memvalidasi token, mencocokkan email, memastikan konfirmasi password, lalu memperbarui data di tabel `users`.

---

## 5. Rute (Routes - `web.php`)

Routing dikelompokkan berdasarkan peran untuk melindungi halaman sensitif dari akses yang tidak sah (menggunakan *Middleware*).

**Grup User (Pengguna Biasa):**
```php
Route::middleware('guest')->group(function () {
    Route::get('login', [UserLoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [UserLoginController::class, 'login']);
    
    // Fitur Tambahan
    Route::get('register', ...)->name('register');
    Route::get('/lupa-password', ...)->name('password.request');
    // dll...
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [UserLoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', ...)->name('dashboard'); // Dashboard User
});
```

**Grup Admin:**
```php
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminLoginController::class, 'showLoginForm'])->name('login');
        Route::post('login', [AdminLoginController::class, 'login']);
    });

    Route::middleware('auth:admin')->group(function () {
        Route::post('logout', [AdminLoginController::class, 'logout'])->name('logout');
        Route::get('/dashboard', ...)->name('dashboard'); // Dashboard Admin
    });
});
```

---

## 6. Email Custom & Notifikasi (Mailtrap)

Untuk fitur **Lupa Password**, telah dikonfigurasi SMTP Mailtrap (*Sending API*) agar email sungguhan dapat dikirim ke kontak pengguna. 
Tampilan email *default* Laravel telah dioverride atau diganti dengan cara:
1.  Menerbitkan (*publish*) _mail template_ milik Laravel ke direktori `resources/views/vendor/mail`.
2.  Memodifikasi warna tombol pada `themes/default.css` menjadi biru Halo APU (`#0088cc`).
3.  Memasukkan logo *custom* Halo APU ke bagian atas (`header.blade.php`).
4.  Membuat kelas notifikasi baru **`CustomResetPasswordNotification`** yang ditautkan ke model `User` guna mengubah teks surel (email) menjadi Bahasa Indonesia secara menyeluruh.

---

## Kesimpulan

Fase autentikasi (Fase 1 Auth) telah diselesaikan dengan struktur keamanan yang kokoh dan desain visual interaktif yang konsisten. Memisahkan *User* dan *Admin* secara absolut di lapisan *database*, *route*, *guard*, dan antarmuka *login* merupakan praktik terbaik yang menjamin sistem Halo APU dapat dikelola secara andal ke depannya.
