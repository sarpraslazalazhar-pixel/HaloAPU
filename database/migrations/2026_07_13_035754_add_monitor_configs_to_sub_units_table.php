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
        Schema::table('sub_units', function (Blueprint $table) {
            $table->boolean('is_monitored')->default(false)->after('aktif');
            $table->string('monitor_kategori')->nullable()->after('is_monitored');
            $table->unsignedBigInteger('monitor_asset_field_id')->nullable()->after('monitor_kategori');
            $table->unsignedBigInteger('monitor_start_field_id')->nullable()->after('monitor_asset_field_id');
            $table->unsignedBigInteger('monitor_end_field_id')->nullable()->after('monitor_start_field_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_units', function (Blueprint $table) {
            $table->dropColumn([
                'is_monitored',
                'monitor_kategori',
                'monitor_asset_field_id',
                'monitor_start_field_id',
                'monitor_end_field_id'
            ]);
        });
    }
};
