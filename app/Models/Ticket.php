<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    public $incrementing = false;
    protected $keyType = 'integer';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                do {
                    $id = random_int(100000000, 999999999);
                } while (self::where($model->getKeyName(), $id)->exists());
                $model->{$model->getKeyName()} = $id;
            }
        });
    }
    protected $fillable = [
        'user_id', 'divisi_id', 'org_unit_id', 'jabatan_id',
        'unit_id', 'sub_unit_id', 'form_data', 'status', 'priority', 'assigned_admin_id',
        'revision_count', 'waiting_approval_at', 'is_result_accepted'
    ];

    public function getFormattedIdAttribute()
    {
        $id = (string) $this->id;
        if (strlen($id) === 9) {
            return substr($id, 0, 3) . '-' . substr($id, 3, 3) . '-' . substr($id, 6, 3);
        }
        return $id;
    }

    public function getJudulAttribute()
    {
        if (!empty($this->form_data) && is_array($this->form_data)) {
            foreach ($this->form_data as $key => $value) {
                if (is_string($value) && !empty($value) && strlen($value) < 100) {
                    return $value;
                }
            }
        }
        return $this->subUnit ? $this->subUnit->nama_layanan : 'Tiket #' . $this->formatted_id;
    }

    protected $casts = [
        'form_data' => 'array',
        'user_id' => 'integer',
        'divisi_id' => 'integer',
        'org_unit_id' => 'integer',
        'jabatan_id' => 'integer',
        'unit_id' => 'integer',
        'sub_unit_id' => 'integer',
        'is_result_accepted' => 'boolean',
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

    public function csat()
    {
        return $this->hasOne(Csat::class);
    }

    public function booking()
    {
        return $this->hasOne(RoomVehicleBooking::class);
    }

    public function assignedAdmin()
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }
}

