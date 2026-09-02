<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_configs', function (Blueprint $table) {
            $table->id();
            $table->string('jenis_reminder', 50)->unique();
            $table->unsignedInteger('lead_time_value');
            $table->json('channel_aktif');
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_configs');
    }
};
