<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TicketRatedAdminNotification extends Notification
{
    use Queueable;

    public $ticket;
    public $rating;
    public $komentar;

    public function __construct(Ticket $ticket, $rating, $komentar)
    {
        $this->ticket = $ticket;
        $this->rating = $rating;
        $this->komentar = $komentar;
    }

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('Rating Baru: Tiket #' . $this->ticket->id)
            ->icon('/images/logo.png')
            ->body("Tiket diberi rating " . $this->rating . " bintang. " . \Illuminate\Support\Str::limit($this->komentar, 50))
            ->action('Lihat Tiket', url('/admin/tiket/' . $this->ticket->id))
            ->data(['url' => url('/admin/tiket/' . $this->ticket->id)]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ticket_rated',
            'ticket_id' => $this->ticket->id,
            'title' => 'Rating Baru: Tiket #' . $this->ticket->id,
            'message' => "Tiket diberi rating {$this->rating} bintang.",
            'url' => url('/admin/tiket/' . $this->ticket->id),
        ];
    }
}
