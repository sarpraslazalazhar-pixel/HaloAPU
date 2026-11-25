<?php

namespace App\Events;

use App\Models\TicketLog;
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
        $log->loadMissing(['admin', 'attachments']);

        $this->ticketId = (int) $log->ticket_id;

        $this->log = [
            'id' => $log->id,
            'ticket_id' => $this->ticketId,
            'admin_id' => $log->admin_id,
            'aksi' => $log->aksi,
            'catatan' => $log->catatan,
            'is_edited' => (bool) $log->is_edited,
            'edited_at' => $log->edited_at?->toIso8601String(),
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
        return [new PrivateChannel('ticket.'.$this->ticketId)];
    }

    public function broadcastAs(): string
    {
        return 'TicketMessageEdited';
    }
}
