<?php

use App\Models\ReminderConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reminder_configs')) {
            return;
        }

        ReminderConfig::firstOrCreate(
            ['jenis_reminder' => 'ai_briefing_daily'],
            [
                'lead_time_value' => 0,
                'channel_aktif' => ['in_app'],
                'aktif' => true,
            ]
        );

        ReminderConfig::firstOrCreate(
            ['jenis_reminder' => 'ai_briefing_weekly'],
            [
                'lead_time_value' => 0,
                'channel_aktif' => ['in_app'],
                'aktif' => true,
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('reminder_configs')) {
            return;
        }

        ReminderConfig::whereIn('jenis_reminder', [
            'ai_briefing_daily',
            'ai_briefing_weekly',
        ])->delete();
    }
};
