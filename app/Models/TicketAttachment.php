<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketAttachment extends Model
{
    protected $fillable = [
        'ticket_id', 'field_id', 'file_path', 'original_name',
        'mime_type', 'file_size', 'wajib'
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function field()
    {
        return $this->belongsTo(FormField::class, 'field_id');
    }
}
