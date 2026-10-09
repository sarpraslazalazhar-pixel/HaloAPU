<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['nama_unit', 'icon', 'deskripsi', 'aktif'];

    protected static function booted()
    {
        static::saved(function ($model) {
            \Illuminate\Support\Facades\Cache::forget('master_units_active');
            \Illuminate\Support\Facades\Cache::forget("sub_units_{$model->id}");
        });
        static::deleted(function ($model) {
            \Illuminate\Support\Facades\Cache::forget('master_units_active');
            \Illuminate\Support\Facades\Cache::forget("sub_units_{$model->id}");
        });
    }

    public function subUnits()
    {
        return $this->hasMany(SubUnit::class);
    }

    public function admins()
    {
        return $this->belongsToMany(Admin::class, 'admin_unit', 'unit_id', 'admin_id');
    }
}
