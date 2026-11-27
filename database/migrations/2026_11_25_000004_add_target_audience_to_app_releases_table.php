<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('app_releases', 'target_audience')) {
            Schema::table('app_releases', function (Blueprint $table) {
                $table->string('target_audience', 20)->default('all')->after('channel');
                $table->index(
                    ['platform', 'channel', 'is_active', 'target_audience'],
                    'app_releases_audience_lookup_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('app_releases', 'target_audience')) {
            Schema::table('app_releases', function (Blueprint $table) {
                try {
                    $table->dropIndex('app_releases_audience_lookup_idx');
                } catch (\Throwable) {
                    // Index may already be absent on some database engines.
                }

                $table->dropColumn('target_audience');
            });
        }
    }
};
