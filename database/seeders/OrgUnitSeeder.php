<?php

namespace Database\Seeders;

use App\Models\OrgUnit;
use App\Models\OrgDivisi;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrgUnitSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('org_unit')->truncate();

        $mapping = [
            'Divisi Sekretariat' => ['Humas, GA & IT', 'HRD & Legal', 'Diklat Litbang'],
            'Divisi LAZ'         => ['Fundraising', 'Program', 'KPw'],
            'Divisi Keuangan'    => ['Penyaluran & Treasury', 'Penerimaan', 'Akuntansi'],
            'Divisi Wakaf'       => ['Fundraising', 'Pengelolaan Wakaf'],
        ];

        foreach ($mapping as $divisiNama => $units) {
            $divisi = OrgDivisi::where('nama_divisi', $divisiNama)->first();
            if ($divisi) {
                foreach ($units as $unitNama) {
                    OrgUnit::create([
                        'nama_unit_organisasi' => $unitNama,
                        'divisi_id' => $divisi->id,
                    ]);
                }
            }
        }
    }
}
