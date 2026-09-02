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
        protected $booking
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
            'tanggal_mulai' => \Carbon\Carbon::parse($this->booking->tanggal_mulai)->format('d M Y H:i'),
            'tanggal_selesai' => \Carbon\Carbon::parse($this->booking->tanggal_selesai)->format('d M Y H:i'),
            'judul' => "Reminder Booking {$tipeLabel}",
            'pesan' => "Booking {$tipeLabel} \"{$this->booking->nama_aset}\" dijadwalkan pada " . \Carbon\Carbon::parse($this->booking->tanggal_mulai)->format('d M Y H:i') . ".",
            'icon' => 'calendar',
            'aksi_url' => $notifiable instanceof \App\Models\Admin ? "/admin/tiketing/{$this->booking->ticket_id}" : "/tiket/{$this->booking->ticket_id}",
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
            ->line("**Mulai:** " . \Carbon\Carbon::parse($this->booking->tanggal_mulai)->format('d M Y H:i'))
            ->line("**Selesai:** " . \Carbon\Carbon::parse($this->booking->tanggal_selesai)->format('d M Y H:i'))
            ->action('Lihat Detail', url($notifiable instanceof \App\Models\Admin ? "/admin/tiketing/{$this->booking->ticket_id}" : "/tiket/{$this->booking->ticket_id}"))
            ->line('Terima kasih telah menggunakan Halo APU.');
    }

    /**
     * WhatsApp notification.
     */
    public function toWhatsApp(object $notifiable): array
    {
        $tipeLabel = $this->booking->tipe === 'ruang' ? 'Ruang' : 'Kendaraan';
        $namaAdmin = $notifiable->name ?? ($notifiable->nama ?? 'Kak');

        $message = "Halo *{$namaAdmin}* 👋\n\n";
        $message .= "Ada info baru nih buat pemakaian *{$tipeLabel}* Kamu. Jadwalnya udah mau mulai ya 😊\n\n";
        $message .= "📌 *Aset:* {$this->booking->nama_aset}\n";
        $message .= "⏱️ *Mulai:* " . \Carbon\Carbon::parse($this->booking->tanggal_mulai)->format('d M Y H:i') . "\n";
        $message .= "🏁 *Selesai:* " . \Carbon\Carbon::parse($this->booking->tanggal_selesai)->format('d M Y H:i') . "\n\n";
        $message .= "Biar lebih jelas, langsung aja cek detail pengajuannya di sistem kita.\n\n";
        $message .= "Terima kasih";

        return [
            'receiver' => $notifiable->no_wa,
            'message' => $message,
        ];
    }
}
