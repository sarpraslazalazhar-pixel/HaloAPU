<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketEditHistory extends Model
{
    protected $fillable = [
        'ticket_id', 'edited_by', 'edit_reason', 'changes',
    ];

    protected $casts = [
        'changes' => 'array',
        'ticket_id' => 'integer',
        'edited_by' => 'integer',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function editor()
    {
        return $this->belongsTo(Admin::class, 'edited_by');
    }

    /**
     * Bentuk seragam untuk web (Inertia) & mobile (API).
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'edit_reason' => $this->edit_reason,
            // `$this->changes` menunjuk properti internal Eloquent, bukan kolom JSON.
            'changes' => $this->getAttribute('changes') ?? [],
            'edited_by_name' => $this->editor?->name ?? 'Admin',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
