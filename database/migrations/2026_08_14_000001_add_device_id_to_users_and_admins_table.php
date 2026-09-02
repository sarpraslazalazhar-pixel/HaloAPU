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
        Schema::table('users', function (Blueprint $table) {
            $table->string('device_id', 150)->nullable()->after('avatar_path')->index();
            $table->string('device_name', 150)->nullable()->after('device_id');
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->string('device_id', 150)->nullable()->after('avatar_path')->index();
            $table->string('device_name', 150)->nullable()->after('device_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'device_name']);
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['device_id', 'device_name']);
        });
    }
};
