<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

use NotificationChannels\WebPush\HasPushSubscriptions;

class Admin extends Authenticatable
{
    use Notifiable, HasRoles, HasPushSubscriptions;

    protected $guard_name = 'admin';

    protected $fillable = [
        'username',
        'email',
        'password',
        'name',
        'no_wa',
        'avatar_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function getNameAttribute(): string
    {
        return !empty($this->attributes['name']) ? $this->attributes['name'] : $this->username;
    }

    public function units()
    {
        return $this->belongsToMany(Unit::class, 'admin_unit', 'admin_id', 'unit_id');
    }

    public function subUnits()
    {
        return $this->belongsToMany(SubUnit::class, 'admin_sub_unit', 'admin_id', 'sub_unit_id');
    }
}

