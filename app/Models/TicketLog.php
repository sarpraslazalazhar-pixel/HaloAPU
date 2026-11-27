<?php

namespace App\Models;

use App\Events\TicketMessagePosted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class TicketLog extends Model
{
    public $timestamps = false; // We use timestamp column manually

    protected $fillable = [
        'ticket_id', 'admin_id', 'aksi', 'catatan', 'is_edited', 'edited_at', 'timestamp'
    ];

    protected $casts = [
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
        'timestamp' => 'datetime',
    ];

    /**
     * Every timeline entry — reply, status change, assignment — is created here,
     * from web, admin, or the mobile/SPARC API. Broadcasting from this single
     * hook keeps all of those sources realtime without touching each controller.
     */
    protected static function booted(): void
    {
        static::created(fn (self $log) => $log->broadcastTimeline());
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_log_id');
    }

    /** Chat message mirrored from this reply, if any. */
    public function chatMessage()
    {
        return $this->hasOne(\App\Models\Message::class, 'ticket_log_id')->withTrashed();
    }

    public function broadcastTimeline(): void
    {
        // No realtime driver (cPanel): skip entirely so writes stay fast, while respecting Event::fake().
        if (\App\Services\TicketChatBridgeService::shouldSkipBroadcast()) {
            return;
        }

        try {
            broadcast(new TicketMessagePosted($this));
        } catch (\Throwable $e) {
            // Realtime must never break ticket writes.
            Log::warning('Ticket realtime broadcast failed: '.$e->getMessage());
        }
    }
}
