<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiConversation extends Model
{
    protected $fillable = [
        'admin_id',
        'title',
        'scope_filters',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'scope_filters' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'ai_conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(AiMessage::class, 'ai_conversation_id')->latestOfMany();
    }
}
