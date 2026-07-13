<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use App\Notifications\CustomResetPasswordNotification;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

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
        'divisi_id',
        'org_unit_id',
        'jabatan_id',
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

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
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
