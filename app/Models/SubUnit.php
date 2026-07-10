<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubUnit extends Model
{
    protected $fillable = ['unit_id', 'nama_layanan', 'deskripsi', 'aktif'];

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
