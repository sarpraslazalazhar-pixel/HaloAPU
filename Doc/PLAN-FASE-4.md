# PLAN FASE 4 — Modul Reminder & Notification Center

> **Proyek:** Halo APU v2 — Sistem Tiketing Internal  
> **Versi Dokumen:** 1.0  
> **Tanggal:** 2026-07-09  
> **Prasyarat:** Fase 1, 2, & 3 sudah selesai (auth, CRUD tiket, booking, SLA lengkap)

---

## Daftar Isi

1. [Empat Jenis Reminder](#1-empat-jenis-reminder)
2. [Laravel Notification Classes](#2-laravel-notification-classes)
3. [Konfigurasi Reminder](#3-konfigurasi-reminder)
4. [Notification Center UI](#4-notification-center-ui)
5. [Notifikasi Suara](#5-notifikasi-suara)
6. [Scheduler Commands Baru](#6-scheduler-commands-baru)
7. [File-by-File Checklist](#7-file-by-file-checklist)

---

## 1. Empat Jenis Reminder

### 1.1 Ringkasan

| # | Jenis Reminder | Trigger | Target Penerima | Channel Default |
|---|---------------|---------|-----------------|-----------------|
| 1 | Reminder Booking | Scheduler: `tanggal_mulai = today + lead_time` | Admin unit terkait | In-app, Email |
| 2 | Reminder SLA | `CheckSlaCommand` (Fase 3) saat tier naik | Admin/Operator unit + Supervisor (Tier 3) | In-app, Email, WA (Tier 3) |
| 3 | Reminder Tiket Pending Lama | Scheduler: `status=Pending AND updated_at < now() - X hari` | Admin unit terkait | In-app, Email |
| 4 | Reminder CSAT Belum Diisi | Scheduler: `status=Solve AND NOT EXISTS csat AND updated_at < now() - X hari` | User pemilik tiket | In-app, Email, WA |

### 1.2 Detail per Jenis

#### 1.2.1 Reminder Booking

**Trigger Condition:**
```sql
SELECT * FROM room_vehicle_bookings
WHERE status = 'Disetujui'
  AND tanggal_mulai = CURDATE() + INTERVAL :lead_time DAY
  AND NOT EXISTS (
    SELECT 1 FROM notifications
    WHERE type = 'App\\Notifications\\BookingReminderNotification'
      AND JSON_EXTRACT(data, '$.booking_id') = room_vehicle_bookings.id
  );
```

**Data yang dikirim:**
```json
{
    "booking_id": 123,
    "tipe": "ruang",
    "nama_aset": "Ruang Rapat Lantai 3",
    "tanggal_mulai": "2026-07-15 09:00",
    "tanggal_selesai": "2026-07-15 11:00",
    "ticket_id": 456,
    "judul_tiket": "Peminjaman Ruang Rapat untuk Workshop",
    "pesan": "Booking ruang Anda dijadwalkan dalam 1 hari lagi."
}
```

**Target Penerima:**
- Admin yang di-assign ke tiket terkait (jika ada)
- Semua admin/operator di unit yang mengelola aset tersebut
- User pemilik tiket (opsional, konfigurasi)

**Lead Time Default:** 1 hari (konfigurasi via `reminder_configs`)

#### 1.2.2 Reminder SLA

**Sudah di-handle oleh `CheckSlaCommand` dari Fase 3.** Fase 4 hanya menambahkan:
- Konfigurasi channel per tier di `reminder_configs`
- Notification class yang lebih lengkap (`SlaEscalationNotification`)

**Data yang dikirim:**
```json
{
    "ticket_id": 456,
    "judul_tiket": "Printer Rusak",
    "unit": "IT",
    "sub_unit": "Hardware",
    "tier": 2,
    "jenis_breach": "respon",
    "elapsed_minutes": 65,
    "threshold_minutes": 60,
    "pesan": "SLA Tier 2 tercapai untuk tiket #456. Segera tangani!"
}
```

#### 1.2.3 Reminder Tiket Pending Lama

**Trigger Condition:**
```sql
SELECT * FROM tickets
WHERE status = 'Pending'
  AND updated_at < NOW() - INTERVAL :threshold_days DAY;
```

**Data yang dikirim:**
```json
{
    "ticket_id": 789,
    "judul_tiket": "AC Ruangan Tidak Dingin",
    "status": "Pending",
    "pending_sejak": "2026-07-01",
    "hari_pending": 8,
    "unit": "Umum",
    "sub_unit": "Fasilitas",
    "pesan": "Tiket #789 sudah pending selama 8 hari. Silakan tindak lanjuti."
}
```

**Target Penerima:**
- Admin yang di-assign ke tiket
- Semua admin/operator di unit terkait

**Threshold Default:** 3 hari (konfigurasi via `reminder_configs`)

**Mekanisme Anti-Spam:**
- Kirim reminder maksimal 1x per hari per tiket
- Cek: `notifications WHERE type = PendingTicketReminder AND data->ticket_id = X AND created_at > today`

#### 1.2.4 Reminder CSAT Belum Diisi

**Trigger Condition:**
```sql
SELECT * FROM tickets
WHERE status = 'Solve'
  AND updated_at < NOW() - INTERVAL :threshold_days DAY
  AND NOT EXISTS (
    SELECT 1 FROM csats WHERE csats.ticket_id = tickets.id
  );
```

**Data yang dikirim:**
```json
{
    "ticket_id": 101,
    "judul_tiket": "Permintaan Kendaraan Dinas",
    "solved_sejak": "2026-07-05",
    "hari_sejak_solve": 4,
    "user_id": 55,
    "user_nama": "Budi Santoso",
    "pesan": "Tiket #101 sudah diselesaikan 4 hari lalu. Mohon berikan rating Anda."
}
```

**Target Penerima:**
- User pemilik tiket (bukan admin)

**Threshold Default:** 2 hari setelah Solve (konfigurasi via `reminder_configs`)

**Mekanisme Anti-Spam:**
- Kirim reminder maksimal 3x per tiket
- Interval: hari ke-2, ke-4, ke-7 setelah Solve (konfigurasi)

---

## 2. Laravel Notification Classes

### 2.1 Custom WhatsApp Channel

**File:** `app/Channels/WhatsAppChannel.php`

```php
<?php

namespace App\Channels;

use App\Models\SystemConfig;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    /**
     * Kirim notifikasi via WhatsApp gateway.
     *
     * @param mixed $notifiable Model yang menerima notifikasi (Admin/User)
     * @param Notification $notification
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        // Panggil method toWhatsApp() dari notification class
        if (!method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $data = $notification->toWhatsApp($notifiable);

        // Ambil nomor WA penerima
        $phoneNumber = $data['receiver'] ?? $notifiable->no_wa ?? null;

        if (!$phoneNumber) {
            Log::warning("WhatsApp notification gagal: nomor WA tidak tersedia untuk {$notifiable->id}");
            return;
        }

        // Ambil konfigurasi gateway dari system_configs
        $gatewayUrl = SystemConfig::getValue('wa_gateway_url');
        $apiKey = SystemConfig::getValue('wa_api_key');

        if (!$gatewayUrl || !$apiKey) {
            Log::error('WhatsApp gateway belum dikonfigurasi (wa_gateway_url / wa_api_key)');
            return;
        }

        try {
            $response = Http::timeout(30)->post($gatewayUrl, [
                'api_key' => $apiKey,
                'receiver' => $phoneNumber,
                'data' => [
                    'message' => $data['message'],
                ],
            ]);

            if ($response->failed()) {
                Log::error("WhatsApp API error: {$response->status()} - {$response->body()}");
            } else {
                Log::info("WhatsApp notification terkirim ke {$phoneNumber}");
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp notification exception: {$e->getMessage()}");
        }
    }
}
```

### 2.2 BookingReminderNotification

**File:** `app/Notifications/BookingReminderNotification.php`

```php
<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\ReminderConfig;
use App\Models\RoomVehicleBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected RoomVehicleBooking $booking
    ) {}

    /**
     * Tentukan channel berdasarkan konfigurasi reminder.
     */
    public function via(object $notifiable): array
    {
        $config = ReminderConfig::where('jenis_reminder', 'booking')->first();

        $channels = ['database']; // minimal selalu in-app

        if ($config && is_array($config->channel_aktif)) {
            if (in_array('email', $config->channel_aktif)) {
                $channels[] = 'mail';
            }
            if (in_array('whatsapp', $config->channel_aktif)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return $channels;
    }

    /**
     * Data untuk disimpan di tabel notifications (in-app).
     */
    public function toDatabase(object $notifiable): array
    {
        $tipeLabel = $this->booking->tipe === 'ruang' ? 'Ruang' : 'Kendaraan';

        return [
            'booking_id' => $this->booking->id,
            'ticket_id' => $this->booking->ticket_id,
            'tipe' => $this->booking->tipe,
            'nama_aset' => $this->booking->nama_aset,
            'tanggal_mulai' => $this->booking->tanggal_mulai->format('d M Y H:i'),
            'tanggal_selesai' => $this->booking->tanggal_selesai->format('d M Y H:i'),
            'judul' => "Reminder Booking {$tipeLabel}",
            'pesan' => "Booking {$tipeLabel} \"{$this->booking->nama_aset}\" dijadwalkan pada {$this->booking->tanggal_mulai->format('d M Y H:i')}.",
            'icon' => 'calendar',
            'aksi_url' => "/admin/tiketing/{$this->booking->ticket_id}",
        ];
    }

    /**
     * Email notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $tipeLabel = $this->booking->tipe === 'ruang' ? 'Ruang' : 'Kendaraan';

        return (new MailMessage)
            ->subject("Reminder Booking {$tipeLabel} — Halo APU")
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Ini adalah pengingat bahwa booking {$tipeLabel} berikut akan segera dimulai:")
            ->line("**Aset:** {$this->booking->nama_aset}")
            ->line("**Mulai:** {$this->booking->tanggal_mulai->format('d M Y H:i')}")
            ->line("**Selesai:** {$this->booking->tanggal_selesai->format('d M Y H:i')}")
            ->action('Lihat Detail', url("/admin/tiketing/{$this->booking->ticket_id}"))
            ->line('Terima kasih telah menggunakan Halo APU.');
    }

    /**
     * WhatsApp notification.
     */
    public function toWhatsApp(object $notifiable): array
    {
        $tipeLabel = $this->booking->tipe === 'ruang' ? 'Ruang' : 'Kendaraan';

        return [
            'receiver' => $notifiable->no_wa,
            'message' => "📅 *Reminder Booking {$tipeLabel}*\n\n"
                . "Aset: {$this->booking->nama_aset}\n"
                . "Mulai: {$this->booking->tanggal_mulai->format('d M Y H:i')}\n"
                . "Selesai: {$this->booking->tanggal_selesai->format('d M Y H:i')}\n\n"
                . "Silakan persiapkan kebutuhan Anda.",
        ];
    }
}
```

### 2.3 SlaEscalationNotification

**File:** `app/Notifications/SlaEscalationNotification.php`

```php
<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlaEscalationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Ticket $ticket,
        protected TicketSlaTracking $sla,
        protected int $tier,
        protected array $viaChannels = ['database', 'mail']
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->viaChannels;

        // Tier 3: selalu tambahkan WhatsApp
        if ($this->tier >= 3 && !in_array(WhatsAppChannel::class, $channels)) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $jenisBreachLabel = !$this->sla->responded_at ? 'Respon' : 'Penyelesaian';

        return [
            'ticket_id' => $this->ticket->id,
            'judul_tiket' => $this->ticket->judul,
            'unit' => $this->ticket->subUnit?->unit?->nama,
            'sub_unit' => $this->ticket->subUnit?->nama,
            'tier' => $this->tier,
            'jenis_breach' => $jenisBreachLabel,
            'judul' => "⚠️ Eskalasi SLA Tier {$this->tier}",
            'pesan' => "Tiket #{$this->ticket->id} \"{$this->ticket->judul}\" telah mencapai SLA Tier {$this->tier} ({$jenisBreachLabel}). Segera tangani!",
            'icon' => 'alert-triangle',
            'aksi_url' => "/admin/tiketing/{$this->ticket->id}",
            'prioritas' => $this->tier >= 3 ? 'tinggi' : 'normal',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $jenisBreachLabel = !$this->sla->responded_at ? 'Respon' : 'Penyelesaian';

        $mail = (new MailMessage)
            ->subject("⚠️ Eskalasi SLA Tier {$this->tier} — Tiket #{$this->ticket->id}")
            ->greeting("Perhatian, {$notifiable->name}!")
            ->line("Tiket berikut telah mencapai SLA **Tier {$this->tier}**:")
            ->line("**Tiket:** #{$this->ticket->id} — {$this->ticket->judul}")
            ->line("**Unit:** {$this->ticket->subUnit?->unit?->nama}")
            ->line("**Sub Unit:** {$this->ticket->subUnit?->nama}")
            ->line("**Jenis:** SLA {$jenisBreachLabel}")
            ->line("**Status:** {$this->ticket->status}")
            ->action('Lihat Tiket', url("/admin/tiketing/{$this->ticket->id}"));

        if ($this->tier >= 3) {
            $mail->line('**⛔ BREACH! Tiket ini sudah melewati batas SLA. Tindakan segera diperlukan.**');
        }

        return $mail;
    }

    public function toWhatsApp(object $notifiable): array
    {
        $jenisBreachLabel = !$this->sla->responded_at ? 'Respon' : 'Penyelesaian';
        $emoji = $this->tier >= 3 ? '🔴' : ($this->tier === 2 ? '🟠' : '🟡');

        return [
            'receiver' => $notifiable->no_wa,
            'message' => "{$emoji} *Eskalasi SLA Tier {$this->tier}*\n\n"
                . "Tiket: #{$this->ticket->id}\n"
                . "Judul: {$this->ticket->judul}\n"
                . "Unit: {$this->ticket->subUnit?->unit?->nama}\n"
                . "Jenis: SLA {$jenisBreachLabel}\n"
                . "Status: {$this->ticket->status}\n\n"
                . ($this->tier >= 3
                    ? "⛔ BREACH! Segera tangani tiket ini."
                    : "Segera tangani tiket ini sebelum eskalasi lebih lanjut."),
        ];
    }
}
```

### 2.4 PendingTicketReminderNotification

**File:** `app/Notifications/PendingTicketReminderNotification.php`

```php
<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\ReminderConfig;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendingTicketReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected int $hariPending;

    public function __construct(
        protected Ticket $ticket
    ) {
        $this->hariPending = (int) now()->diffInDays($this->ticket->updated_at);
    }

    public function via(object $notifiable): array
    {
        $config = ReminderConfig::where('jenis_reminder', 'pending_lama')->first();
        $channels = ['database'];

        if ($config && is_array($config->channel_aktif)) {
            if (in_array('email', $config->channel_aktif)) {
                $channels[] = 'mail';
            }
            if (in_array('whatsapp', $config->channel_aktif)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'judul_tiket' => $this->ticket->judul,
            'status' => 'Pending',
            'hari_pending' => $this->hariPending,
            'unit' => $this->ticket->subUnit?->unit?->nama,
            'sub_unit' => $this->ticket->subUnit?->nama,
            'judul' => "Tiket Pending Lama",
            'pesan' => "Tiket #{$this->ticket->id} \"{$this->ticket->judul}\" sudah pending selama {$this->hariPending} hari. Silakan tindak lanjuti.",
            'icon' => 'clock',
            'aksi_url' => "/admin/tiketing/{$this->ticket->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tiket Pending Lama — #{$this->ticket->id}")
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Tiket berikut sudah dalam status Pending selama **{$this->hariPending} hari**:")
            ->line("**Tiket:** #{$this->ticket->id} — {$this->ticket->judul}")
            ->line("**Unit:** {$this->ticket->subUnit?->unit?->nama}")
            ->line("**Pending Sejak:** {$this->ticket->updated_at->format('d M Y H:i')}")
            ->action('Lihat Tiket', url("/admin/tiketing/{$this->ticket->id}"))
            ->line('Mohon segera ditindaklanjuti.');
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'receiver' => $notifiable->no_wa,
            'message' => "⏳ *Tiket Pending Lama*\n\n"
                . "Tiket: #{$this->ticket->id}\n"
                . "Judul: {$this->ticket->judul}\n"
                . "Pending: {$this->hariPending} hari\n"
                . "Unit: {$this->ticket->subUnit?->unit?->nama}\n\n"
                . "Mohon segera ditindaklanjuti.",
        ];
    }
}
```

### 2.5 CsatReminderNotification

**File:** `app/Notifications/CsatReminderNotification.php`

```php
<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\ReminderConfig;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CsatReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected int $hariSejak;

    public function __construct(
        protected Ticket $ticket
    ) {
        $this->hariSejak = (int) now()->diffInDays($this->ticket->updated_at);
    }

    public function via(object $notifiable): array
    {
        $config = ReminderConfig::where('jenis_reminder', 'csat')->first();
        $channels = ['database'];

        if ($config && is_array($config->channel_aktif)) {
            if (in_array('email', $config->channel_aktif)) {
                $channels[] = 'mail';
            }
            if (in_array('whatsapp', $config->channel_aktif)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'judul_tiket' => $this->ticket->judul,
            'hari_sejak_solve' => $this->hariSejak,
            'judul' => "Berikan Rating Tiket Anda",
            'pesan' => "Tiket #{$this->ticket->id} \"{$this->ticket->judul}\" sudah diselesaikan {$this->hariSejak} hari lalu. Mohon berikan rating Anda.",
            'icon' => 'star',
            'aksi_url' => "/tiketing/{$this->ticket->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Berikan Rating untuk Tiket #{$this->ticket->id} — Halo APU")
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Tiket Anda telah diselesaikan. Kami ingin mendengar pendapat Anda!")
            ->line("**Tiket:** #{$this->ticket->id} — {$this->ticket->judul}")
            ->line("**Diselesaikan:** {$this->hariSejak} hari lalu")
            ->action('Berikan Rating', url("/tiketing/{$this->ticket->id}"))
            ->line('Rating Anda sangat berarti bagi peningkatan layanan kami. Terima kasih!');
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'receiver' => $notifiable->no_wa,
            'message' => "⭐ *Berikan Rating Layanan Anda*\n\n"
                . "Tiket: #{$this->ticket->id}\n"
                . "Judul: {$this->ticket->judul}\n"
                . "Diselesaikan: {$this->hariSejak} hari lalu\n\n"
                . "Silakan berikan rating melalui aplikasi Halo APU.\n"
                . "Rating Anda membantu kami meningkatkan layanan. 🙏",
        ];
    }
}
```

---

## 3. Konfigurasi Reminder

### 3.1 Tabel `reminder_configs`

**Migration sudah ada** (dari Fase 2). Struktur:

```sql
CREATE TABLE reminder_configs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jenis_reminder VARCHAR(50) NOT NULL UNIQUE,  -- 'booking', 'sla', 'pending_lama', 'csat'
    lead_time_value INT UNSIGNED NOT NULL,        -- nilai lead time (hari/menit tergantung jenis)
    channel_aktif JSON NOT NULL,                  -- ["in_app", "email", "whatsapp"]
    aktif BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);
```

**Seeder Default:**

| jenis_reminder | lead_time_value | channel_aktif | aktif |
|---------------|-----------------|---------------|-------|
| `booking` | 1 (hari) | `["in_app", "email"]` | true |
| `sla` | 0 (otomatis dari SLA) | `["in_app", "email"]` | true |
| `pending_lama` | 3 (hari) | `["in_app", "email"]` | true |
| `csat` | 2 (hari) | `["in_app", "email", "whatsapp"]` | true |

### 3.2 Model `ReminderConfig`

**File:** `app/Models/ReminderConfig.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderConfig extends Model
{
    protected $fillable = [
        'jenis_reminder',
        'lead_time_value',
        'channel_aktif',
        'aktif',
    ];

    protected $casts = [
        'lead_time_value' => 'integer',
        'channel_aktif' => 'array',       // JSON → array otomatis
        'aktif' => 'boolean',
    ];

    /**
     * Ambil config berdasarkan jenis reminder.
     */
    public static function getConfig(string $jenis): ?self
    {
        return self::where('jenis_reminder', $jenis)
            ->where('aktif', true)
            ->first();
    }

    /**
     * Cek apakah channel tertentu aktif untuk jenis reminder ini.
     */
    public function isChannelActive(string $channel): bool
    {
        return in_array($channel, $this->channel_aktif ?? []);
    }
}
```

### 3.3 Routes

```php
use App\Http\Controllers\Admin\ReminderConfigController;

Route::middleware(['auth:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('reminder-config', [ReminderConfigController::class, 'index'])->name('reminder-config.index');
    Route::put('reminder-config', [ReminderConfigController::class, 'update'])->name('reminder-config.update');
});
```

### 3.4 Controller `Admin\ReminderConfigController`

**File:** `app/Http/Controllers/Admin/ReminderConfigController.php`

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReminderConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReminderConfigController extends Controller
{
    public function index()
    {
        $configs = ReminderConfig::orderBy('jenis_reminder')->get();

        return Inertia::render('Admin/ReminderConfig/Index', [
            'configs' => $configs,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'configs' => 'required|array|min:1',
            'configs.*.id' => 'required|exists:reminder_configs,id',
            'configs.*.lead_time_value' => 'required|integer|min:0',
            'configs.*.channel_aktif' => 'required|array',
            'configs.*.channel_aktif.*' => 'in:in_app,email,whatsapp',
            'configs.*.aktif' => 'required|boolean',
        ]);

        foreach ($validated['configs'] as $configData) {
            ReminderConfig::where('id', $configData['id'])->update([
                'lead_time_value' => $configData['lead_time_value'],
                'channel_aktif' => $configData['channel_aktif'],
                'aktif' => $configData['aktif'],
            ]);
        }

        return back()->with('success', 'Konfigurasi reminder berhasil disimpan.');
    }
}
```

### 3.5 React Page: `Admin/ReminderConfig/Index.tsx`

**File:** `resources/js/Pages/Admin/ReminderConfig/Index.tsx`

**Struktur UI:**

```
┌─────────────────────────────────────────────────────────────────────┐
│ Judul: Konfigurasi Reminder                                        │
│ Deskripsi: Atur jenis, channel, dan waktu pengiriman reminder      │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│ ┌────────────────┬───────────┬─────────┬─────────┬──────┬────────┐ │
│ │ Jenis Reminder │ Lead Time │ In-App  │ Email   │ WA   │ Aktif  │ │
│ ├────────────────┼───────────┼─────────┼─────────┼──────┼────────┤ │
│ │ Booking        │ [1] hari  │ ✅       │ ✅       │ ☐    │ ✅      │ │
│ │ SLA            │ [otomatis]│ ✅       │ ✅       │ ☐    │ ✅      │ │
│ │ Pending Lama   │ [3] hari  │ ✅       │ ✅       │ ☐    │ ✅      │ │
│ │ CSAT           │ [2] hari  │ ✅       │ ✅       │ ✅    │ ✅      │ │
│ └────────────────┴───────────┴─────────┴─────────┴──────┴────────┘ │
│                                                                     │
│ [Simpan Perubahan]                                                  │
└─────────────────────────────────────────────────────────────────────┘
```

**Komponen yang dipakai:**
- `Card`, `CardHeader`, `CardContent` dari shadcn/ui
- `Table`, `TableHeader`, `TableRow`, `TableCell` dari shadcn/ui
- `Input` type number untuk lead time
- `Checkbox` untuk channel toggle (in_app, email, whatsapp)
- `Switch` untuk toggle aktif/nonaktif
- `Button` untuk submit
- `useForm` dari `@inertiajs/react`
- `Label` untuk aksesibilitas

**Pseudocode:**
```tsx
import { useForm } from '@inertiajs/react';

const JENIS_LABELS: Record<string, string> = {
    booking: 'Reminder Booking',
    sla: 'Reminder SLA',
    pending_lama: 'Tiket Pending Lama',
    csat: 'CSAT Belum Diisi',
};

const LEAD_TIME_UNITS: Record<string, string> = {
    booking: 'hari sebelum',
    sla: 'otomatis',
    pending_lama: 'hari pending',
    csat: 'hari setelah solve',
};

export default function ReminderConfigIndex({ configs }) {
    const { data, setData, put, processing } = useForm({
        configs: configs.map(c => ({
            id: c.id,
            lead_time_value: c.lead_time_value,
            channel_aktif: c.channel_aktif,
            aktif: c.aktif,
        })),
    });

    const toggleChannel = (index: number, channel: string) => {
        const updated = [...data.configs];
        const channels = updated[index].channel_aktif;
        if (channels.includes(channel)) {
            updated[index].channel_aktif = channels.filter(c => c !== channel);
        } else {
            updated[index].channel_aktif = [...channels, channel];
        }
        setData('configs', updated);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('admin.reminder-config.update'));
    };

    // ... render table with form ...
}
```

---

## 4. Notification Center UI

### 4.1 API Routes untuk Notifikasi

**File:** Tambahkan di `routes/admin.php`:

```php
use App\Http\Controllers\Admin\NotificationController;

Route::middleware(['auth:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Notifikasi
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');
    Route::get('notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.read');
    Route::patch('notifications/{id}/snooze', [NotificationController::class, 'snooze'])
        ->name('notifications.snooze');
    Route::patch('notifications/{id}/done', [NotificationController::class, 'markAsDone'])
        ->name('notifications.done');
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])
        ->name('notifications.mark-all-read');
});
```

### 4.2 Controller `Admin\NotificationController`

**File:** `app/Http/Controllers/Admin/NotificationController.php`

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;

class NotificationController extends Controller
{
    /**
     * Ambil jumlah notifikasi yang belum dibaca.
     * Dipanggil via usePoll() setiap 15 detik.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user('admin')
            ->unreadNotifications()
            ->count();

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    /**
     * Halaman semua notifikasi (paginated, dengan filter).
     */
    public function index(Request $request)
    {
        $query = $request->user('admin')->notifications();

        // Filter: status
        $status = $request->get('status');
        if ($status === 'unread') {
            $query->whereNull('read_at');
        } elseif ($status === 'read') {
            $query->whereNotNull('read_at');
        }

        // Filter: tipe
        $type = $request->get('type');
        if ($type) {
            $query->where('type', 'LIKE', "%{$type}%");
        }

        $notifications = $query->latest()->paginate(20);

        return Inertia::render('Admin/Notifications/Index', [
            'notifications' => $notifications,
            'filters' => [
                'status' => $status,
                'type' => $type,
            ],
        ]);
    }

    /**
     * Tandai satu notifikasi sebagai sudah dibaca.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user('admin')
            ->notifications()
            ->findOrFail($id);

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Snooze notifikasi — set snoozed_until di kolom data.
     * Scheduler akan re-notify saat expired.
     */
    public function snooze(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'snooze_minutes' => 'required|integer|in:15,30,60,120,1440',
        ]);

        $notification = $request->user('admin')
            ->notifications()
            ->findOrFail($id);

        $data = $notification->data;
        $data['snoozed_until'] = now()->addMinutes($validated['snooze_minutes'])->toISOString();
        $data['snoozed'] = true;

        $notification->update([
            'data' => $data,
            'read_at' => now(), // Mark as read saat di-snooze
        ]);

        return response()->json([
            'success' => true,
            'snoozed_until' => $data['snoozed_until'],
        ]);
    }

    /**
     * Tandai notifikasi sebagai selesai (done).
     * Menambahkan flag done_at di data JSON.
     */
    public function markAsDone(Request $request, string $id): JsonResponse
    {
        $notification = $request->user('admin')
            ->notifications()
            ->findOrFail($id);

        $data = $notification->data;
        $data['done_at'] = now()->toISOString();

        $notification->update([
            'data' => $data,
            'read_at' => $notification->read_at ?? now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Tandai semua notifikasi sebagai sudah dibaca.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user('admin')->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}
```

### 4.3 Komponen Header: `NotificationBell.tsx`

**File:** `resources/js/Components/NotificationBell.tsx`

```tsx
import React, { useState, useEffect, useCallback } from 'react';
import { usePoll } from '@inertiajs/react';
import axios from 'axios';
import { Bell, Check, Clock, ExternalLink, CheckCircle2 } from 'lucide-react';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/Components/ui/popover';
import { Button } from '@/Components/ui/button';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Badge } from '@/Components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';

interface NotificationData {
    judul: string;
    pesan: string;
    icon?: string;
    aksi_url?: string;
    prioritas?: string;
    snoozed?: boolean;
    done_at?: string;
}

interface NotificationItem {
    id: string;
    type: string;
    data: NotificationData;
    read_at: string | null;
    created_at: string;
}

export default function NotificationBell() {
    const [unreadCount, setUnreadCount] = useState(0);
    const [notifications, setNotifications] = useState<NotificationItem[]>([]);
    const [isOpen, setIsOpen] = useState(false);

    // Poll setiap 15 detik untuk jumlah notifikasi belum dibaca
    usePoll(15000, {
        only: [], // Tidak perlu reload halaman
        onFinish: () => {
            fetchUnreadCount();
        },
    });

    const fetchUnreadCount = useCallback(async () => {
        try {
            const response = await axios.get(route('admin.notifications.unread-count'));
            setUnreadCount(response.data.unread_count);
        } catch (error) {
            console.error('Gagal fetch unread count:', error);
        }
    }, []);

    const fetchRecentNotifications = useCallback(async () => {
        try {
            const response = await axios.get(route('admin.notifications.index'), {
                params: { per_page: 10 },
            });
            setNotifications(response.data.notifications?.data || []);
        } catch (error) {
            console.error('Gagal fetch notifications:', error);
        }
    }, []);

    // Fetch notifikasi terbaru saat dropdown dibuka
    useEffect(() => {
        if (isOpen) {
            fetchRecentNotifications();
        }
    }, [isOpen, fetchRecentNotifications]);

    // Fetch unread count saat mount
    useEffect(() => {
        fetchUnreadCount();
    }, [fetchUnreadCount]);

    const handleMarkAsRead = async (id: string) => {
        try {
            await axios.patch(route('admin.notifications.read', { id }));
            setNotifications(prev =>
                prev.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n)
            );
            setUnreadCount(prev => Math.max(0, prev - 1));
        } catch (error) {
            console.error('Gagal mark as read:', error);
        }
    };

    const handleSnooze = async (id: string, minutes: number) => {
        try {
            await axios.patch(route('admin.notifications.snooze', { id }), {
                snooze_minutes: minutes,
            });
            fetchRecentNotifications();
        } catch (error) {
            console.error('Gagal snooze:', error);
        }
    };

    const handleMarkAllRead = async () => {
        try {
            await axios.post(route('admin.notifications.mark-all-read'));
            setNotifications(prev =>
                prev.map(n => ({ ...n, read_at: n.read_at || new Date().toISOString() }))
            );
            setUnreadCount(0);
        } catch (error) {
            console.error('Gagal mark all read:', error);
        }
    };

    const formatTimeAgo = (dateStr: string): string => {
        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now.getTime() - date.getTime();
        const diffMinutes = Math.floor(diffMs / 60000);

        if (diffMinutes < 1) return 'Baru saja';
        if (diffMinutes < 60) return `${diffMinutes} menit lalu`;
        const diffHours = Math.floor(diffMinutes / 60);
        if (diffHours < 24) return `${diffHours} jam lalu`;
        const diffDays = Math.floor(diffHours / 24);
        return `${diffDays} hari lalu`;
    };

    return (
        <Popover open={isOpen} onOpenChange={setIsOpen}>
            <PopoverTrigger asChild>
                <Button variant="ghost" size="icon" className="relative">
                    <Bell className="h-5 w-5" />
                    {unreadCount > 0 && (
                        <Badge
                            variant="destructive"
                            className="absolute -top-1 -right-1 h-5 min-w-[20px] flex items-center justify-center text-xs p-0"
                        >
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </Badge>
                    )}
                </Button>
            </PopoverTrigger>

            <PopoverContent className="w-96 p-0" align="end">
                {/* Header */}
                <div className="flex items-center justify-between p-4 border-b">
                    <h3 className="font-semibold">Notifikasi</h3>
                    {unreadCount > 0 && (
                        <Button variant="ghost" size="sm" onClick={handleMarkAllRead}>
                            <Check className="h-4 w-4 mr-1" />
                            Tandai semua dibaca
                        </Button>
                    )}
                </div>

                {/* List Notifikasi */}
                <ScrollArea className="max-h-[400px]">
                    {notifications.length === 0 ? (
                        <div className="p-8 text-center text-muted-foreground">
                            Tidak ada notifikasi
                        </div>
                    ) : (
                        notifications.map(notification => (
                            <div
                                key={notification.id}
                                className={`
                                    p-4 border-b cursor-pointer hover:bg-accent/50 transition-colors
                                    ${!notification.read_at ? 'bg-blue-50 dark:bg-blue-950/20' : ''}
                                `}
                                onClick={() => {
                                    if (!notification.read_at) {
                                        handleMarkAsRead(notification.id);
                                    }
                                    if (notification.data.aksi_url) {
                                        window.location.href = notification.data.aksi_url;
                                    }
                                }}
                            >
                                <div className="flex items-start gap-3">
                                    {/* Unread indicator */}
                                    {!notification.read_at && (
                                        <div className="w-2 h-2 rounded-full bg-blue-500 mt-2 flex-shrink-0" />
                                    )}

                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium truncate">
                                            {notification.data.judul}
                                        </p>
                                        <p className="text-sm text-muted-foreground line-clamp-2 mt-1">
                                            {notification.data.pesan}
                                        </p>
                                        <p className="text-xs text-muted-foreground mt-1">
                                            {formatTimeAgo(notification.created_at)}
                                        </p>
                                    </div>

                                    {/* Snooze dropdown */}
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="h-8 w-8 flex-shrink-0"
                                                onClick={(e) => e.stopPropagation()}
                                            >
                                                <Clock className="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent>
                                            <DropdownMenuItem onClick={() => handleSnooze(notification.id, 15)}>
                                                Snooze 15 menit
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => handleSnooze(notification.id, 30)}>
                                                Snooze 30 menit
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => handleSnooze(notification.id, 60)}>
                                                Snooze 1 jam
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onClick={() => handleSnooze(notification.id, 1440)}>
                                                Snooze 1 hari
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        ))
                    )}
                </ScrollArea>

                {/* Footer */}
                <div className="p-3 border-t text-center">
                    <a
                        href={route('admin.notifications.index')}
                        className="text-sm text-blue-500 hover:underline inline-flex items-center gap-1"
                    >
                        Lihat semua notifikasi
                        <ExternalLink className="h-3 w-3" />
                    </a>
                </div>
            </PopoverContent>
        </Popover>
    );
}
```

### 4.4 Halaman Full: `Admin/Notifications/Index.tsx`

**File:** `resources/js/Pages/Admin/Notifications/Index.tsx`

**Struktur UI:**

```
┌──────────────────────────────────────────────────────────────────────┐
│ Judul: Semua Notifikasi                                              │
├──────────────────────────────────────────────────────────────────────┤
│ Filter: [Semua ▾] [Belum Dibaca ▾] [Tipe: Semua ▾]  [Tandai Semua] │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│ ● 🔔 Eskalasi SLA Tier 2                                  5m lalu   │
│   Tiket #456 "Printer Rusak" mencapai Tier 2              [Snooze]  │
│                                                            [Done]    │
│ ─────────────────────────────────────────────────────────────────    │
│   📅 Reminder Booking Ruang                                1j lalu   │
│   Booking Ruang Rapat Lantai 3 besok 09:00                 [Snooze]  │
│                                                            [Done]    │
│ ─────────────────────────────────────────────────────────────────    │
│ ... (lebih banyak notifikasi)                                        │
│                                                                      │
│ [← Sebelumnya]  Halaman 1 dari 5  [Selanjutnya →]                  │
└──────────────────────────────────────────────────────────────────────┘
```

**Komponen yang dipakai:**
- `Card` untuk wrapper
- `Select` untuk filter status dan tipe
- `Button` untuk aksi (mark all read, snooze, done)
- `Pagination` dari shadcn/ui atau custom
- `Badge` untuk label prioritas/tipe

---

## 5. Notifikasi Suara

### 5.1 Audio File

**File:** `public/sounds/ting-ting-ting.mp3`

- Format: MP3 (kompatibilitas browser luas)
- Durasi: ~1-2 detik
- Ukuran: < 50KB
- **Catatan:** Letakkan file audio di `public/sounds/`. File ini bisa di-download dari library sound effect gratis atau dibuat sendiri.

### 5.2 React Hook: `useNotificationSound.ts`

**File:** `resources/js/hooks/useNotificationSound.ts`

```tsx
import { useState, useEffect, useCallback, useRef } from 'react';

interface UseNotificationSoundOptions {
    soundUrl?: string;
    pollIntervalMs?: number;
    enabled?: boolean;
}

interface UseNotificationSoundReturn {
    isMuted: boolean;
    toggleMute: () => void;
    setMuted: (muted: boolean) => void;
    lastPlayedAt: Date | null;
}

/**
 * Hook untuk memutar suara notifikasi saat ada event penting:
 * 1. Tiket baru masuk (untuk admin)
 * 2. SLA Tier 3 breach
 *
 * Suara hanya diputar jika:
 * - User belum mute
 * - Ada perubahan unread count (naik)
 * - Browser sudah interact (autoplay policy)
 */
export function useNotificationSound({
    soundUrl = '/sounds/ting-ting-ting.mp3',
    enabled = true,
}: UseNotificationSoundOptions = {}): UseNotificationSoundReturn {
    const [isMuted, setIsMuted] = useState<boolean>(() => {
        // Baca preferensi dari localStorage
        const saved = localStorage.getItem('notification_sound_muted');
        return saved === 'true';
    });

    const [lastPlayedAt, setLastPlayedAt] = useState<Date | null>(null);
    const previousCountRef = useRef<number | null>(null);
    const audioRef = useRef<HTMLAudioElement | null>(null);

    // Inisialisasi Audio element
    useEffect(() => {
        audioRef.current = new Audio(soundUrl);
        audioRef.current.volume = 0.7;

        return () => {
            if (audioRef.current) {
                audioRef.current.pause();
                audioRef.current = null;
            }
        };
    }, [soundUrl]);

    // Simpan preferensi mute ke localStorage
    useEffect(() => {
        localStorage.setItem('notification_sound_muted', isMuted.toString());
    }, [isMuted]);

    const playSound = useCallback(() => {
        if (isMuted || !enabled || !audioRef.current) return;

        try {
            audioRef.current.currentTime = 0;
            audioRef.current.play().catch(err => {
                // Browser autoplay policy — user belum interact
                console.warn('Autoplay blocked:', err.message);
            });
            setLastPlayedAt(new Date());
        } catch (error) {
            console.error('Gagal play sound:', error);
        }
    }, [isMuted, enabled]);

    /**
     * Method ini harus dipanggil dari komponen yang melakukan polling.
     * Contoh penggunaan di NotificationBell:
     *
     * const { checkAndPlay } = useNotificationSound();
     * // Setelah fetch unread count:
     * checkAndPlay(newCount);
     */
    const checkAndPlay = useCallback((currentCount: number) => {
        if (previousCountRef.current !== null && currentCount > previousCountRef.current) {
            playSound();
        }
        previousCountRef.current = currentCount;
    }, [playSound]);

    const toggleMute = useCallback(() => {
        setIsMuted(prev => !prev);
    }, []);

    return {
        isMuted,
        toggleMute,
        setMuted: setIsMuted,
        lastPlayedAt,
    };
}
```

### 5.3 Integrasi dengan NotificationBell

Tambahkan hook di `NotificationBell.tsx`:

```tsx
import { useNotificationSound } from '@/hooks/useNotificationSound';
import { Volume2, VolumeX } from 'lucide-react';

export default function NotificationBell() {
    const { isMuted, toggleMute } = useNotificationSound();
    const previousCountRef = useRef<number>(0);
    const audioRef = useRef<HTMLAudioElement | null>(null);

    // Inisialisasi audio
    useEffect(() => {
        audioRef.current = new Audio('/sounds/ting-ting-ting.mp3');
        audioRef.current.volume = 0.7;
    }, []);

    const fetchUnreadCount = useCallback(async () => {
        try {
            const response = await axios.get(route('admin.notifications.unread-count'));
            const newCount = response.data.unread_count;

            // Play sound jika count naik & tidak muted
            if (!isMuted && newCount > previousCountRef.current && previousCountRef.current !== null) {
                audioRef.current?.play().catch(() => {});
            }

            previousCountRef.current = newCount;
            setUnreadCount(newCount);
        } catch (error) {
            console.error('Gagal fetch unread count:', error);
        }
    }, [isMuted]);

    // ... rest of component ...

    return (
        <div className="flex items-center gap-1">
            {/* Mute/Unmute button */}
            <Button variant="ghost" size="icon" onClick={toggleMute} title={isMuted ? 'Aktifkan suara' : 'Matikan suara'}>
                {isMuted ? <VolumeX className="h-4 w-4" /> : <Volume2 className="h-4 w-4" />}
            </Button>

            {/* Bell button (existing) */}
            <Popover>
                {/* ... existing bell code ... */}
            </Popover>
        </div>
    );
}
```

### 5.4 Event yang Memicu Suara

| # | Event | Kondisi | Penerima |
|---|-------|---------|----------|
| 1 | Tiket baru masuk | `unreadCount` naik karena ada tiket baru | Admin |
| 2 | SLA Tier 3 breach | `CheckSlaCommand` mengirim notifikasi breach | Admin |

**Catatan:** Suara diputar berdasarkan kenaikan `unreadCount`, bukan berdasarkan event spesifik. Ini lebih sederhana dan cukup efektif karena kedua event di atas akan menambah notifikasi baru.

---

## 6. Scheduler Commands Baru

### 6.1 `BookingReminderCommand`

**File:** `app/Console/Commands/BookingReminderCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\ReminderConfig;
use App\Models\RoomVehicleBooking;
use App\Notifications\BookingReminderNotification;
use Illuminate\Console\Command;

class BookingReminderCommand extends Command
{
    protected $signature = 'reminder:booking';
    protected $description = 'Kirim reminder untuk booking yang mendekati tanggal mulai';

    public function handle(): int
    {
        $config = ReminderConfig::getConfig('booking');
        if (!$config) {
            $this->info('Reminder booking tidak aktif.');
            return Command::SUCCESS;
        }

        $leadDays = $config->lead_time_value; // hari
        $targetDate = now()->addDays($leadDays)->toDateString();

        $bookings = RoomVehicleBooking::where('status', 'Disetujui')
            ->whereDate('tanggal_mulai', $targetDate)
            ->with(['ticket.subUnit.unit', 'ticket.user'])
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            // Cek apakah reminder sudah pernah dikirim hari ini
            $alreadySent = \Illuminate\Notifications\DatabaseNotification::where('type', BookingReminderNotification::class)
                ->where('data->booking_id', $booking->id)
                ->whereDate('created_at', today())
                ->exists();

            if ($alreadySent) continue;

            // Kirim ke admin unit terkait
            $unitId = $booking->ticket?->subUnit?->unit_id;
            if ($unitId) {
                $admins = Admin::whereHas('units', fn ($q) => $q->where('unit_id', $unitId))->get();
                foreach ($admins as $admin) {
                    $admin->notify(new BookingReminderNotification($booking));
                    $sent++;
                }
            }
        }

        $this->info("Selesai. {$bookings->count()} booking dicek, {$sent} reminder terkirim.");
        return Command::SUCCESS;
    }
}
```

### 6.2 `PendingTicketReminderCommand`

**File:** `app/Console/Commands/PendingTicketReminderCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\ReminderConfig;
use App\Models\Ticket;
use App\Notifications\PendingTicketReminderNotification;
use Illuminate\Console\Command;

class PendingTicketReminderCommand extends Command
{
    protected $signature = 'reminder:pending';
    protected $description = 'Kirim reminder untuk tiket yang sudah lama pending';

    public function handle(): int
    {
        $config = ReminderConfig::getConfig('pending_lama');
        if (!$config) {
            $this->info('Reminder pending tidak aktif.');
            return Command::SUCCESS;
        }

        $thresholdDays = $config->lead_time_value;
        $cutoff = now()->subDays($thresholdDays);

        $tickets = Ticket::where('status', 'Pending')
            ->where('updated_at', '<', $cutoff)
            ->with(['subUnit.unit', 'assignedAdmin'])
            ->get();

        $sent = 0;

        foreach ($tickets as $ticket) {
            // Anti-spam: max 1 reminder per hari per tiket
            $alreadySent = \Illuminate\Notifications\DatabaseNotification::where('type', PendingTicketReminderNotification::class)
                ->where('data->ticket_id', $ticket->id)
                ->whereDate('created_at', today())
                ->exists();

            if ($alreadySent) continue;

            // Kirim ke admin yang di-assign + admin unit terkait
            $admins = collect();
            if ($ticket->assignedAdmin) {
                $admins->push($ticket->assignedAdmin);
            }

            $unitId = $ticket->subUnit?->unit_id;
            if ($unitId) {
                $unitAdmins = Admin::whereHas('units', fn ($q) => $q->where('unit_id', $unitId))->get();
                $admins = $admins->merge($unitAdmins)->unique('id');
            }

            foreach ($admins as $admin) {
                $admin->notify(new PendingTicketReminderNotification($ticket));
                $sent++;
            }
        }

        $this->info("Selesai. {$tickets->count()} tiket pending ditemukan, {$sent} reminder terkirim.");
        return Command::SUCCESS;
    }
}
```

### 6.3 `CsatReminderCommand`

**File:** `app/Console/Commands/CsatReminderCommand.php`

```php
<?php

namespace App\Console\Commands;

use App\Models\ReminderConfig;
use App\Models\Ticket;
use App\Notifications\CsatReminderNotification;
use Illuminate\Console\Command;

class CsatReminderCommand extends Command
{
    protected $signature = 'reminder:csat';
    protected $description = 'Kirim reminder CSAT untuk tiket yang sudah di-solve tapi belum dirating';

    public function handle(): int
    {
        $config = ReminderConfig::getConfig('csat');
        if (!$config) {
            $this->info('Reminder CSAT tidak aktif.');
            return Command::SUCCESS;
        }

        $thresholdDays = $config->lead_time_value;
        $cutoff = now()->subDays($thresholdDays);

        $tickets = Ticket::where('status', 'Solve')
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('csat')  // Belum ada rating
            ->with(['user'])
            ->get();

        $sent = 0;

        foreach ($tickets as $ticket) {
            $user = $ticket->user;
            if (!$user) continue;

            // Anti-spam: max 3 reminder per tiket
            $reminderCount = \Illuminate\Notifications\DatabaseNotification::where('type', CsatReminderNotification::class)
                ->where('notifiable_type', get_class($user))
                ->where('notifiable_id', $user->id)
                ->where('data->ticket_id', $ticket->id)
                ->count();

            if ($reminderCount >= 3) continue;

            // Cek interval: tidak lebih dari 1 reminder per 2 hari per tiket
            $lastSent = \Illuminate\Notifications\DatabaseNotification::where('type', CsatReminderNotification::class)
                ->where('notifiable_type', get_class($user))
                ->where('notifiable_id', $user->id)
                ->where('data->ticket_id', $ticket->id)
                ->latest()
                ->first();

            if ($lastSent && $lastSent->created_at->diffInDays(now()) < 2) {
                continue;
            }

            $user->notify(new CsatReminderNotification($ticket));
            $sent++;
        }

        $this->info("Selesai. {$tickets->count()} tiket tanpa CSAT, {$sent} reminder terkirim.");
        return Command::SUCCESS;
    }
}
```

### 6.4 `SnoozeCheckCommand`

**File:** `app/Console/Commands/SnoozeCheckCommand.php`

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class SnoozeCheckCommand extends Command
{
    protected $signature = 'reminder:snooze-check';
    protected $description = 'Re-fire notifikasi yang snooze-nya sudah expired';

    public function handle(): int
    {
        // Cari notifikasi yang di-snooze dan sudah expired
        $notifications = DatabaseNotification::whereNotNull('read_at')
            ->get()
            ->filter(function ($notification) {
                $data = $notification->data;
                if (!isset($data['snoozed']) || !$data['snoozed']) return false;
                if (!isset($data['snoozed_until'])) return false;
                if (isset($data['done_at'])) return false; // Sudah done

                return now()->gte($data['snoozed_until']);
            });

        $refired = 0;

        foreach ($notifications as $notification) {
            // Re-fire: set read_at = null dan clear snooze flags
            $data = $notification->data;
            unset($data['snoozed']);
            unset($data['snoozed_until']);
            $data['re_fired_at'] = now()->toISOString();

            $notification->update([
                'data' => $data,
                'read_at' => null,
            ]);

            $refired++;
        }

        $this->info("Selesai. {$refired} notifikasi di-re-fire.");
        return Command::SUCCESS;
    }
}
```

### 6.5 Register Semua Schedule

**File:** `routes/console.php` (Laravel 11+)

```php
use Illuminate\Support\Facades\Schedule;

// SLA Check (dari Fase 3)
Schedule::command('sla:check')->everyMinute();

// Reminder commands
Schedule::command('reminder:booking')->dailyAt('07:00');      // Cek booking H-X setiap pagi
Schedule::command('reminder:pending')->dailyAt('08:00');      // Cek tiket pending setiap pagi
Schedule::command('reminder:csat')->dailyAt('09:00');         // Cek CSAT belum diisi setiap pagi
Schedule::command('reminder:snooze-check')->everyFiveMinutes(); // Re-fire snoozed notifications
```

**Catatan:**
- `reminder:booking`, `reminder:pending`, `reminder:csat` cukup dijalankan 1x/hari (pagi hari kerja)
- `reminder:snooze-check` perlu lebih sering (setiap 5 menit) agar snooze responsif
- `sla:check` tetap setiap menit (dari Fase 3)

---

## 7. File-by-File Checklist

### 7.1 Backend — PHP/Laravel

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 1 | `app/Channels/WhatsAppChannel.php` | **Buat baru** | Custom notification channel — `send()` method, HTTP client ke WA gateway, ambil config dari `system_configs` |
| 2 | `app/Notifications/BookingReminderNotification.php` | **Buat baru** | `via()` conditional dari `ReminderConfig`, `toDatabase()`, `toMail()`, `toWhatsApp()`, implements `ShouldQueue` |
| 3 | `app/Notifications/SlaEscalationNotification.php` | **Buat baru** (atau edit dari Fase 3) | Tier-aware notification, WA otomatis di Tier 3, `toDatabase()` dengan icon & prioritas |
| 4 | `app/Notifications/PendingTicketReminderNotification.php` | **Buat baru** | Hitung `hariPending`, anti-spam check, `toDatabase()`, `toMail()`, `toWhatsApp()` |
| 5 | `app/Notifications/CsatReminderNotification.php` | **Buat baru** | Target: user (bukan admin), `toDatabase()` dengan aksi_url ke halaman user, `toMail()`, `toWhatsApp()` |
| 6 | `app/Models/ReminderConfig.php` | **Buat baru** | Cast `channel_aktif` ke array, static `getConfig()`, `isChannelActive()` |
| 7 | `app/Http/Controllers/Admin/ReminderConfigController.php` | **Buat baru** | `index()` render Inertia, `update()` batch update configs |
| 8 | `app/Http/Controllers/Admin/NotificationController.php` | **Buat baru** | `unreadCount()` JSON, `index()` paginated, `markAsRead()`, `snooze()`, `markAsDone()`, `markAllAsRead()` |
| 9 | `app/Console/Commands/BookingReminderCommand.php` | **Buat baru** | Query booking H-X, anti-spam check, notify admin unit |
| 10 | `app/Console/Commands/PendingTicketReminderCommand.php` | **Buat baru** | Query tiket pending lama, anti-spam 1x/hari, notify admin |
| 11 | `app/Console/Commands/CsatReminderCommand.php` | **Buat baru** | Query tiket Solve tanpa CSAT, max 3 reminder, interval 2 hari, notify user |
| 12 | `app/Console/Commands/SnoozeCheckCommand.php` | **Buat baru** | Query snoozed expired notifications, re-fire (set read_at = null) |
| 13 | `database/seeders/ReminderConfigSeeder.php` | **Buat baru** | Seeder 4 jenis reminder dengan default values |
| 14 | `database/seeders/DatabaseSeeder.php` | **Edit** | Tambahkan `$this->call(ReminderConfigSeeder::class)` |
| 15 | `routes/admin.php` (atau `routes/web.php`) | **Edit** | Tambah route reminder-config (GET/PUT) + notification routes (GET, PATCH, POST) |
| 16 | `routes/console.php` | **Edit** | Register schedule: `reminder:booking` daily 07:00, `reminder:pending` daily 08:00, `reminder:csat` daily 09:00, `reminder:snooze-check` everyFiveMinutes |

### 7.2 Frontend — React/TypeScript

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 17 | `resources/js/Pages/Admin/ReminderConfig/Index.tsx` | **Buat baru** | Tabel konfigurasi per jenis, checkbox channel, input lead time, toggle aktif, submit batch |
| 18 | `resources/js/Components/NotificationBell.tsx` | **Buat baru** | Icon lonceng + badge counter, `usePoll()` 15 detik, dropdown 10 terbaru, mark as read, snooze, link ke halaman full |
| 19 | `resources/js/Pages/Admin/Notifications/Index.tsx` | **Buat baru** | Halaman full notifikasi, filter status/tipe, paginated list, aksi snooze/done/read, timestamp relative |
| 20 | `resources/js/hooks/useNotificationSound.ts` | **Buat baru** | Hook play sound saat unreadCount naik, mute/unmute toggle, localStorage persist, Audio API |
| 21 | `resources/js/Layouts/AdminLayout.tsx` (atau layout admin yang ada) | **Edit** | Tambahkan `<NotificationBell />` di header/navbar |
| 22 | `resources/js/types/index.d.ts` (atau `types.ts`) | **Edit** | Tambahkan interface `ReminderConfig`, `NotificationItem`, `NotificationData` |

### 7.3 Assets & Konfigurasi

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 23 | `public/sounds/ting-ting-ting.mp3` | **Buat baru** | File audio notifikasi, < 50KB, durasi 1-2 detik |

### 7.4 Tests

| # | File | Aksi | Keterangan |
|---|------|------|-----------|
| 24 | `tests/Unit/WhatsAppChannelTest.php` | **Buat baru** | Mock HTTP client, test send method |
| 25 | `tests/Feature/ReminderConfigTest.php` | **Buat baru** | Test CRUD config, validasi channel |
| 26 | `tests/Feature/NotificationControllerTest.php` | **Buat baru** | Test unread count, mark read, snooze, done |
| 27 | `tests/Feature/BookingReminderCommandTest.php` | **Buat baru** | Test query booking, anti-spam, notification dispatch |
| 28 | `tests/Feature/PendingReminderCommandTest.php` | **Buat baru** | Test query pending, anti-spam |
| 29 | `tests/Feature/CsatReminderCommandTest.php` | **Buat baru** | Test query CSAT, max 3 reminder, interval |
| 30 | `tests/Feature/SnoozeCheckCommandTest.php` | **Buat baru** | Test re-fire expired snooze |

### 7.5 Urutan Implementasi

```
1. WhatsAppChannel (custom notification channel)
2. ReminderConfig model + seeder + migration (jika belum ada)
3. ReminderConfigController + routes + React page
4. 4 Notification classes (Booking, SLA, Pending, CSAT)
5. 4 Scheduler commands + register di routes/console.php
6. NotificationController + routes
7. NotificationBell component + integrasi ke layout admin
8. Halaman full notifikasi (Admin/Notifications/Index.tsx)
9. useNotificationSound hook + audio file
10. Tests
```

---

> **Catatan:** Pastikan `php artisan queue:work` atau `php artisan queue:listen` berjalan di production agar notifikasi yang implements `ShouldQueue` dapat diproses. Di cPanel, bisa menggunakan Supervisor atau cron job tambahan untuk queue worker.
