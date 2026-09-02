<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('units')->truncate();

        $units = [
            [
                'nama_unit' => 'GA (General Affair)',
                'deskripsi' => 'Menangani urusan umum seperti ruangan, kendaraan, dan aset perusahaan',
                'aktif' => true,
            ],
            [
                'nama_unit' => 'IT (Information Technology)',
                'deskripsi' => 'Menangani urusan teknologi informasi, jaringan, dan perangkat',
                'aktif' => true,
            ],
            [
                'nama_unit' => 'Humas',
                'deskripsi' => 'Menangani urusan hubungan masyarakat dan komunikasi',
                'aktif' => true,
            ],
        ];

        foreach ($units as $unit) {
            Unit::create($unit);
        }
    }
}
