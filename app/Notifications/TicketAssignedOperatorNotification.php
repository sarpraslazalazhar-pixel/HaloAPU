<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Channels\FcmChannel;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

use App\Traits\FilterNotificationChannels;

class TicketAssignedOperatorNotification extends Notification implements ShouldBroadcast
{
    use Queueable, FilterNotificationChannels;

    public $ticket;

    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }

    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable instanceof \App\Models\Admin) {
            $channels[] = 'database';
            $channels[] = 'broadcast';
            $channels[] = \NotificationChannels\WebPush\WebPushChannel::class;
            if (!empty($notifiable->fcm_token)) {
                $channels[] = FcmChannel::class;
            }
            // Jika admin punya nomor WA, kirim juga via WhatsApp
            if (!empty($notifiable->no_wa)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        if ($notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable) {
            // Anonymous fallback not needed for operator assignment since it always targets a specific admin.
        }

        return $this->filterChannels($channels, $notifiable);
    }

    public function toFcm(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? 'Umum';
        return [
            'title' => 'Penugasan Tiket #' . $this->ticket->id,
            'body' => "Anda telah ditugaskan untuk menangani tiket layanan {$layanan}.",
            'ticket_id' => (string) $this->ticket->id,
            'url' => url('/admin/tiket/' . $this->ticket->id),
            'type' => 'ticket_assigned',
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     * Ini yang dikirim via Echo ke browser untuk real-time notification + suara.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';

        return new BroadcastMessage([
            'type' => 'ticket_assigned',
            'ticket_id' => $this->ticket->id,
            'title' => 'Penugasan Tiket Baru',
            'message' => "Anda telah ditugaskan untuk menangani tiket terkait layanan {$layanan}.",
            'url' => url('/admin/tiket/' . $this->ticket->id),
        ]);
    }

    public function toWebPush($notifiable, $notification)
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        return (new \NotificationChannels\WebPush\WebPushMessage)
            ->title('Penugasan Tiket Baru')
            ->icon('/images/logo.png')
            ->body("Anda telah ditugaskan untuk menangani tiket terkait layanan {$layanan}.")
            ->action('Lihat Tiket', url('/admin/tiket/' . $this->ticket->id))
            ->data(['url' => url('/admin/tiket/' . $this->ticket->id)]);
    }

    public function toArray(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';

        return [
            'type' => 'ticket_assigned',
            'ticket_id' => $this->ticket->id,
            'title' => 'Penugasan Tiket Baru',
            'message' => "Anda telah ditugaskan untuk menangani tiket terkait layanan {$layanan}.",
            'url' => url('/admin/tiket/' . $this->ticket->id),
        ];
    }

    public function toWhatsApp(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        $url = url('/admin/tiket/' . $this->ticket->id);
        
        $namaAdmin = $notifiable->name ?? ($notifiable->nama ?? 'Admin');

        $message = "Halo *{$namaAdmin}* 👋\n\n";
        $message .= "Anda telah ditugaskan untuk menangani tiket layanan *{$layanan}*.\n\n";
        $message .= "Mohon segera cek detailnya di sini:\n{$url}\n\n";
        $message .= "Terima kasih";

        // Tentukan nomor penerima
        $receiver = '';
        if ($notifiable instanceof \App\Models\Admin) {
            $receiver = $notifiable->no_wa;
        }

        return [
            'receiver' => $receiver,
            'message' => $message,
        ];
    }
}

