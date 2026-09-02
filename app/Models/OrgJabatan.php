<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrgJabatan extends Model
{
    protected $table = 'org_jabatan';
    protected $fillable = ['nama_jabatan', 'urutan'];
}
