<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AccountDevice extends Model
{
    protected $fillable = [
        'authenticatable_type',
        'authenticatable_id',
        'device_id',
        'device_name',
        'ip_address',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Get the owning authenticatable model (User or Admin).
     */
    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
