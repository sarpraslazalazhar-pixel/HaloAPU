<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Empty signal: the operator's active slot changed, so the client refetches
 * `admin.tiket.aktif` instead of trusting data pushed over the socket.
 */
class ActiveAssignedTicketChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(private readonly int $adminId)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.Admin.'.$this->adminId)];
    }

    public function broadcastAs(): string
    {
        return 'ActiveAssignedTicketChanged';
    }

    public function broadcastWith(): array
    {
        return [];
    }
}
