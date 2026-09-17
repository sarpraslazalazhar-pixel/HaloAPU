<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\SystemConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SystemConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemConfig::clearCache();
    }

    public function test_get_value_returns_default_when_not_set(): void
    {
        $value = SystemConfig::getValue('non_existent_key', 'default_value');
        $this->assertEquals('default_value', $value);

        // Ensure default is not poisoned for a subsequent call with a different default
        $differentDefault = SystemConfig::getValue('non_existent_key', 'another_default');
        $this->assertEquals('another_default', $differentDefault);
    }

    public function test_get_value_returns_persisted_value(): void
    {
        SystemConfig::setValue('nama_sistem', 'Halo APU Test');
        $this->assertEquals('Halo APU Test', SystemConfig::getValue('nama_sistem'));
    }

    public function test_get_value_decodes_json_array(): void
    {
        $schedule = [
            'senin' => ['08:00', '16:00'],
            'selasa' => ['08:00', '16:00'],
        ];
        SystemConfig::setValue('jam_kerja', $schedule);

        $retrieved = SystemConfig::getValue('jam_kerja');
        $this->assertIsArray($retrieved);
        $this->assertEquals($schedule, $retrieved);
    }

    public function test_cache_cleared_on_set_value(): void
    {
        SystemConfig::setValue('nama_sistem', 'Old Name');
        $this->assertEquals('Old Name', SystemConfig::getValue('nama_sistem'));

        SystemConfig::setValue('nama_sistem', 'New Name');
        $this->assertEquals('New Name', SystemConfig::getValue('nama_sistem'));
    }

    public function test_in_memory_and_persistent_caching_minimizes_queries(): void
    {
        SystemConfig::setValue('nama_sistem', 'Halo APU');
        SystemConfig::setValue('logo_path', 'branding/logo.png');
        SystemConfig::setValue('banner_path', 'branding/banner.png');

        // Reset runtime cache to simulate fresh request
        SystemConfig::clearCache();

        DB::enableQueryLog();

        // 1st call triggers query to load all configs
        $val1 = SystemConfig::getValue('nama_sistem');
        // Subsequent calls within same request hit memory
        $val2 = SystemConfig::getValue('logo_path');
        $val3 = SystemConfig::getValue('banner_path');
        $val4 = SystemConfig::getValue('favicon_path', 'default_favicon.ico');

        $queries = DB::getQueryLog();

        $this->assertEquals('Halo APU', $val1);
        $this->assertEquals('branding/logo.png', $val2);
        $this->assertEquals('branding/banner.png', $val3);
        $this->assertEquals('default_favicon.ico', $val4);

        // In array cache store or after first load, only 1 query should have been executed to system_configs
        $configQueries = array_filter($queries, fn ($q) => str_contains($q['query'], 'system_configs'));
        $this->assertLessThanOrEqual(1, count($configQueries));
    }

    public function test_get_app_config_returns_expected_keys(): void
    {
        SystemConfig::setValue('nama_sistem', 'Custom App');
        SystemConfig::setValue('logo_path', 'logo.png');

        $appConfig = SystemConfig::getAppConfig();

        $this->assertArrayHasKey('nama_sistem', $appConfig);
        $this->assertArrayHasKey('logo_path', $appConfig);
        $this->assertArrayHasKey('banner_path', $appConfig);
        $this->assertArrayHasKey('favicon_path', $appConfig);
        $this->assertArrayHasKey('notification_sound_path', $appConfig);

        $this->assertEquals('Custom App', $appConfig['nama_sistem']);
        $this->assertEquals('logo.png', $appConfig['logo_path']);
    }
}
