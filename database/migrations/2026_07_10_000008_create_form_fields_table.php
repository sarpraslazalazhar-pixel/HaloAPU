<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_unit_id')->constrained('sub_units')->cascadeOnDelete();
            $table->string('label', 255);
            $table->string('tipe_field', 30);
            // Enum values: teks_pendek, teks_panjang, angka, tanggal, waktu,
            // dropdown, radio, checkbox, multi_pilih, upload_gambar,
            // upload_file, nominal_rp, info_peraturan
            $table->boolean('wajib')->default(false);
            $table->json('opsi')->nullable();
            // Contoh: ["Opsi A", "Opsi B", "Opsi C"] untuk dropdown/radio/multi_pilih
            $table->foreignId('parent_field_id')->nullable()->constrained('form_fields')->nullOnDelete();
            $table->string('trigger_value', 255)->nullable();
            // Jika parent_field_id != null, field ini hanya muncul ketika parent field == trigger_value
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();

            $table->index(['sub_unit_id', 'urutan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_fields');
    }
};
