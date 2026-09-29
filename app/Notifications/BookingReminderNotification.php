<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\ReminderConfig;
// use App\Models\RoomVehicleBooking; // Assuming this model will exist or exists
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected $booking,
        protected string $stage = 'default'
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
        $tglMulai = \Carbon\Carbon::parse($this->booking->tanggal_mulai)->format('d M Y H:i');
        $tglSelesai = \Carbon\Carbon::parse($this->booking->tanggal_selesai)->format('d M Y H:i');

        if ($this->stage === 'h_min_10') {
            $judul = "Pengingat: 10 Menit Lagi Booking {$tipeLabel}";
            $pesan = "Booking {$tipeLabel} \"{$this->booking->nama_aset}\" akan dimulai dalam 10 menit ({$tglMulai}). Harap bersiap.";
            $icon = 'clock';
        } elseif ($this->stage === 'started') {
            $judul = "Jadwal Pemakaian {$tipeLabel} Telah Dimulai";
            $pesan = "Jadwal pemakaian {$tipeLabel} \"{$this->booking->nama_aset}\" sedang berlangsung ({$tglMulai} s/d {$tglSelesai}).";
            $icon = 'play-circle';
        } elseif ($this->stage === 'h_min_15_ending') {
            $judul = "PERINGATAN: Waktu Pemakaian {$tipeLabel} Tersisa 15 Menit";
            $pesan = "Waktu pemakaian {$tipeLabel} \"{$this->booking->nama_aset}\" tersisa 15 menit lagi (selesai {$tglSelesai}). Segera selesaikan dan kembalikan aset tepat waktu. Keterlambatan dapat dikenakan denda sesuai regulasi.";
            $icon = 'alert-triangle';
        } else {
            $judul = "Reminder Booking {$tipeLabel}";
            $pesan = "Booking {$tipeLabel} \"{$this->booking->nama_aset}\" dijadwalkan pada {$tglMulai}.";
            $icon = 'calendar';
        }

        return [
            'booking_id' => $this->booking->id,
            'ticket_id' => $this->booking->ticket_id,
            'tipe' => $this->booking->tipe,
            'nama_aset' => $this->booking->nama_aset,
            'tanggal_mulai' => $tglMulai,
            'tanggal_selesai' => $tglSelesai,
            'stage' => $this->stage,
            'judul' => $judul,
            'pesan' => $pesan,
            'icon' => $icon,
            'aksi_url' => $notifiable instanceof \App\Models\Admin ? "/admin/tiketing/{$this->booking->ticket_id}" : "/tiket/{$this->booking->ticket_id}",
        ];
    }

    /**
     * Email notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $tipeLabel = $this->booking->tipe === 'ruang' ? 'Ruang' : 'Kendaraan';
        $tglMulai = \Carbon\Carbon::parse($this->booking->tanggal_mulai)->format('d M Y H:i');
        $tglSelesai = \Carbon\Carbon::parse($this->booking->tanggal_selesai)->format('d M Y H:i');

        $mail = (new MailMessage);

        if ($this->stage === 'h_min_10') {
            $mail->subject("Pengingat: 10 Menit Lagi Booking {$tipeLabel} — Halo APU")
                ->greeting("Halo, {$notifiable->name}!")
                ->line("Jadwal pemakaian {$tipeLabel} berikut akan dimulai dalam **10 menit**:")
                ->line("**Aset:** {$this->booking->nama_aset}")
                ->line("**Mulai:** {$tglMulai}")
                ->line("**Selesai:** {$tglSelesai}")
                ->line("Mohon pastikan persiapan Anda telah selesai.")
                ->action('Lihat Detail', url($notifiable instanceof \App\Models\Admin ? "/admin/tiketing/{$this->booking->ticket_id}" : "/tiket/{$this->booking->ticket_id}"));
        } elseif ($this->stage === 'started') {
            $mail->subject("Jadwal Pemakaian {$tipeLabel} Dimulai — Halo APU")
                ->greeting("Halo, {$notifiable->name}!")
                ->line("Jadwal pemakaian {$tipeLabel} berikut **telah dimulai** sekarang:")
                ->line("**Aset:** {$this->booking->nama_aset}")
                ->line("**Mulai:** {$tglMulai}")
                ->line("**Selesai:** {$tglSelesai}")
                ->line("Selamat beraktivitas dan mohon tetap menjaga kebersihan serta fasilitas aset.")
                ->action('Lihat Detail', url($notifiable instanceof \App\Models\Admin ? "/admin/tiketing/{$this->booking->ticket_id}" : "/tiket/{$this->booking->ticket_id}"));
        } elseif ($this->stage === 'h_min_15_ending') {
            $mail->subject("⚠️ PERINGATAN: 15 Menit Lagi Waktu Pemakaian {$tipeLabel} Habis — Halo APU")
                ->greeting("Halo, {$notifiable->name}!")
                ->line("Waktu pemakaian {$tipeLabel} berikut tersisa **15 menit lagi**:")
                ->line("**Aset:** {$this->booking->nama_aset}")
                ->line("**Batas Waktu Selesai:** {$tglSelesai}")
                ->line("**PENTING:** Harap segera merapikan/mengosongkan ruangan atau mengembalikan kendaraan tepat waktu. Keterlambatan pengembalian dapat dikenakan sanksi/denda sesuai peraturan yang berlaku.")
                ->action('Lihat Detail', url($notifiable instanceof \App\Models\Admin ? "/admin/tiketing/{$this->booking->ticket_id}" : "/tiket/{$this->booking->ticket_id}"));
        } else {
            $mail->subject("Reminder Booking {$tipeLabel} — Halo APU")
                ->greeting("Halo, {$notifiable->name}!")
                ->line("Ini adalah pengingat bahwa booking {$tipeLabel} berikut akan segera dimulai:")
                ->line("**Aset:** {$this->booking->nama_aset}")
                ->line("**Mulai:** {$tglMulai}")
                ->line("**Selesai:** {$tglSelesai}")
                ->action('Lihat Detail', url($notifiable instanceof \App\Models\Admin ? "/admin/tiketing/{$this->booking->ticket_id}" : "/tiket/{$this->booking->ticket_id}"));
        }

        return $mail->line('Terima kasih telah menggunakan Halo APU.');
    }

    /**
     * WhatsApp notification.
     */
    public function toWhatsApp(object $notifiable): array
    {
        $tipeLabel = $this->booking->tipe === 'ruang' ? 'Ruang' : 'Kendaraan';
        $namaAdmin = $notifiable->name ?? ($notifiable->nama ?? 'Bapak/Ibu');
        $tglMulai = \Carbon\Carbon::parse($this->booking->tanggal_mulai)->format('d M Y H:i');
        $tglSelesai = \Carbon\Carbon::parse($this->booking->tanggal_selesai)->format('d M Y H:i');

        if ($this->stage === 'h_min_10') {
            $message = "Halo *{$namaAdmin}* 👋\n\n";
            $message .= "🔔 *PENGINGAT 10 MENIT LAGI*\n";
            $message .= "Jadwal pemakaian *{$tipeLabel}* Anda akan dimulai dalam *10 menit*.\n\n";
            $message .= "📌 *Aset:* {$this->booking->nama_aset}\n";
            $message .= "⏱️ *Mulai:* {$tglMulai}\n";
            $message .= "🏁 *Selesai:* {$tglSelesai}\n\n";
            $message .= "Harap segera bersiap di lokasi. Terima kasih.";
        } elseif ($this->stage === 'started') {
            $message = "Halo *{$namaAdmin}* 👋\n\n";
            $message .= "▶️ *JADWAL PEMAKAIAN TELAH DIMULAI*\n";
            $message .= "Jadwal pemakaian *{$tipeLabel}* Anda telah resmi dimulai sekarang.\n\n";
            $message .= "📌 *Aset:* {$this->booking->nama_aset}\n";
            $message .= "⏱️ *Mulai:* {$tglMulai}\n";
            $message .= "🏁 *Selesai:* {$tglSelesai}\n\n";
            $message .= "Selamat beraktivitas dan mohon tetap menjaga fasilitas aset dengan baik.";
        } elseif ($this->stage === 'h_min_15_ending') {
            $message = "Halo *{$namaAdmin}* 👋\n\n";
            $message .= "⚠️ *PERINGATAN WAKTU HABIS*\n";
            $message .= "Waktu pemakaian *{$tipeLabel}* Anda tersisa *15 menit lagi*.\n\n";
            $message .= "📌 *Aset:* {$this->booking->nama_aset}\n";
            $message .= "🏁 *Batas Waktu Selesai:* {$tglSelesai}\n\n";
            $message .= "❗ *PERHATIAN:* Mohon segera mengosongkan ruangan atau mengembalikan unit kendaraan tepat waktu. Keterlambatan dapat dikenakan *denda* sesuai peraturan yang berlaku.\n\n";
            $message .= "Terima kasih atas kerja samanya.";
        } else {
            $message = "Halo *{$namaAdmin}* 👋\n\n";
            $message .= "Ada info baru nih buat pemakaian *{$tipeLabel}* Kamu. Jadwalnya udah mau mulai ya 😊\n\n";
            $message .= "📌 *Aset:* {$this->booking->nama_aset}\n";
            $message .= "⏱️ *Mulai:* {$tglMulai}\n";
            $message .= "🏁 *Selesai:* {$tglSelesai}\n\n";
            $message .= "Biar lebih jelas, langsung aja cek detail pengajuannya di sistem kita.\n\n";
            $message .= "Terima kasih";
        }

        return [
            'receiver' => $notifiable->no_wa,
            'message' => $message,
        ];
    }
}
