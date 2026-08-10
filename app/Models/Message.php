<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'sender_type',
        'sender_id',
        'ticket_id',
        'reply_to_message_id',
        'body',
        'is_edited',
        'edited_at',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'sender_id' => 'integer',
        'ticket_id' => 'integer',
        'reply_to_message_id' => 'integer',
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->morphTo();
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class);
    }

    public function replyTo()
    {
        return $this->belongsTo(Message::class, 'reply_to_message_id');
    }

    public function reads()
    {
        return $this->hasMany(MessageRead::class);
    }

    public function attachments()
    {
        return $this->hasMany(ChatAttachment::class);
    }
}
