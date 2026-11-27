<?php

namespace App\Events;

use App\Models\TicketLog;
use App\Services\TicketChatBridgeService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketMessageEdited implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $log;

    public int $ticketId;

    public function __construct(TicketLog $log)
    {
        $this->ticketId = (int) $log->ticket_id;
        $this->log = TicketChatBridgeService::serializeTicketLog($log);
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('ticket.'.$this->ticketId)];
    }

    public function broadcastAs(): string
    {
        return 'TicketMessageEdited';
    }
}
