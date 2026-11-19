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
        'ticket_log_id',
        'reply_to_message_id',
        'body',
        'is_edited',
        'edited_at',
    ];

    protected $casts = [
        'conversation_id' => 'integer',
        'sender_id' => 'integer',
        'ticket_id' => 'integer',
        'ticket_log_id' => 'integer',
        'reply_to_message_id' => 'integer',
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        $invalidate = function (Message $message): void {
            try {
                $conv = $message->conversation;
                if ($conv?->user_id) {
                    \Illuminate\Support\Facades\Cache::forget("unread_chat_user_{$conv->user_id}");
                }
                if ($conv?->admin_one_id) {
                    \Illuminate\Support\Facades\Cache::forget("unread_chat_admin_{$conv->admin_one_id}");
                }
                if ($conv?->admin_two_id) {
                    \Illuminate\Support\Facades\Cache::forget("unread_chat_admin_{$conv->admin_two_id}");
                }

                $ticket = $message->ticket ?? $conv?->ticket;
                if ($ticket) {
                    if ($ticket->user_id) {
                        \Illuminate\Support\Facades\Cache::forget("unread_chat_user_{$ticket->user_id}");
                    }
                    if ($ticket->created_by_admin_id) {
                        \Illuminate\Support\Facades\Cache::forget("unread_chat_admin_{$ticket->created_by_admin_id}");
                    }
                    if ($ticket->assigned_admin_id) {
                        \Illuminate\Support\Facades\Cache::forget("unread_chat_admin_{$ticket->assigned_admin_id}");
                    }
                }
            } catch (\Throwable) {
                // Ignore cache flush errors in CLI/test contexts.
            }
        };

        static::created($invalidate);
        static::deleted($invalidate);
    }

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
