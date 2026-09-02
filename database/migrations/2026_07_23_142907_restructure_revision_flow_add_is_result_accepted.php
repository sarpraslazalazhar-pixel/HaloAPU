<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->boolean('is_result_accepted')->default(false)->after('revision_count');
        });

        // Migrate existing waiting_approval tickets to solve
        DB::table('tickets')->where('status', 'waiting_approval')->update([
            'status' => 'solve',
            'is_result_accepted' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('is_result_accepted');
        });
    }
};
