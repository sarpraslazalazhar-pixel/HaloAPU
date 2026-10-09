<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgJabatan extends Model
{
    protected $table = 'org_jabatan';
    protected $fillable = ['nama_jabatan', 'urutan'];

    protected static function booted()
    {
        static::saved(fn () => \Illuminate\Support\Facades\Cache::forget('master_jabatan_select'));
        static::deleted(fn () => \Illuminate\Support\Facades\Cache::forget('master_jabatan_select'));
    }
}
