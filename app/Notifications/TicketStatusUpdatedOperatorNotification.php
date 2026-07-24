<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketStatusUpdatedOperatorNotification extends Notification
{
    use Queueable;

    public $ticket;
    public $catatan;
    public $pengubahName;

    public function __construct(Ticket $ticket, $catatan, $pengubahName)
    {
        $this->ticket = $ticket;
        $this->catatan = $catatan;
        $this->pengubahName = $pengubahName;
    }

    public function via(object $notifiable): array
    {
        $channels = ['database', \NotificationChannels\WebPush\WebPushChannel::class];
        if (!empty($notifiable->no_wa)) {
            $channels[] = WhatsAppChannel::class;
        }
        return $channels;
    }

    public function toWebPush($notifiable, $notification)
    {
        $statusStr = ucwords(str_replace('_', ' ', $this->ticket->status));
        return (new \NotificationChannels\WebPush\WebPushMessage)
            ->title('Status Tiket (Ditugaskan) Diubah')
            ->icon('/images/logo.png')
            ->body("{$this->pengubahName} mengubah status tiket #{$this->ticket->formatted_id} menjadi {$statusStr}.")
            ->action('Lihat Tiket', route('admin.tiket.show', $this->ticket->id))
            ->data(['url' => route('admin.tiket.show', $this->ticket->id)]);
    }

    public function toArray(object $notifiable): array
    {
        $statusStr = ucwords(str_replace('_', ' ', $this->ticket->status));
        return [
            'type' => 'ticket_status_updated_operator',
            'ticket_id' => $this->ticket->id,
            'title' => 'Status Tiket Diubah',
            'message' => "{$this->pengubahName} mengubah status tiket #{$this->ticket->formatted_id} menjadi {$statusStr}.",
            'url' => route('admin.tiket.show', $this->ticket->id),
        ];
    }

    public function toWhatsApp(object $notifiable): array
    {
        $nama = $notifiable->name ?: $notifiable->username;
        $statusStr = ucwords(str_replace('_', ' ', $this->ticket->status));
        $url = route('admin.tiket.show', $this->ticket->id);
        
        $message = "Halo *{$nama}* 👋\n\n";
        $message .= "Ada update status pada tiket yang ditugaskan kepada Anda:\n\n";
        $message .= "🎫 *ID Tiket:* #{$this->ticket->formatted_id}\n";
        $message .= "📌 *Status Baru:* {$statusStr}\n";
        $message .= "👤 *Diubah Oleh:* {$this->pengubahName}\n";
        
        if (!empty($this->catatan)) {
            $message .= "📝 *Catatan:* _{$this->catatan}_\n\n";
        } else {
            $message .= "\n";
        }
        
        $message .= "Cek detail tiket selengkapnya di sini:\n{$url}\n\n";
        
        $message .= "Terima kasih";

        return [
            'receiver' => $notifiable->no_wa,
            'message' => $message,
        ];
    }
}
