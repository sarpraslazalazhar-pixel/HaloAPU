<?php

namespace App\Models;

use App\Events\TicketMessagePosted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class TicketLog extends Model
{
    public $timestamps = false; // We use timestamp column manually

    protected $fillable = [
        'ticket_id', 'admin_id', 'aksi', 'catatan', 'timestamp'
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    /**
     * Every timeline entry — reply, status change, assignment — is created here,
     * from web, admin, or the mobile/SPARC API. Broadcasting from this single
     * hook keeps all of those sources realtime without touching each controller.
     */
    protected static function booted(): void
    {
        static::created(function (self $log) {
            try {
                broadcast(new TicketMessagePosted($log));
            } catch (\Throwable $e) {
                // Realtime must never break ticket writes.
                Log::warning('Ticket realtime broadcast failed: '.$e->getMessage());
            }
        });
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
}
