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
        Schema::table('sla_configs', function (Blueprint $table) {
            // Drop foreign key first because MySQL uses the unique index for it
            $table->dropForeign(['sub_unit_id']);
            $table->dropUnique('unique_sla');
            $table->dropColumn('tier');
            
            $table->string('priority', 20)->after('sub_unit_id');
            $table->unique(['sub_unit_id', 'priority', 'jenis'], 'unique_sla_priority');
            
            // Re-add the foreign key constraint
            $table->foreign('sub_unit_id')->references('id')->on('sub_units')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sla_configs', function (Blueprint $table) {
            $table->dropForeign(['sub_unit_id']);
            $table->dropUnique('unique_sla_priority');
            $table->dropColumn('priority');
            
            $table->unsignedTinyInteger('tier')->after('sub_unit_id');
            $table->unique(['sub_unit_id', 'tier', 'jenis'], 'unique_sla');
            
            $table->foreign('sub_unit_id')->references('id')->on('sub_units')->onDelete('cascade');
        });
    }
};
