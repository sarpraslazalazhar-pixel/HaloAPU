<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\ReminderConfig;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CsatReminderNotification extends Notification
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
            'pesan' => "Tiket #{$this->ticket->formatted_id} \"{$this->ticket->judul}\" sudah diselesaikan {$this->hariSejak} hari lalu. Mohon berikan rating Anda.",
            'icon' => 'star',
            'aksi_url' => "/tiket/{$this->ticket->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Berikan Rating untuk Tiket #{$this->ticket->formatted_id} — Halo APU")
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Tiket Anda telah diselesaikan. Kami ingin mendengar pendapat Anda!")
            ->line("**Tiket:** #{$this->ticket->formatted_id} — {$this->ticket->judul}")
            ->line("**Diselesaikan:** {$this->hariSejak} hari lalu")
            ->action('Berikan Rating', url("/tiket/{$this->ticket->id}"))
            ->line('Rating Anda sangat berarti bagi peningkatan layanan kami. Terima kasih!');
    }

    public function toWhatsApp(object $notifiable): array
    {
        $nama = $notifiable->name ?: ($notifiable->username ?? 'Kak');
        $link = url("/tiket/{$this->ticket->id}");

        $message = "Halo *{$nama}* 👋\n\n";
        $message .= "Ada info baru nih buat pengajuan *{$this->ticket->formatted_id}* Kamu. Pengajuannya udah selesai dikerjakan ya 😊\n\n";
        $message .= "Mohon ketersediaannya untuk memberikan Rating Kepuasan (CSAT) buat pengajuan ini.\n\n";
        $message .= "Biar lebih jelas, langsung aja isi rating dan cek detailnya di sini:\n{$link}\n\n";
        $message .= "Terima kasih";

        return [
            'receiver' => $notifiable->no_wa,
            'message' => $message,
        ];
    }
}
