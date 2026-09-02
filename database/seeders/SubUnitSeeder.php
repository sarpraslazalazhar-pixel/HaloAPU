<?php

namespace Database\Seeders;

use App\Models\SubUnit;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubUnitSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('sub_units')->truncate();

        $mapping = [
            'GA (General Affair)' => [
                ['nama_layanan' => 'Peminjaman Alat',         'deskripsi' => 'Pinjam berbagai peralatan pendukung kegiatan Anda dengan cepat, mudah, dan praktis.'],
                ['nama_layanan' => 'Penggunaan Ruangan',      'deskripsi' => 'Pesan dan gunakan ruangan untuk berbagai kegiatan Anda dengan proses yang cepat dan mudah.'],
                ['nama_layanan' => 'Penggunaan Kendaraan',    'deskripsi' => 'Pesan dan gunakan kendaraan operasional dengan mudah dan aman untuk mendukung mobilitas kegiatan Anda.'],
                ['nama_layanan' => 'Pengadaan Jasa & Barang', 'deskripsi' => 'Layanan pengajuan dan pengelolaan pengadaan barang serta jasa dengan prosedur yang akuntabel, terstruktur, dan efisien.'],
                ['nama_layanan' => 'Perbaikan & Perawatan',   'deskripsi' => null],
                ['nama_layanan' => 'Perpanjang Pajak',        'deskripsi' => null],
                ['nama_layanan' => 'Support SDM',             'deskripsi' => null],
            ],
            'IT (Information Technology)' => [
                ['nama_layanan' => 'Request Akun Donasi',            'deskripsi' => null],
                ['nama_layanan' => 'Data Sistem Penerimaan',         'deskripsi' => null],
                ['nama_layanan' => 'Void Bukti Setor',               'deskripsi' => null],
                ['nama_layanan' => 'Akun & Reset Sandi',             'deskripsi' => null],
                ['nama_layanan' => 'Penggunaan Link Zoom',           'deskripsi' => null],
                ['nama_layanan' => 'Maintenance Hardware & Software', 'deskripsi' => null],
                ['nama_layanan' => 'Support SDM',                    'deskripsi' => null],
            ],
            'Humas' => [
                ['nama_layanan' => 'Editing & Produksi Video',   'deskripsi' => null],
                ['nama_layanan' => 'Pengajuan Desain Grafis',    'deskripsi' => null],
                ['nama_layanan' => 'Dokumentasi Event/Kegiatan', 'deskripsi' => null],
            ],
        ];

        foreach ($mapping as $unitNama => $subUnits) {
            $unit = Unit::where('nama_unit', $unitNama)->first();
            if ($unit) {
                foreach ($subUnits as $subUnit) {
                    SubUnit::create(array_merge($subUnit, [
                        'unit_id' => $unit->id,
                        'aktif' => true,
                    ]));
                }
            }
        }
    }
}
