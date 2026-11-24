<?php

namespace App\Notifications;

use App\Channels\FcmChannel;
use App\Models\Admin;
use App\Models\Ticket;
use App\Traits\FilterNotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TicketWorkHoursNotification extends Notification implements ShouldBroadcast
{
    use Queueable, FilterNotificationChannels;

    public function __construct(
        public Ticket $ticket,
        public string $message,
        public bool $resumeReminder = false,
        public ?string $customTitle = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast', WebPushChannel::class];
        if (! empty($notifiable->fcm_token)) {
            $channels[] = FcmChannel::class;
        }

        return $this->filterChannels($channels, $notifiable);
    }

    private function urlFor(object $notifiable): string
    {
        return $notifiable instanceof Admin
            ? route('admin.tiket.show', $this->ticket->id)
            : route('tiket.show', $this->ticket->id);
    }

    private function title(): string
    {
        if ($this->customTitle !== null) {
            return $this->customTitle;
        }

        return $this->resumeReminder
            ? "Jam kerja sudah buka: Tiket #TKT-{$this->ticket->formatted_id}"
            : "Jam kerja berakhir: Tiket #TKT-{$this->ticket->formatted_id}";
    }

    public function toArray(object $notifiable): array
    {
        $url = $this->urlFor($notifiable);

        return [
            'type' => 'ticket_work_hours',
            'ticket_id' => (int) $this->ticket->id,
            'title' => $this->title(),
            'message' => $this->message,
            'url' => $url,
            'aksi_url' => $url,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toWebPush(object $notifiable, $notification): WebPushMessage
    {
        $url = $this->urlFor($notifiable);

        return (new WebPushMessage)
            ->title($this->title())
            ->icon('/images/logo.png')
            ->body($this->message)
            ->action('Lihat Tiket', $url)
            ->data(['url' => $url]);
    }

    public function toFcm(object $notifiable): array
    {
        $payload = $this->toArray($notifiable);

        return [
            'title' => $payload['title'],
            'body' => $payload['message'],
            'ticket_id' => (string) $this->ticket->id,
            'url' => $payload['url'],
            'type' => 'ticket_work_hours',
        ];
    }
}
