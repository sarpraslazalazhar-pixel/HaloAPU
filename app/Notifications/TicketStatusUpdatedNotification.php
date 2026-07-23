<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketStatusUpdatedNotification extends Notification
{
    use Queueable;

    public $ticket;
    public $catatan;

    public function __construct(Ticket $ticket, $catatan)
    {
        $this->ticket = $ticket;
        $this->catatan = $catatan;
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
            ->title('Status Tiket Diubah')
            ->icon('/images/logo.png')
            ->body('Status tiket Anda berubah menjadi ' . $statusStr . '. Catatan: ' . \Illuminate\Support\Str::limit($this->catatan, 50))
            ->action('Lihat Tiket', route('tiket.show', $this->ticket->id))
            ->data(['url' => route('tiket.show', $this->ticket->id)]);
    }

    public function toArray(object $notifiable): array
    {
        $statusStr = ucwords(str_replace('_', ' ', $this->ticket->status));
        return [
            'type' => 'ticket_status_updated',
            'ticket_id' => $this->ticket->id,
            'title' => 'Status Tiket Diubah',
            'message' => 'Status tiket Anda berubah menjadi ' . $statusStr . '. Catatan: ' . $this->catatan,
            'url' => route('tiket.show', $this->ticket->id),
        ];
    }

    public function toWhatsApp(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        $nama = $notifiable->name ?: $notifiable->username;
        $statusStr = ucwords(str_replace('_', ' ', $this->ticket->status));
        $url = route('tiket.show', $this->ticket->id);
        
        $message = "Halo *{$nama}* 👋\n\n";
        $message .= "Ada info baru nih buat pengajuan Kamu. Statusnya udah di-update ya 😊\n\n";
        $message .= "📌 *Status Sekarang:* {$statusStr}\n";
        
        if (!empty($this->catatan)) {
            $message .= "📝 *Catatan Admin:* _{$this->catatan}_\n\n";
        } else {
            $message .= "\n";
        }
        
        if ($this->ticket->status === 'solve' || $this->ticket->status === 'selesai') {
            $message .= "Karena pengajuan telah selesai, mohon ketersediaannya untuk memberikan Rating Kepuasan (CSAT) melalui link berikut:\n{$url}\n\n";
        } else {
            $message .= "Biar lebih jelas, langsung aja cek detailnya di sini:\n{$url}\n\n";
        }
        
        $message .= "Terima kasih";

        return [
            'receiver' => $notifiable->no_wa,
            'message' => $message,
        ];
    }
}
