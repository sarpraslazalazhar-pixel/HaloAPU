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
            $table->boolean('is_revision_enabled')->default(false)->after('aktif');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_units', function (Blueprint $table) {
            $table->dropColumn('is_revision_enabled');
        });
    }
};
