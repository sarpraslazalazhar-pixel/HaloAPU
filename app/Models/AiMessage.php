<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'is_fallback',
        'model_name',
        'tools_used',
        'charts',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'ai_conversation_id' => 'integer',
            'is_fallback' => 'boolean',
            'tools_used' => 'array',
            'charts' => 'array',
            'meta' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
