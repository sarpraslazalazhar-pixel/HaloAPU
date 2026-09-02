<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketLog extends Model
{
    public $timestamps = false; // We use timestamp column manually

    protected $fillable = [
        'ticket_id', 'admin_id', 'aksi', 'catatan', 'timestamp'
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

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
