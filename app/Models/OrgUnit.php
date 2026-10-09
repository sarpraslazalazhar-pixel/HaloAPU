<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgUnit extends Model
{
    protected $table = 'org_unit';
    protected $fillable = ['nama_unit_organisasi', 'divisi_id'];

    protected static function booted()
    {
        static::saved(function ($model) {
            \Illuminate\Support\Facades\Cache::forget('master_unit_org_select');
            \Illuminate\Support\Facades\Cache::forget("org_units_{$model->divisi_id}");
        });
        static::deleted(function ($model) {
            \Illuminate\Support\Facades\Cache::forget('master_unit_org_select');
            \Illuminate\Support\Facades\Cache::forget("org_units_{$model->divisi_id}");
        });
    }

    public function divisi()
    {
        return $this->belongsTo(OrgDivisi::class, 'divisi_id');
    }
}
