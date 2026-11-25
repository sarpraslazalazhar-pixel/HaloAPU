<?php

namespace Database\Seeders;

use App\Models\SubUnit;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubUnitSeeder extends Seeder
{
    // Mirrors the 19 sub_units rows in apudevid_haloapu.sql.
    // Monitor field ids are wired by FormFieldSeeder, once field ids exist.
    public function run(): void
    {
        DB::table('sub_units')->truncate();

        $rows = [
            // [unit, nama_layanan, deskripsi, is_revision_enabled, is_monitored, monitor_kategori]
            ['GA', 'Peminjaman Alat', 'Pinjam berbagai peralatan pendukung kegiatan Anda dengan cepat, mudah, dan praktis.', false, false, null],
            ['GA', 'Penggunaan Ruangan', 'Pesan dan gunakan ruangan untuk berbagai kegiatan Anda dengan proses yang cepat dan mudah.', false, true, 'Ruang Rapat'],
            ['GA', 'Penggunaan Kendaraan', 'Pesan dan gunakan kendaraan operasional dengan mudah dan aman untuk mendukung mobilitas kegiatan Anda.', false, true, 'Kendaraan'],
            ['GA', 'Pengadaan Jasa & Barang', 'Layanan pengajuan dan pengelolaan pengadaan barang serta jasa dengan prosedur yang akuntabel, terstruktur, dan efisien.', false, false, null],
            ['GA', 'Perbaikan & Perawatan', null, false, false, null],
            ['GA', 'Perpanjang Pajak', null, false, false, null],
            ['GA', 'Support SDM', null, false, false, null],
            ['IT', 'Request Akun Donasi', null, false, false, null],
            ['IT', 'Data Sistem Penerimaan', null, false, false, null],
            ['IT', 'Void Bukti Setor', null, false, false, null],
            ['IT', 'Akun & Reset Sandi', null, false, false, null],
            ['IT', 'Penggunaan Link Zoom', null, false, false, null],
            ['IT', 'Maintenance Hardware & Software', null, false, false, null],
            ['IT', 'Support SDM', null, false, false, null],
            ['Humas', 'Editing & Produksi Video', null, true, false, null],
            ['Humas', 'Pengajuan Desain Grafis', null, true, false, null],
            ['Humas', 'Dokumentasi Event/Kegiatan', null, false, false, null],
            ['IT', 'Short Link', null, false, false, null],
            ['IT', 'Data Rekaman CCTV', 'Data Rekaman CCTV', false, false, null],
        ];

        $unitIds = Unit::pluck('id', 'nama_unit');

        foreach ($rows as [$unitNama, $namaLayanan, $deskripsi, $revisi, $monitored, $kategori]) {
            $unitId = $unitIds[$unitNama]
                ?? throw new \RuntimeException("Unit {$unitNama} was not seeded.");

            SubUnit::create([
                'unit_id' => $unitId,
                'nama_layanan' => $namaLayanan,
                'deskripsi' => $deskripsi,
                'aktif' => true,
                'is_revision_enabled' => $revisi,
                'wajib_kembali' => $namaLayanan === 'Peminjaman Alat',
                'is_monitored' => $monitored,
                'monitor_kategori' => $kategori,
            ]);
        }
    }
}
