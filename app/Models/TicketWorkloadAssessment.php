<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketWorkloadAssessment extends Model
{
    protected $fillable = [
        'score', 'effort_hours', 'note', 'assessed_by_admin_id',
    ];

    protected $hidden = ['legacy_request_count'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'effort_hours' => 'decimal:2',
        ];
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assessed_by_admin_id');
    }
}
