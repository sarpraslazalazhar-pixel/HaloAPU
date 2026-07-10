<?php

namespace Database\Seeders;

use App\Models\FormField;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FormFieldSeeder extends Seeder
{
    // sub_unit IDs are deterministic based on SubUnitSeeder order:
    // 1=Peminjaman Alat, 2=Penggunaan Ruangan, 3=Penggunaan Kendaraan,
    // 4=Pengadaan Jasa & Barang, 5=Perbaikan & Perawatan, 6=Perpanjang Pajak,
    // 7=Support SDM(GA), 8=Request Akun Donasi, 9=Data Sistem Penerimaan,
    // 10=Void Bukti Setor, 11=Akun & Reset Sandi, 12=Penggunaan Link Zoom,
    // 13=Maintenance HW/SW, 14=Support SDM(IT), 15=Editing & Produksi Video,
    // 16=Pengajuan Desain Grafis, 17=Dokumentasi Event/Kegiatan

    public function run(): void
    {
        DB::table('form_fields')->truncate();

        $fields = [
            // Peminjaman Alat (sub_unit_id: 1)
            [1, 'Aturan Peminjaman Alat Bila terjadi kerusakan yang bertanggung jawab adalah peminjam. Alat yang dipinjam dikembalikan dalam keadaan bersih dan rapih.', 'info_peraturan', false, [], null, null, 1],
            [1, 'Pilih Alat',        'multi_pilih',  true, ['Peralatan Live Streaming','Infokus','Infokus Portabel','TV','Kabel Roll','Kabel HDMI','Screen Infokus','Printer','Sound Portabel','HT Baofeng','Termos Listrik','Yang lain:'], null, null, 3],
            [1, 'Tujuan Penggunaan',  'teks_pendek',  true, [], null, null, 4],
            [1, 'Lokasi Penggunaan',  'teks_pendek',  true, [], null, null, 5],
            [1, 'Tanggal Mulai',      'tanggal',      true, [], null, null, 6],
            [1, 'Tanggal Selesai',    'tanggal',      true, [], null, null, 7],
            [1, 'Keterangan Tambahan','teks_panjang', false, [], null, null, 8],

            // Penggunaan Ruangan (sub_unit_id: 2)
            [2, 'Pilih Ruangan',       'dropdown',    true, ['Meeting Room LAZ Cirendeu','Meeting Room RGI Depok','Ruang SCC RGI Depok','Aula Nurhayati RGI Depok','Meeting Room LAZ Pusat','Dasamas Center Bogor Utara'], null, null, 1],
            [2, 'Tanggal Pakai',       'tanggal',     true, [], null, null, 2],
            [2, 'Jam Mulai',           'waktu',       true, [], null, null, 3],
            [2, 'Jam Selesai',         'waktu',       true, [], null, null, 4],
            [2, 'Jumlah Peserta',      'angka',       true, [], null, null, 5],
            [2, 'Keterangan Tambahan', 'teks_panjang', false, [], null, null, 6],

            // Penggunaan Kendaraan (sub_unit_id: 3)
            [3, 'Pilih Kendaraan',               'dropdown',    true, ['Panther','APV','Grand Max','ELF','Innova Silver','Innova Putih'], null, null, 1],
            [3, 'Lokasi Tujuan',                 'teks_pendek', true, [], null, null, 2],
            [3, 'Tanggal Peminjaman',            'tanggal',     true, [], null, null, 3],
            [3, 'Waktu Pengajuan',               'waktu',       true, [], null, null, 4],
            [3, 'Waktu Pengembalian',            'waktu',       true, [], null, null, 5],
            [3, 'Jumlah Penumpang',              'angka',       false, [], null, null, 6],
            [3, 'Keterangan Tambahan',           'teks_panjang',false, [], null, null, 7],
            [3, 'Keperluan Penggunaan Kendaraan', 'dropdown',   true, ['Tugas Dinas','Tugas Non Dinas'], null, null, 8],
            // child of "Keperluan Penggunaan Kendaraan" — parent label resolved by name
            [3, 'Upload SIM', 'upload_file', true, [], 'Keperluan Penggunaan Kendaraan', 'Tugas Non Dinas', 9],

            // Pengadaan Jasa & Barang (sub_unit_id: 4)
            [4, 'Nama Barang/Jasa',    'teks_pendek',  true, [], null, null, 1],
            [4, 'Spesifikasi Detai',   'teks_panjang', true, [], null, null, 2],
            [4, 'Keterangan Tambahan', 'teks_panjang', false, [], null, null, 3],

            // Perbaikan & Perawatan (sub_unit_id: 5)
            [5, 'Lokasi/Area',         'teks_pendek',  true, [], null, null, 1],
            [5, 'Kategori',            'multi_pilih',  true, ['Kelistrikan','Sanitasi/Air','AC/Pendingin','Mebel/Furniture','IT/Jaringan','Layanan Lainnya'], null, null, 2],
            [5, 'Keterangan Tambahan', 'teks_panjang', false, [], null, null, 3],

            // Perpanjang Pajak (sub_unit_id: 6)
            [6, 'Jenis Pajak',          'multi_pilih', true, ['Perpanjangan Pajak Tahunan','Perpanjangan STNK 5 Tahunan','Ganti Plat Nomor','Balik Nama,Mutasi Kendaraan','Lainnya'], null, null, 1],
            [6, 'Tanggal Jatuh Tempo',  'tanggal',     true, [], null, null, 2],
            [6, 'Keterangan Tambahan',  'teks_panjang',false, [], null, null, 3],

            // Support SDM GA (sub_unit_id: 7)
            [7, 'Tanggal Selesai',      'tanggal',     true, [], null, null, 1],
            [7, 'Tanggal Selesai',      'tanggal',     true, [], null, null, 2],
            [7, 'Keterangan Tambahan',  'teks_panjang',false, [], null, null, 3],

            // Request Akun Donasi (sub_unit_id: 8)
            [8, 'Nama Akun Donasi',    'teks_pendek',  true, [], null, null, 1],
            [8, 'Jenis Campaign',      'dropdown',     true, ['Admin','CrowdFunding'], null, null, 2],
            [8, 'Target Donasi',       'nominal_rp',   true, [], null, null, 3],
            [8, 'Tanggal Donasi',      'tanggal',      true, [], null, null, 4],
            [8, 'Keterangan Tambahan', 'teks_panjang', false, [], null, null, 5],
            [8, 'Data Copywriting',    'upload_file',  true, [], null, null, 6],

            // Data Sistem Penerimaan (sub_unit_id: 9)
            [9, 'Jenis Data',             'dropdown',    true, ['Rekap Donatur','Data Donatur','Data Transaksi','Lainnya'], null, null, 1],
            [9, 'Jika Lainnya',           'teks_pendek', true, [], 'Jenis Data', 'Lainnya', 2],
            [9, 'Data dari Tanggal',      'tanggal',     true, [], null, null, 3],
            [9, 'Data sampai Tanggal',    'tanggal',     true, [], null, null, 4],
            [9, 'Keterangan Tambahan',    'teks_panjang',false, [], null, null, 5],

            // Void Bukti Setor (sub_unit_id: 10)
            [10, 'NO KWITANSI',        'teks_panjang', true, [], null, null, 1],
            [10, 'Nama Donatur',       'teks_pendek',  true, [], null, null, 2],
            [10, 'Tanggal Transaksi',  'tanggal',      true, [], null, null, 3],
            [10, 'Nominal',            'nominal_rp',   true, [], null, null, 4],
            [10, 'Keterangan Tambahan','teks_panjang', false, [], null, null, 5],

            // Akun & Reset Sandi (sub_unit_id: 11)
            [11, 'Sistem/Aplikasi',     'multi_pilih', true, ['Admin (Penerimaan & Penyaluran)','POS','Surveyor','HRIS','Email','Cloud','Sistem Asset'], null, null, 1],
            [11, 'Username/Email',      'teks_pendek', true, [], null, null, 2],
            [11, 'Jenis Ajuan',         'dropdown',    true, ['Buat Baru','Reset Sandi','Hapus'], null, null, 3],
            [11, 'Keterangan Tambahan', 'teks_panjang',false, [], null, null, 4],

            // Penggunaan Link Zoom (sub_unit_id: 12)
            [12, 'Room Zoom dibuka 30 menit sebelum acara dimulai Harap Hubungi tim IT jika 30 menit sebelum acara room zoom belum di buka.', 'info_peraturan', false, [], null, null, 1],
            [12, 'Tipe Zoom',            'dropdown',    true, ['Zoom Meeting Standar','Zoom Hybird (Offline dan Online)'], null, null, 2],
            [12, 'Tanggal Penggunaan',   'tanggal',     true, [], null, null, 3],
            [12, 'Jam Mulai',            'waktu',       true, [], null, null, 4],
            [12, 'Jam Selesai',          'waktu',       true, [], null, null, 5],
            [12, 'Topik Meeting',        'teks_pendek', true, [], null, null, 6],
            [12, 'Keterangan Tambahan',  'teks_pendek', false, [], null, null, 7],

            // Maintenance Hardware & Software (sub_unit_id: 13)
            [13, 'Jenis Bantuan', 'dropdown', true, ['Hardware','Software'], null, null, 1],
            [13, 'Hardware',      'dropdown', true, ['Laptop','Desktop PC','Printer','Scanner','Monitor','Smartphone','Tablet','Server','CCTV','Access Point / WiFi','Proyektor','Lainnya'], 'Jenis Bantuan', 'Hardware', 2],
            [13, 'Software',      'dropdown', true, ['Instalasi Software','Update Software','Aktivasi Lisensi','Microsoft Office','Windows Error','Antivirus','Lainnya'], 'Jenis Bantuan', 'Software', 3],

            // Support SDM IT (sub_unit_id: 14)
            [14, 'Jenis Support',        'dropdown',    true, ['Surat Keterangan','Slip Gaji','Perubahan Data'], null, null, 1],
            [14, 'Nama Karyawan',        'teks_pendek', true, [], null, null, 2],
            [14, 'Keterangan Tambahan',  'teks_panjang',false, [], null, null, 3],

            // Editing & Produksi Video (sub_unit_id: 15)
            [15, 'Judul/Tema Video',     'teks_pendek',  true, [], null, null, 1],
            [15, 'Tanggal Pengajuan',    'tanggal',      true, [], null, null, 2],
            [15, 'Durasi Estimasi',      'dropdown',     true, ['1 Menit','3 Menit','5 Menit','>5 Menit'], null, null, 3],
            [15, 'Ukuran Video',         'dropdown',     true, ['Landscape','Portrait'], null, null, 4],
            [15, 'Deadline Tayang',      'tanggal',      true, [], null, null, 5],
            [15, 'Link Referensi',       'teks_pendek',  false, [], null, null, 6],
            [15, 'Jenis Video',          'dropdown',     true, ['Event,Profile','Reels','Motion Graphic','Testimoni','Lainnya'], null, null, 7],
            [15, 'Jenis Video Lainnya',  'teks_panjang', true, [], 'Jenis Video', 'Lainnya', 8],
            [15, 'Brief Video',          'teks_panjang', true, [], null, null, 9],
            [15, 'Brief Dokumen / Referensi', 'upload_file', false, [], null, null, 10],
            [15, 'Keterangan Tambahan',  'teks_panjang', false, [], null, null, 11],

            // Pengajuan Desain Grafis (sub_unit_id: 16)
            [16, 'Jenis Desain',          'dropdown',    true, ['Banner','Feed IG','Poster','Brosur','Custom'], null, null, 1],
            [16, 'Tanggal Pengajuan',     'tanggal',     true, [], null, null, 2],
            [16, 'Ukuran (px/cm)',        'teks_pendek', true, [], null, null, 3],
            [16, 'Jenis Produksi Desain', 'dropdown',    true, ['Feed Instagram / Meme','Spanduk','Backdrop','Roll/X Banner','Mockup Penyerahan','Standing Frame','Umbul-Umbul','Stiker','Sertifikat','Proposal','Laporan','Akrilik','Thumbnail Campaign','Meme Al-Kisah','Banner Website','Banner Umum','Lainnya'], null, null, 4],
            [16, 'Jika lainnya',          'teks_pendek', true, [], 'Jenis Produksi Desain', 'Lainnya', 5],
            [16, 'Tujuan Penggunaan',     'teks_panjang',true, [], null, null, 6],
            [16, 'Brief Materi',          'teks_panjang',true, [], null, null, 7],
            [16, 'Brief Dokumen',         'upload_file', true, [], null, null, 8],
            [16, 'Jumlah Produksi',       'angka',       true, [], null, null, 9],
            [16, 'Distribusi',            'dropdown',    true, ['Instagram','Facebook','Website','WhatsApp','Email','Cetak','Internal','Eksternal','Lainnya'], null, null, 10],
            [16, 'Jika Lainnya',          'teks_pendek', true, [], 'Distribusi', 'Lainnya', 11],
            [16, 'Deadline',              'tanggal',     true, [], null, null, 12],
            [16, 'Keterangan Tambahan',   'teks_panjang',false, [], null, null, 13],

            // Dokumentasi Event/Kegiatan (sub_unit_id: 17)
            [17, 'Jenis Support',        'dropdown',    true, ['Surat Keterangan','Slip Gaji','Perubahan Data'], null, null, 1],
            [17, 'Nama Karyawan',        'teks_pendek', true, [], null, null, 2],
            [17, 'Keterangan Tambahan',  'teks_panjang',false, [], null, null, 3],
        ];

        // Map: sub_unit_id + label => new ID (for parent-child)
        $idMap = [];

        foreach ($fields as $f) {
            [$subUnitId, $label, $tipe, $wajib, $opsi, $parentLabel, $trigger, $urutan] = $f;

            $parentId = null;
            if ($parentLabel) {
                $parentId = $idMap["{$subUnitId}::{$parentLabel}"] ?? null;
            }

            $field = FormField::create([
                'sub_unit_id' => $subUnitId,
                'label' => $label,
                'tipe_field' => $tipe,
                'wajib' => $wajib,
                'opsi' => $opsi,
                'parent_field_id' => $parentId,
                'trigger_value' => $trigger,
                'urutan' => $urutan,
            ]);

            $idMap["{$subUnitId}::{$label}"] = $field->id;
        }
    }
}
