<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiBriefing extends Model
{
    protected $fillable = [
        'admin_id',
        'period_type',
        'briefing_date',
        'scope_label',
        'title',
        'summary_markdown',
        'metrics_snapshot',
        'is_fallback',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'briefing_date' => 'date',
            'metrics_snapshot' => 'array',
            'is_fallback' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
