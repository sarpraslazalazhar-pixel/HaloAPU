<?php

namespace App\Notifications;

use App\Channels\FcmChannel;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketEditHistory;
use App\Traits\FilterNotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class TicketFormEditedNotification extends Notification implements ShouldBroadcast
{
    use FilterNotificationChannels, Queueable;

    public function __construct(
        public Ticket $ticket,
        public TicketEditHistory $history,
        public Admin $editor,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast', \NotificationChannels\WebPush\WebPushChannel::class];
        if (! empty($notifiable->fcm_token)) {
            $channels[] = FcmChannel::class;
        }

        return $this->filterChannels($channels, $notifiable);
    }

    private function editorName(): string
    {
        return $this->editor->name ?: $this->editor->username;
    }

    private function changedLabels(): array
    {
        return array_values(array_map(fn ($c) => $c['label'] ?? '', $this->history->changes ?? []));
    }

    private function url(object $notifiable): string
    {
        return $notifiable instanceof Admin
            ? route('admin.tiket.show', $this->ticket->id)
            : route('tiket.show', $this->ticket->id);
    }

    private function message(): string
    {
        return 'Admin '.$this->editorName().' memperbarui '.implode(', ', $this->changedLabels())
            .' pada tiket #'.$this->ticket->id.'. Alasan: '.$this->history->edit_reason;
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => 'Formulir Tiket #'.$this->ticket->id.' Diperbarui',
            'body' => $this->message(),
            'ticket_id' => (string) $this->ticket->id,
            'url' => $this->url($notifiable),
            'type' => 'ticket_form_edited',
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => 'ticket_form_edited',
            'ticket_id' => $this->ticket->id,
            'title' => 'Formulir Tiket Diperbarui',
            'message' => $this->message(),
            'url' => $this->url($notifiable),
        ]);
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new \NotificationChannels\WebPush\WebPushMessage)
            ->title('Formulir Tiket Diperbarui')
            ->icon('/images/logo.png')
            ->body($this->message())
            ->action('Lihat Tiket', $this->url($notifiable))
            ->data(['url' => $this->url($notifiable)]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_form_edited',
            'ticket_id' => $this->ticket->id,
            'title' => 'Formulir Tiket Diperbarui',
            'message' => $this->message(),
            'url' => $this->url($notifiable),
            'edit_reason' => $this->history->edit_reason,
            'changed_fields' => $this->changedLabels(),
        ];
    }
}
