<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_unit_id')
                  ->nullable()
                  ->constrained('sub_units')
                  ->cascadeOnDelete();
            $table->unsignedTinyInteger('tier');
            $table->enum('jenis', ['respon', 'penyelesaian']);
            $table->unsignedInteger('threshold_minutes');
            $table->timestamps();

            $table->unique(['sub_unit_id', 'tier', 'jenis'], 'unique_sla');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_configs');
    }
};
