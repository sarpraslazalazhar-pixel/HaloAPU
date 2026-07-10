<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketSlaTracking extends Model
{
    protected $table = 'ticket_sla_tracking';

    protected $fillable = [
        'ticket_id',
        'sla_response_deadline',
        'sla_resolution_deadline',
        'responded_at',
        'resolved_at',
        'paused_at',
        'total_paused_minutes',
        'current_tier',
        'is_response_breached',
        'is_resolution_breached',
    ];

    protected $casts = [
        'sla_response_deadline' => 'datetime',
        'sla_resolution_deadline' => 'datetime',
        'responded_at' => 'datetime',
        'resolved_at' => 'datetime',
        'paused_at' => 'datetime',
        'total_paused_minutes' => 'integer',
        'current_tier' => 'integer',
        'is_response_breached' => 'boolean',
        'is_resolution_breached' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    public function isBreached(): bool
    {
        return $this->is_response_breached || $this->is_resolution_breached;
    }
}
