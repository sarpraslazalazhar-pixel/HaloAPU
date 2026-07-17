<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['nama_unit', 'deskripsi', 'aktif'];

    public function subUnits()
    {
        return $this->hasMany(SubUnit::class);
    }

    public function admins()
    {
        return $this->belongsToMany(Admin::class, 'admin_unit', 'unit_id', 'admin_id');
    }
}
