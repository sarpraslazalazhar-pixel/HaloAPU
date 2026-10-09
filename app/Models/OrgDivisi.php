<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgDivisi extends Model
{
    protected $table = 'org_divisi';
    protected $fillable = ['nama_divisi'];

    protected static function booted()
    {
        static::saved(fn () => \Illuminate\Support\Facades\Cache::forget('master_divisi_select'));
        static::deleted(fn () => \Illuminate\Support\Facades\Cache::forget('master_divisi_select'));
    }

    public function orgUnits()
    {
        return $this->hasMany(OrgUnit::class, 'divisi_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'divisi_id');
    }
}
