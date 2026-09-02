<?php

namespace Database\Seeders;

use App\Models\OrgJabatan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrgJabatanSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('org_jabatan')->truncate();

        $jabatan = [
            ['nama_jabatan' => 'Kepala Divisi', 'urutan' => 1],
            ['nama_jabatan' => 'Manager',       'urutan' => 2],
            ['nama_jabatan' => 'Koordinator',   'urutan' => 3],
            ['nama_jabatan' => 'Staff',         'urutan' => 4],
        ];

        foreach ($jabatan as $j) {
            OrgJabatan::create($j);
        }
    }
}
