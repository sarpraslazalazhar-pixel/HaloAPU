<?php

namespace Database\Seeders;

use App\Models\ReminderConfig;
use Illuminate\Database\Seeder;

class ReminderConfigSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'jenis_reminder' => 'booking',
                'lead_time_value' => 1,
                'channel_aktif' => ['in_app', 'email'],
                'aktif' => true,
            ],
            [
                'jenis_reminder' => 'sla',
                'lead_time_value' => 0,
                'channel_aktif' => ['in_app', 'email'],
                'aktif' => true,
            ],
            [
                'jenis_reminder' => 'pending_lama',
                'lead_time_value' => 3,
                'channel_aktif' => ['in_app', 'email'],
                'aktif' => true,
            ],
            [
                'jenis_reminder' => 'csat',
                'lead_time_value' => 2,
                'channel_aktif' => ['in_app', 'email'],
                'aktif' => true,
            ],
        ];

        foreach ($defaults as $config) {
            ReminderConfig::updateOrCreate(
                ['jenis_reminder' => $config['jenis_reminder']],
                $config
            );
        }
    }
}
