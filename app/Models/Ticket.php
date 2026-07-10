<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    protected $fillable = [
        'user_id', 'divisi_id', 'org_unit_id', 'jabatan_id',
        'unit_id', 'sub_unit_id', 'form_data', 'status'
    ];

    protected $casts = [
        'form_data' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function subUnit()
    {
        return $this->belongsTo(SubUnit::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function logs()
    {
        return $this->hasMany(TicketLog::class);
    }

    public function orgDivisi()
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

    public function slaTracking()
    {
        return $this->hasOne(TicketSlaTracking::class);
    }
}
