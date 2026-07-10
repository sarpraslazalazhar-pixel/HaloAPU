<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('divisi_id')->nullable()->constrained('org_divisi')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_unit')->nullOnDelete();
            $table->foreignId('jabatan_id')->nullable()->constrained('org_jabatan')->nullOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->foreignId('sub_unit_id')->constrained('sub_units')->cascadeOnDelete();
            $table->json('form_data');
            // Struktur: { "field_id_1": "value1", "field_id_2": ["val_a", "val_b"], ... }
            $table->string('status', 20)->default('open');
            // Values: open, on_proses, pending, solve, reject
            $table->timestamps();

            $table->index('status');
            $table->index('user_id');
            $table->index(['unit_id', 'sub_unit_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
