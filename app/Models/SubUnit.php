<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubUnit extends Model
{
    protected $fillable = [
        'unit_id', 'nama_layanan', 'deskripsi', 'aktif',
        'is_monitored', 'monitor_kategori',
        'monitor_asset_field_id', 'monitor_date_field_id', 'monitor_end_date_field_id', 'monitor_start_field_id', 'monitor_end_field_id',
        'is_revision_enabled', 'wajib_kembali'
    ];

    protected $casts = [
        'aktif' => 'boolean',
        'is_monitored' => 'boolean',
        'is_revision_enabled' => 'boolean',
        'wajib_kembali' => 'boolean',
    ];

    protected static function booted()
    {
        static::saved(function ($subUnit) {
            \Illuminate\Support\Facades\Cache::forget("sub_units_{$subUnit->unit_id}");
            \Illuminate\Support\Facades\Cache::forget('admin_subunits_with_unit');
        });
        static::deleted(function ($subUnit) {
            \Illuminate\Support\Facades\Cache::forget("sub_units_{$subUnit->unit_id}");
            \Illuminate\Support\Facades\Cache::forget('admin_subunits_with_unit');
        });
    }

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
