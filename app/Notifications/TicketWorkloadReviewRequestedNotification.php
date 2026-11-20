<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Traits\FilterNotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class TicketWorkloadReviewRequestedNotification extends Notification implements ShouldBroadcast
{
    use Queueable, FilterNotificationChannels;

    public function __construct(public Ticket $ticket)
    {
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannels(['database', 'broadcast'], $notifiable);
    }

    private function payload(): array
    {
        $url = route('admin.laporan.tiket', [
            'year' => $this->ticket->created_at->year,
            'search' => (string) $this->ticket->id,
        ]).'#rincian-tiket';

        return [
            'type' => 'ticket_workload_review_requested',
            'ticket_id' => (int) $this->ticket->id,
            'title' => 'Tiket selesai perlu dinilai',
            'message' => "Nilai beban kerja tiket #TKT-{$this->ticket->formatted_id}.",
            'url' => $url,
            'aksi_url' => $url,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
