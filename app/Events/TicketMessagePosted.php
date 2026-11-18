<?php

namespace App\Events;

use App\Models\TicketLog;
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
        $log->loadMissing(['admin', 'attachments']);

        $this->ticketId = (int) $log->ticket_id;

        $this->log = [
            'id' => $log->id,
            'ticket_id' => $this->ticketId,
            'admin_id' => $log->admin_id,
            'aksi' => $log->aksi,
            'catatan' => $log->catatan,
            'timestamp' => $log->timestamp?->toIso8601String(),
            'admin' => $log->admin ? [
                'id' => $log->admin->id,
                'name' => $log->admin->name,
                'username' => $log->admin->username,
            ] : null,
            'attachments' => $log->attachments->map(fn ($att) => [
                'id' => $att->id,
                'original_name' => $att->original_name,
                'file_name' => $att->original_name ?: basename((string) $att->file_path),
                'file_path' => $att->file_path,
                'mime_type' => $att->mime_type,
                'file_size' => $att->file_size,
            ])->all(),
        ];
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
