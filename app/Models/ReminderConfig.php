<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderConfig extends Model
{
    protected $fillable = [
        'jenis_reminder',
        'lead_time_value',
        'channel_aktif',
        'aktif',
    ];

    protected $casts = [
        'lead_time_value' => 'integer',
        'channel_aktif' => 'array',
        'aktif' => 'boolean',
    ];

    public static function getConfig(string $jenis): ?self
    {
        return self::where('jenis_reminder', $jenis)
            ->where('aktif', true)
            ->first();
    }

    public function isChannelActive(string $channel): bool
    {
        return in_array($channel, $this->channel_aktif ?? []);
    }
}
