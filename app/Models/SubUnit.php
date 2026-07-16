<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubUnit extends Model
{
    protected $fillable = [
        'unit_id', 'nama_layanan', 'deskripsi', 'aktif',
        'is_monitored', 'monitor_kategori',
        'monitor_asset_field_id', 'monitor_start_field_id', 'monitor_end_field_id'
    ];

    protected $casts = [
        'aktif' => 'boolean',
        'is_monitored' => 'boolean',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function formFields()
    {
        return $this->hasMany(FormField::class);
    }

    public function slaConfigs()
    {
        return $this->hasMany(SlaConfig::class);
    }
}
