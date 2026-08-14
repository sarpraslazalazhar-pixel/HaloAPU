<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use App\Notifications\CustomResetPasswordNotification;

use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, HasPushSubscriptions;

    /**
     * Send the password reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new CustomResetPasswordNotification($token));
    }

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'no_wa',
        'avatar_path',
        'device_id',
        'device_name',
        'divisi_id',
        'org_unit_id',
        'jabatan_id',
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

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function messages()
    {
        return $this->morphMany(Message::class, 'sender');
    }

    public function divisi()
    {
        return $this->belongsTo(OrgDivisi::class, 'divisi_id');
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function jabatan()
    {
        return $this->belongsTo(OrgJabatan::class, 'jabatan_id');
    }
}
