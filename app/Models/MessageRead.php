<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class MessageRead extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'user_type',
        'user_id',
        'read_at',
    ];

    protected $casts = [
        'message_id' => 'integer',
        'user_id' => 'integer',
        'read_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (MessageRead $read) {
            $prefix = ($read->user_type === Admin::class || str_contains((string) $read->user_type, 'Admin'))
                ? 'admin'
                : 'user';
            Cache::forget("unread_chat_{$prefix}_{$read->user_id}");
        });
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}
