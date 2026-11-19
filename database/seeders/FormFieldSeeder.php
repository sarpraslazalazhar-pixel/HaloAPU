<?php

namespace Database\Seeders;

use App\Models\FormField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FormFieldSeeder extends Seeder
{
    // Mirrors the 109 form_fields rows in apudevid_haloapu.sql.
    public function run(): void
    {
        DB::table('form_fields')->truncate();

        $subUnitIds = [];
        foreach (DB::table('sub_units')
            ->join('units', 'units.id', '=', 'sub_units.unit_id')
            ->get(['units.nama_unit', 'sub_units.nama_layanan', 'sub_units.id']) as $subUnit) {
            $subUnitIds[$subUnit->nama_unit][$subUnit->nama_layanan] = $subUnit->id;
        }

        $forms = [
            ['GA', 'Peminjaman Alat', [
                ['Aturan Peminjaman Alat Bila terjadi kerusakan yang bertanggung jawab adalah peminjam. Alat yang dipinjam dikembalikan dalam keadaan bersih dan rapih.', 'info_peraturan', false, [], 1],
                ['Pilih Alat', 'multi_pilih', true, ['Peralatan Live Streaming', 'Infokus', 'Infokus Portabel', 'TV', 'Kabel Roll', 'Kabel HDMI', 'Screen Infokus', 'Printer', 'Sound Portabel', 'HT Baofeng', 'Termos Listrik', 'Yang lain:'], 3],
                ['Tujuan Penggunaan', 'teks_pendek', true, [], 4],
                ['Lokasi Penggunaan', 'teks_pendek', true, [], 5],
                ['Tanggal Mulai', 'tanggal', true, [], 6],
                ['Tanggal Selesai', 'tanggal', true, [], 7],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 8],
            ]],
            ['GA', 'Penggunaan Ruangan', [
                ['Pilih Ruangan', 'dropdown', true, ['Ruang Rapat Cirendeu', 'Ruang Rapat RGI', 'Ruang SCC RGI', 'Aula Nurhayati RGI', 'Ruang Rapat Pusat', 'Dasamas Center'], 1],
                ['Tanggal Pakai', 'tanggal', true, [], 2],
                ['Jam Mulai', 'waktu', true, [], 3],
                ['Jam Selesai', 'waktu', true, [], 4],
                ['Jumlah Peserta', 'angka', true, [], 5],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 6],
            ]],
            ['GA', 'Penggunaan Kendaraan', [
                ['Pilih Kendaraan', 'dropdown', true, ['Panther', 'APV', 'Grand Max', 'ELF', 'Innova Silver', 'Innova Putih'], 1],
                ['Lokasi Tujuan', 'teks_pendek', true, [], 2],
                ['Tanggal Peminjaman', 'tanggal', true, [], 3],
                ['Waktu Pengajuan', 'waktu', true, [], 4],
                ['Waktu Pengembalian', 'waktu', true, [], 5],
                ['Jumlah Penumpang', 'angka', false, [], 7],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 10],
                ['Keperluan Penggunaan Kendaraan', 'dropdown', true, ['Tugas Dinas', 'Tugas Non Dinas'], 9],
                ['Upload SIM', 'upload_file', true, [], 9, 'Keperluan Penggunaan Kendaraan', 'Tugas Non Dinas'],
            ]],
            ['GA', 'Pengadaan Jasa & Barang', [
                ['Nama Barang/Jasa', 'teks_pendek', true, [], 1],
                ['Spesifikasi Detai', 'teks_panjang', true, [], 2],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 3],
            ]],
            ['GA', 'Perbaikan & Perawatan', [
                ['Lokasi/Area', 'teks_pendek', true, [], 1],
                ['Kategori', 'multi_pilih', true, ['Kelistrikan', 'Sanitasi/Air', 'AC/Pendingin', 'Mebel/Furniture', 'Kendaraan', 'Layanan Lainnya'], 2],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 3],
            ]],
            ['GA', 'Perpanjang Pajak', [
                ['Jenis Pajak', 'multi_pilih', true, ['Perpanjangan Pajak Tahunan', 'Perpanjangan STNK 5 Tahunan', 'Ganti Plat Nomor', 'Balik Nama,Mutasi Kendaraan', 'Lainnya'], 1],
                ['Tanggal Jatuh Tempo', 'tanggal', true, [], 2],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 3],
            ]],
            ['GA', 'Support SDM', [
                ['Tanggal Mulai', 'tanggal', true, [], 2],
                ['Tanggal Selesai', 'tanggal', true, [], 3],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 4],
            ]],
            ['IT', 'Request Akun Donasi', [
                ['Nama Akun Donasi', 'teks_pendek', true, [], 1],
                ['Jenis Campaign', 'dropdown', true, ['Admin', 'CrowdFunding'], 2],
                ['Target Donasi', 'nominal_rp', true, [], 3],
                ['Tanggal Donasi', 'tanggal', true, [], 4],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 5],
                ['Data Copywriting', 'upload_file', true, [], 6],
            ]],
            ['IT', 'Data Sistem Penerimaan', [
                ['Jenis Data', 'dropdown', true, ['Rekap Donatur', 'Data Donatur', 'Data Transaksi', 'Lainnya'], 1],
                ['Jika Lainnya', 'teks_pendek', true, [], 2, 'Jenis Data', 'Lainnya'],
                ['Data dari Tanggal', 'tanggal', true, [], 3],
                ['Data sampai Tanggal', 'tanggal', true, [], 4],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 5],
            ]],
            ['IT', 'Void Bukti Setor', [
                ['NO KWITANSI', 'teks_panjang', true, [], 1],
                ['Nama Donatur', 'teks_pendek', true, [], 2],
                ['Tanggal Transaksi', 'tanggal', true, [], 3],
                ['Nominal', 'nominal_rp', true, [], 4],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 5],
            ]],
            ['IT', 'Akun & Reset Sandi', [
                ['Sistem/Aplikasi', 'multi_pilih', true, ['Admin (Penerimaan & Penyaluran)', 'POS', 'Surveyor', 'HRIS', 'Email', 'Cloud', 'Sistem Asset'], 1],
                ['Username/Email', 'teks_pendek', true, [], 2],
                ['Jenis Ajuan', 'dropdown', true, ['Buat Baru', 'Reset Sandi', 'Hapus'], 3],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 4],
            ]],
            ['IT', 'Penggunaan Link Zoom', [
                ['Room Zoom dibuka 30 menit sebelum acara dimulai Harap Hubungi tim IT jika 30 menit sebelum acara room zoom belum di buka.', 'info_peraturan', false, [], 1],
                ['Tipe Zoom', 'dropdown', true, ['Zoom Meeting Standar', 'Zoom Hybird (Offline dan Online)'], 2],
                ['Tanggal Penggunaan', 'tanggal', true, [], 3],
                ['Jam Mulai', 'waktu', true, [], 4],
                ['Jam Selesai', 'waktu', true, [], 5],
                ['Topik Meeting', 'teks_pendek', true, [], 6],
                ['Keterangan Tambahan', 'teks_pendek', false, [], 7],
            ]],
            ['IT', 'Maintenance Hardware & Software', [
                ['Jenis Bantuan', 'dropdown', true, ['Hardware', 'Software'], 1],
                ['Hardware', 'dropdown', true, ['Laptop', 'Desktop PC', 'Printer', 'Scanner', 'Monitor', 'Smartphone', 'Tablet', 'Server', 'CCTV', 'Access Point / WiFi', 'Proyektor', 'Lainnya'], 2, 'Jenis Bantuan', 'Hardware'],
                ['Software', 'dropdown', true, ['Instalasi Software', 'Update Software', 'Aktivasi Lisensi', 'Microsoft Office', 'Windows Error', 'Antivirus', 'Lainnya'], 3, 'Jenis Bantuan', 'Software'],
            ]],
            ['IT', 'Support SDM', [
                ['Tanggal Mulai', 'tanggal', true, [], 2],
                ['Tanggal Selesai', 'tanggal', true, [], 3],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 4],
            ]],
            ['Humas', 'Editing & Produksi Video', [
                ['Judul/Tema Video', 'teks_pendek', true, [], 1],
                ['Tanggal Pengajuan', 'tanggal', true, [], 2],
                ['Durasi Estimasi', 'dropdown', true, ['1 Menit', '3 Menit', '5 Menit', '>5 Menit'], 4],
                ['Ukuran Video', 'dropdown', true, ['Landscape', 'Portrait'], 5],
                ['Link Referensi', 'teks_pendek', false, [], 6],
                ['Jenis Video', 'dropdown', true, ['Event,Profile', 'Reels', 'Motion Graphic', 'Testimoni', 'Lainnya'], 7],
                ['Jenis Video Lainnya', 'teks_panjang', true, [], 8, 'Jenis Video', 'Lainnya'],
                ['Brief Video', 'teks_panjang', true, [], 8],
                ['Brief Dokumen / Referensi', 'upload_file', false, [], 9],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 10],
            ]],
            ['Humas', 'Pengajuan Desain Grafis', [
                ['Jenis Desain', 'dropdown', true, ['Banner', 'Feed IG', 'Poster', 'Brosur', 'Custom'], 1],
                ['Tanggal Pengajuan', 'tanggal', true, [], 2],
                ['Ukuran (px/cm)', 'teks_pendek', false, [], 4],
                ['Jenis Produksi Desain', 'dropdown', true, ['Feed Instagram / Meme', 'Spanduk', 'Backdrop', 'Roll/X Banner', 'Mockup Penyerahan', 'Standing Frame', 'Umbul-Umbul', 'Stiker', 'Sertifikat', 'Proposal', 'Laporan', 'Akrilik', 'Thumbnail Campaign', 'Meme Al-Kisah', 'Banner Website', 'Banner Umum', 'Lainnya'], 5],
                ['Jika lainnya', 'teks_pendek', true, [], 5, 'Jenis Produksi Desain', 'Lainnya'],
                ['Tujuan Penggunaan', 'teks_panjang', true, [], 6],
                ['Brief Materi', 'teks_panjang', true, [], 7],
                ['Brief Dokumen', 'upload_file', false, [], 8],
                ['Jumlah Produksi', 'angka', true, [], 9],
                ['Distribusi', 'dropdown', true, ['Instagram', 'Facebook', 'Website', 'WhatsApp', 'Email', 'Cetak', 'Internal', 'Eksternal', 'Lainnya'], 10],
                ['Jika Lainnya', 'teks_pendek', true, [], 11, 'Distribusi', 'Lainnya'],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 11],
            ]],
            ['Humas', 'Dokumentasi Event/Kegiatan', [
                ['Tanggal Mulai', 'tanggal', true, [], 2],
                ['Tanggal Selesai', 'tanggal', true, [], 3],
                ['Keterangan Tambahan', 'teks_panjang', false, [], 4],
            ]],
            ['IT', 'Maintenance Hardware & Software', [
                ['Keterangan Tambahan', 'teks_panjang', false, [], 4],
            ]],
            ['GA', 'Perbaikan & Perawatan', [
                ['Gambar', 'upload_gambar', false, [], 4],
            ]],
            ['GA', 'Penggunaan Kendaraan', [
                ['Tanggal Selesai (Jika berhari-hari)', 'tanggal', false, [], 6],
            ]],
            ['IT', 'Short Link', [
                ['Tujuan Penggunaan', 'teks_pendek', true, [], 1],
                ['URL untuk di short', 'teks_panjang', true, [], 2],
                ['Keterangan tambahan', 'teks_panjang', false, [], 4],
                ['Tanggal Pemakaian', 'tanggal', false, [], 3],
            ]],
            ['GA', 'Peminjaman Alat', [
                ['nama alat/barang', 'teks_pendek', true, [], 9, 'Pilih Alat', 'Yang lain:'],
            ]],
            ['IT', 'Support SDM', [
                ['Nama Kegiatan', 'teks_pendek', true, [], 1],
            ]],
            ['GA', 'Support SDM', [
                ['Nama Kegiatan', 'teks_pendek', true, [], 1],
            ]],
            ['Humas', 'Dokumentasi Event/Kegiatan', [
                ['Nama Kegiatan', 'teks_pendek', true, [], 1],
            ]],
            ['Humas', 'Pengajuan Desain Grafis', [
                ['Deadline', 'tanggal', true, [], 3],
            ]],
            ['Humas', 'Editing & Produksi Video', [
                ['Deadline', 'tanggal', true, [], 3],
            ]],
            ['IT', 'Data Rekaman CCTV', [
                ['Lokasi', 'dropdown', true, ['Al Azhar Pusat', 'Cirende', 'Al Azhar Bintaro'], 1],
                ['Tanggal', 'tanggal', true, [], 2],
                ['Jam Mulai', 'waktu', true, [], 3],
                ['Jam Akhir', 'waktu', true, [], 4],
            ]],
        ];

        $fieldIds = [];
        foreach ($forms as [$unit, $service, $rows]) {
            $subUnitId = $subUnitIds[$unit][$service]
                ?? throw new \RuntimeException("Sub-unit {$unit} / {$service} was not seeded.");

            foreach ($rows as $row) {
                [$label, $type, $required, $options, $order] = $row;
                $parentLabel = $row[5] ?? null;
                $parentId = $parentLabel === null ? null : ($fieldIds[$subUnitId][$parentLabel]
                    ?? throw new \RuntimeException("Cannot resolve parent '{$parentLabel}' for {$unit} / {$service} / {$label}."));

                $field = FormField::create([
                    'sub_unit_id' => $subUnitId,
                    'label' => $label,
                    'tipe_field' => $type,
                    'wajib' => $required,
                    'opsi' => $options,
                    'urutan' => $order,
                    'parent_field_id' => $parentId,
                    'trigger_value' => $row[6] ?? null,
                ]);

                $fieldIds[$subUnitId][$label] = $field->id;
            }
        }

        // Monitor references can only be set after the fields have their new IDs.
        // Labels come from the dump's monitor ids: Ruangan 8/9/10/11, Kendaraan 14/16/97/17/18.
        $monitors = [
            ['GA', 'Penggunaan Ruangan', [
                'monitor_asset_field_id' => 'Pilih Ruangan',
                'monitor_date_field_id' => 'Tanggal Pakai',
                'monitor_end_date_field_id' => null,
                'monitor_start_field_id' => 'Jam Mulai',
                'monitor_end_field_id' => 'Jam Selesai',
            ]],
            ['GA', 'Penggunaan Kendaraan', [
                'monitor_asset_field_id' => 'Pilih Kendaraan',
                'monitor_date_field_id' => 'Tanggal Peminjaman',
                'monitor_end_date_field_id' => 'Tanggal Selesai (Jika berhari-hari)',
                'monitor_start_field_id' => 'Waktu Pengajuan',
                'monitor_end_field_id' => 'Waktu Pengembalian',
            ]],
        ];

        foreach ($monitors as [$unit, $service, $labels]) {
            $subUnitId = $subUnitIds[$unit][$service];
            $references = [];
            foreach ($labels as $column => $label) {
                $references[$column] = $label === null ? null : ($fieldIds[$subUnitId][$label]
                    ?? throw new \RuntimeException("Cannot resolve monitor field '{$label}' for {$unit} / {$service}."));
            }
            DB::table('sub_units')->where('id', $subUnitId)->update($references);
        }
    }
}
