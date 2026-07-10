<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_unit', function (Blueprint $table) {
            $table->id();
            $table->string('nama_unit_organisasi', 150);
            $table->foreignId('divisi_id')->constrained('org_divisi')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_unit');
    }
};
