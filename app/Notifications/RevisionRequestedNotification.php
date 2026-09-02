<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class RevisionRequestedNotification extends Notification
{
    use Queueable;

    public $ticket;
    public $catatan;

    public function __construct(Ticket $ticket, string $catatan)
    {
        $this->ticket = $ticket;
        $this->catatan = $catatan;
    }

    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable instanceof \App\Models\Admin) {
            $channels[] = 'database';
            $channels[] = \NotificationChannels\WebPush\WebPushChannel::class;
            if (!empty($notifiable->no_wa)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        if ($notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable) {
            $nomorUtama = \App\Models\SystemConfig::getValue('nomor_wa_utama');
            if (!empty($nomorUtama)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return $channels;
    }

    public function toWebPush($notifiable, $notification)
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        return (new \NotificationChannels\WebPush\WebPushMessage)
            ->title('Permintaan Revisi Tiket')
            ->icon('/images/logo.png')
            ->body("Tiket {$this->ticket->formatted_id} ({$layanan}) perlu direvisi. Catatan: " . \Illuminate\Support\Str::limit($this->catatan, 50))
            ->action('Lihat Tiket', url('/admin/tiket/' . $this->ticket->id))
            ->data(['url' => url('/admin/tiket/' . $this->ticket->id)]);
    }

    public function toDatabase(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';

        return [
            'type' => 'revision_requested',
            'ticket_id' => $this->ticket->id,
            'title' => 'Permintaan Revisi Tiket',
            'message' => "Tiket {$this->ticket->formatted_id} ({$layanan}) perlu direvisi. Catatan dari pemohon: \"{$this->catatan}\"",
            'url' => url('/admin/tiket/' . $this->ticket->id),
        ];
    }

    public function toWhatsApp(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        $url = url('/admin/tiket/' . $this->ticket->id);

        $namaAdmin = $notifiable->name ?? ($notifiable->nama ?? 'Admin');

        $message = "Halo *{$namaAdmin}* 👋\n\n";
        $message .= "Ada info baru nih buat pengajuan *{$this->ticket->formatted_id}* ({$layanan}). Pengajuannya dikembalikan oleh pemohon untuk direvisi ya 😊\n\n";
        $message .= "📝 *Catatan Revisi:*\n_{$this->catatan}_\n\n";
        $message .= "Biar lebih jelas, langsung aja cek detailnya di sini:\n{$url}\n\n";
        $message .= "Terima kasih";

        if ($notifiable instanceof \App\Models\Admin) {
            $receiver = $notifiable->no_wa;
        } else {
            $receiver = \App\Models\SystemConfig::getValue('nomor_wa_utama');
        }

        return [
            'receiver' => $receiver,
            'message' => $message,
        ];
    }
}
