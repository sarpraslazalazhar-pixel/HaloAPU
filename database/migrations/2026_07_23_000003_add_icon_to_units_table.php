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
        Schema::table('units', function (Blueprint $table) {
            $table->string('icon', 100)->nullable()->after('nama_unit');
        });

        if (Schema::hasColumn('sub_units', 'icon')) {
            Schema::table('sub_units', function (Blueprint $table) {
                $table->dropColumn('icon');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('icon');
        });

        Schema::table('sub_units', function (Blueprint $table) {
            $table->string('icon', 100)->nullable()->after('nama_layanan');
        });
    }
};
