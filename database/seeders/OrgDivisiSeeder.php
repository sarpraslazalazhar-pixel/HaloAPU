<?php

namespace Database\Seeders;

use App\Models\OrgDivisi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrgDivisiSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('org_divisi')->truncate();

        OrgDivisi::create(['nama_divisi' => 'Divisi Keuangan']);
        OrgDivisi::create(['nama_divisi' => 'Divisi LAZ']);
        OrgDivisi::create(['nama_divisi' => 'Divisi Sekretariat']);
        OrgDivisi::create(['nama_divisi' => 'Divisi Wakaf']);
    }
}
