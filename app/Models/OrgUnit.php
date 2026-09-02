<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgUnit extends Model
{
    protected $table = 'org_unit';
    protected $fillable = ['nama_unit_organisasi', 'divisi_id'];

    public function divisi()
    {
        return $this->belongsTo(OrgDivisi::class, 'divisi_id');
    }
}
