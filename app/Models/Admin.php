<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

use NotificationChannels\WebPush\HasPushSubscriptions;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, HasPushSubscriptions;

    protected $guard_name = 'admin';

    protected $fillable = [
        'username',
        'email',
        'password',
        'name',
        'no_wa',
        'avatar_path',
        'device_id',
        'device_name',
        'notify_browser',
        'notify_inapp',
        'notify_sound',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'notify_browser' => 'boolean',
            'notify_inapp' => 'boolean',
            'notify_sound' => 'boolean',
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

    public function messages()
    {
        return $this->morphMany(Message::class, 'sender');
    }

    public function devices()
    {
        return $this->morphMany(AccountDevice::class, 'authenticatable');
    }
}

