<?php

namespace App\Events;

use App\Models\TicketLog;
use App\Services\TicketChatBridgeService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A single ticket timeline entry, pushed to everyone watching that ticket.
 *
 * Ticket replies arrive from three places (web user, web admin, mobile/SPARC),
 * so this fires from TicketChatBridgeService rather than from each controller.
 */
class TicketMessagePosted implements ShouldBroadcastNow
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
        return [new PrivateChannel('ticket.' . $this->ticketId)];
    }

    public function broadcastAs(): string
    {
        return 'TicketMessagePosted';
    }
}
