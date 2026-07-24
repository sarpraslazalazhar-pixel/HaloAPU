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
            $table->string('monitor_date_field_id')->nullable()->after('monitor_asset_field_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_units', function (Blueprint $table) {
            $table->dropColumn('monitor_date_field_id');
        });
    }
};
