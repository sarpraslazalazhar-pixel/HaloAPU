<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_devices', function (Blueprint $table) {
            $table->id();
            $table->string('authenticatable_type'); // App\Models\User or App\Models\Admin
            $table->unsignedBigInteger('authenticatable_id');
            $table->string('device_id', 200)->index();
            $table->string('device_name', 200)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->index(['authenticatable_type', 'authenticatable_id'], 'acc_device_morph_idx');
            $table->unique(['authenticatable_type', 'authenticatable_id', 'device_id'], 'acc_device_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_devices');
    }
};
