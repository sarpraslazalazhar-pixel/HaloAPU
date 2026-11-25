<?php

namespace Tests\Feature\Ai;

use App\Channels\WhatsAppChannel;
use App\Models\Admin;
use App\Models\AiBriefing;
use App\Models\Csat;
use App\Models\ReminderConfig;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\AiOperationalBriefingNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiBriefingTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unitA;

    private Unit $unitB;

    private SubUnit $subUnitA;

    private SubUnit $subUnitB;

    private User $requester;

    private Admin $superAdmin;

    private Admin $coordinatorA;

    private Admin $operatorA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->travelTo(Carbon::create(2026, 11, 24, 10, 0, 0, 'Asia/Jakarta'));

        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Koordinator', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'akses-assign-operator', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'akses-konfigurasi', 'guard_name' => 'admin']);

        $this->unitA = Unit::create(['nama_unit' => 'Unit IT', 'deskripsi' => 'IT', 'aktif' => true]);
        $this->unitB = Unit::create(['nama_unit' => 'Unit Sarpras', 'deskripsi' => 'Sarpras', 'aktif' => true]);

        $this->subUnitA = SubUnit::create([
            'unit_id' => $this->unitA->id,
            'nama_layanan' => 'Layanan Jaringan IT',
            'deskripsi' => 'Jaringan',
            'aktif' => true,
        ]);
        $this->subUnitB = SubUnit::create([
            'unit_id' => $this->unitB->id,
            'nama_layanan' => 'Layanan Kendaraan Sarpras',
            'deskripsi' => 'Kendaraan',
            'aktif' => true,
        ]);

        $this->requester = User::factory()->create([
            'name' => 'Pemohon Briefing',
            'username' => 'pemohon_briefing',
            'email' => 'pemohon_briefing@example.com',
        ]);

        $this->superAdmin = Admin::factory()->create([
            'name' => 'Super Admin',
            'username' => 'superadmin',
            'email' => 'superadmin@example.com',
            'no_wa' => '081111111111',
        ]);
        $this->superAdmin->assignRole('superadmin');

        $this->coordinatorA = Admin::factory()->create([
            'name' => 'Koordinator Unit IT',
            'username' => 'koord_it',
            'email' => 'koord_it@example.com',
            'no_wa' => '082222222222',
        ]);
        $this->coordinatorA->assignRole('Koordinator');
        $this->coordinatorA->givePermissionTo('akses-assign-operator');
        $this->coordinatorA->units()->attach($this->unitA->id);

        $this->operatorA = Admin::factory()->create([
            'name' => 'Operator Biasa',
            'username' => 'operator_biasa',
            'email' => 'operator_biasa@example.com',
        ]);
        $this->operatorA->assignRole('Operator');
        $this->operatorA->units()->attach($this->unitA->id);
    }

    private function seedOperationalDataAcrossUnits(): array
    {
        $appTz = (string) config('app.timezone', 'Asia/Jakarta');
        $yesterdayWib = Carbon::now('Asia/Jakarta')->subDay()->setTime(11, 0, 0)->timezone($appTz);
        $twoDaysAgoWib = Carbon::now('Asia/Jakarta')->subDays(3)->setTime(11, 0, 0)->timezone($appTz);

        // Unit A: 1 open breached ticket created yesterday, 1 solved yesterday with CSAT 5
        $ticketAOpen = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => ['keluhan' => 'Switch lantai 2 mati'],
            'status' => 'open',
            'priority' => 'Urgen',
            'source' => 'web',
        ]);
        DB::table('tickets')->where('id', $ticketAOpen->id)->update([
            'created_at' => $yesterdayWib,
            'updated_at' => $yesterdayWib,
        ]);
        TicketSlaTracking::create([
            'ticket_id' => $ticketAOpen->id,
            'sla_response_deadline' => Carbon::now($appTz)->subMinutes(30),
            'sla_resolution_deadline' => Carbon::now($appTz)->addHours(2),
            'is_response_breached' => true,
            'current_tier' => 1,
        ]);

        $ticketASolved = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => ['keluhan' => 'Reset akun VPN'],
            'status' => 'solve',
            'priority' => 'Sedang',
            'source' => 'web',
        ]);
        DB::table('tickets')->where('id', $ticketASolved->id)->update([
            'created_at' => $yesterdayWib,
            'updated_at' => $yesterdayWib,
        ]);
        $csatA = Csat::create([
            'ticket_id' => $ticketASolved->id,
            'user_id' => $this->requester->id,
            'rating' => 5,
            'komentar' => 'Cepat selesai',
        ]);
        DB::table('csats')->where('id', $csatA->id)->update([
            'created_at' => $yesterdayWib,
            'updated_at' => $yesterdayWib,
        ]);

        // Unit B: 2 active tickets (1 open breached created yesterday, 1 stale pending), 1 solved with CSAT 1
        $ticketBOpen = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitB->id,
            'sub_unit_id' => $this->subUnitB->id,
            'form_data' => ['keluhan' => 'AC Gedung B rusak berat'],
            'status' => 'open',
            'priority' => 'Urgen',
            'source' => 'web',
        ]);
        DB::table('tickets')->where('id', $ticketBOpen->id)->update([
            'created_at' => $yesterdayWib,
            'updated_at' => $yesterdayWib,
        ]);
        TicketSlaTracking::create([
            'ticket_id' => $ticketBOpen->id,
            'sla_response_deadline' => Carbon::now($appTz)->subHours(2),
            'sla_resolution_deadline' => Carbon::now($appTz)->subMinutes(10),
            'is_response_breached' => true,
            'is_resolution_breached' => true,
            'current_tier' => 2,
        ]);

        $ticketBPending = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitB->id,
            'sub_unit_id' => $this->subUnitB->id,
            'form_data' => ['keluhan' => 'Menunggu suku cadang Unit B'],
            'status' => 'pending',
            'priority' => 'Tinggi',
            'source' => 'web',
        ]);
        DB::table('tickets')->where('id', $ticketBPending->id)->update([
            'created_at' => $twoDaysAgoWib,
            'updated_at' => $twoDaysAgoWib,
        ]);

        $ticketBSolved = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitB->id,
            'sub_unit_id' => $this->subUnitB->id,
            'form_data' => ['keluhan' => 'Antar surat Unit B'],
            'status' => 'solve',
            'priority' => 'Rendah',
            'source' => 'web',
        ]);
        DB::table('tickets')->where('id', $ticketBSolved->id)->update([
            'created_at' => $yesterdayWib,
            'updated_at' => $yesterdayWib,
        ]);
        $csatB = Csat::create([
            'ticket_id' => $ticketBSolved->id,
            'user_id' => $this->requester->id,
            'rating' => 1,
            'komentar' => 'Terlalu lama di Unit B',
        ]);
        DB::table('csats')->where('id', $csatB->id)->update([
            'created_at' => $yesterdayWib,
            'updated_at' => $yesterdayWib,
        ]);

        return [
            'ticketAOpen' => $ticketAOpen,
            'ticketBOpen' => $ticketBOpen,
        ];
    }

    public function test_daily_and_weekly_briefing_commands_create_scoped_briefings_and_notify_eligible_admins(): void
    {
        config(['services.gemini.key' => '']);
        Notification::fake();

        $tickets = $this->seedOperationalDataAcrossUnits();

        // Daily briefing command
        $this->artisan('ai:briefing --period=daily')
            ->assertExitCode(0);

        $todayStr = Carbon::now('Asia/Jakarta')->format('Y-m-d');

        $superDaily = AiBriefing::where('admin_id', $this->superAdmin->id)
            ->where('period_type', 'daily')
            ->whereDate('briefing_date', $todayStr)
            ->first();
        $coordDaily = AiBriefing::where('admin_id', $this->coordinatorA->id)
            ->where('period_type', 'daily')
            ->whereDate('briefing_date', $todayStr)
            ->first();
        $opDaily = AiBriefing::where('admin_id', $this->operatorA->id)
            ->where('period_type', 'daily')
            ->first();

        $this->assertNotNull($superDaily);
        $this->assertNotNull($coordDaily);
        $this->assertNull($opDaily);

        // Superadmin snapshot sees all units (4 yesterday tickets, 2 solved, 2 open, 1 stale pending, 2 breaches, avg csat 3.0)
        $this->assertSame(4, $superDaily->metrics_snapshot['new_tickets']);
        $this->assertSame(2, $superDaily->metrics_snapshot['solved_tickets']);
        $this->assertSame(2, $superDaily->metrics_snapshot['active_open']);
        $this->assertSame(1, $superDaily->metrics_snapshot['stale_pending_gt_2_days']);
        $this->assertSame(2, $superDaily->metrics_snapshot['sla_breached_count']);
        $this->assertEquals(3.0, $superDaily->metrics_snapshot['csat_avg']);

        // Coordinator Unit A snapshot sees ONLY Unit A (2 yesterday tickets, 1 solved, 1 open, 0 stale pending, 1 breach, avg csat 5.0)
        $this->assertSame(2, $coordDaily->metrics_snapshot['new_tickets']);
        $this->assertSame(1, $coordDaily->metrics_snapshot['solved_tickets']);
        $this->assertSame(1, $coordDaily->metrics_snapshot['active_open']);
        $this->assertSame(0, $coordDaily->metrics_snapshot['stale_pending_gt_2_days']);
        $this->assertSame(1, $coordDaily->metrics_snapshot['sla_breached_count']);
        $this->assertEquals(5.0, $coordDaily->metrics_snapshot['csat_avg']);

        $coordAtRiskIds = array_column($coordDaily->metrics_snapshot['top_at_risk_tickets'], 'ticket_id');
        $this->assertSame([$tickets['ticketAOpen']->id], $coordAtRiskIds);
        $this->assertNotContains($tickets['ticketBOpen']->id, $coordAtRiskIds);

        $coordServices = array_column($coordDaily->metrics_snapshot['top_services'], 'nama_layanan');
        $this->assertSame(['Layanan Jaringan IT'], $coordServices);
        $this->assertStringNotContainsString('Unit Sarpras', $coordDaily->summary_markdown);
        $this->assertStringNotContainsString('Layanan Kendaraan Sarpras', $coordDaily->summary_markdown);

        Notification::assertSentTo($this->superAdmin, AiOperationalBriefingNotification::class);
        Notification::assertSentTo($this->coordinatorA, AiOperationalBriefingNotification::class);
        Notification::assertNotSentTo($this->operatorA, AiOperationalBriefingNotification::class);

        // Weekly briefing command
        $this->artisan('ai:briefing --period=weekly')
            ->assertExitCode(0);

        $superWeekly = AiBriefing::where('admin_id', $this->superAdmin->id)
            ->where('period_type', 'weekly')
            ->whereDate('briefing_date', $todayStr)
            ->first();
        $coordWeekly = AiBriefing::where('admin_id', $this->coordinatorA->id)
            ->where('period_type', 'weekly')
            ->whereDate('briefing_date', $todayStr)
            ->first();

        $this->assertNotNull($superWeekly);
        $this->assertNotNull($coordWeekly);
        $this->assertSame(5, $superWeekly->metrics_snapshot['new_tickets']);
        $this->assertSame(2, $coordWeekly->metrics_snapshot['new_tickets']);
    }

    public function test_deduplication_skips_same_day_unless_force_option_is_passed(): void
    {
        config(['services.gemini.key' => '']);
        Notification::fake();

        $this->seedOperationalDataAcrossUnits();

        $this->artisan('ai:briefing --period=daily')->assertExitCode(0);
        Notification::assertSentToTimes($this->superAdmin, AiOperationalBriefingNotification::class, 1);
        Notification::assertSentToTimes($this->coordinatorA, AiOperationalBriefingNotification::class, 1);
        $this->assertSame(2, AiBriefing::count());

        // Second run same day without --force -> skipped
        $this->artisan('ai:briefing --period=daily')
            ->expectsOutputToContain('dilewati: 2')
            ->assertExitCode(0);

        Notification::assertSentToTimes($this->superAdmin, AiOperationalBriefingNotification::class, 1);
        Notification::assertSentToTimes($this->coordinatorA, AiOperationalBriefingNotification::class, 1);
        $this->assertSame(2, AiBriefing::count());

        // Third run with --force -> regenerated & re-sent without creating duplicate rows
        $this->artisan('ai:briefing --period=daily --force')
            ->expectsOutputToContain('Terkirim: 2')
            ->assertExitCode(0);

        Notification::assertSentToTimes($this->superAdmin, AiOperationalBriefingNotification::class, 2);
        Notification::assertSentToTimes($this->coordinatorA, AiOperationalBriefingNotification::class, 2);
        $this->assertSame(2, AiBriefing::count());
    }

    public function test_respects_reminder_config_active_flag_channels_and_validation_update(): void
    {
        config(['services.gemini.key' => '']);
        Notification::fake();

        $dailyConfig = ReminderConfig::where('jenis_reminder', 'ai_briefing_daily')->firstOrFail();
        $weeklyConfig = ReminderConfig::where('jenis_reminder', 'ai_briefing_weekly')->firstOrFail();

        // ReminderConfigController update works cleanly for ai_briefing_* rows
        $this->actingAs($this->superAdmin, 'admin')
            ->put(route('admin.reminder-config.update'), [
                'configs' => [
                    [
                        'id' => $dailyConfig->id,
                        'lead_time_value' => 0,
                        'channel_aktif' => ['in_app', 'email', 'whatsapp'],
                        'aktif' => true,
                    ],
                    [
                        'id' => $weeklyConfig->id,
                        'lead_time_value' => 0,
                        'channel_aktif' => ['in_app'],
                        'aktif' => true,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $dailyConfig->refresh();
        $this->assertSame(['in_app', 'email', 'whatsapp'], $dailyConfig->channel_aktif);

        // Disable daily briefing and run command -> skipped cleanly
        $dailyConfig->update(['aktif' => false]);

        $this->artisan('ai:briefing --period=daily')
            ->expectsOutputToContain('tidak aktif')
            ->assertExitCode(0);

        $this->assertSame(0, AiBriefing::count());
        Notification::assertNothingSent();

        // Re-enable with in_app only -> notification uses only database
        $dailyConfig->update(['aktif' => true, 'channel_aktif' => ['in_app']]);

        $briefing = AiBriefing::create([
            'admin_id' => $this->superAdmin->id,
            'period_type' => 'daily',
            'briefing_date' => '2026-11-24',
            'scope_label' => 'Semua Unit (Global)',
            'title' => 'Test Channel Briefing',
            'summary_markdown' => 'Ringkasan test',
            'metrics_snapshot' => ['new_tickets' => 1],
            'is_fallback' => true,
        ]);

        $notification = new AiOperationalBriefingNotification($briefing);
        $this->assertSame(['database'], $notification->via($this->superAdmin));

        // Enable email + whatsapp channels -> notification via includes mail & WhatsAppChannel
        $dailyConfig->update(['channel_aktif' => ['in_app', 'email', 'whatsapp']]);
        $channelsAll = $notification->via($this->superAdmin);
        $this->assertContains('database', $channelsAll);
        $this->assertContains('mail', $channelsAll);
        $this->assertContains(WhatsAppChannel::class, $channelsAll);
    }

    public function test_on_demand_generate_and_show_briefing_endpoints_with_read_tracking_and_ownership_guard(): void
    {
        config([
            'services.gemini.key' => 'AIzaBriefingTestKey',
            'services.gemini.model' => 'gemini-3.6-flash',
        ]);

        $this->seedOperationalDataAcrossUnits();

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [['text' => "### Ringkasan Briefing AI\n\n- Kondisi Unit IT terkendali."]],
                    ],
                ]],
            ], 200),
        ]);

        $generateResp = $this->actingAs($this->coordinatorA, 'admin')
            ->postJson('/admin/ai/briefings/generate', [
                'period_type' => 'daily',
            ]);

        $generateResp->assertCreated()
            ->assertJsonPath('briefing.admin_id', $this->coordinatorA->id)
            ->assertJsonPath('briefing.period_type', 'daily')
            ->assertJsonPath('briefing.is_fallback', false)
            ->assertJsonPath('briefing.read_at', null);

        $briefingId = (int) $generateResp->json('briefing.id');

        // Another admin cannot read this briefing
        $this->actingAs($this->operatorA, 'admin')
            ->getJson("/admin/ai/briefings/{$briefingId}")
            ->assertForbidden();

        // Owner reads briefing -> marks read_at
        $showResp = $this->actingAs($this->coordinatorA, 'admin')
            ->getJson("/admin/ai/briefings/{$briefingId}");

        $showResp->assertOk();
        $this->assertNotNull($showResp->json('briefing.read_at'));
        $this->assertNotNull(AiBriefing::findOrFail($briefingId)->read_at);
    }

    public function test_scheduler_ai_briefing_endpoint_enforces_akses_konfigurasi_permission(): void
    {
        config(['services.gemini.key' => '']);
        Notification::fake();

        // Coordinator without akses-konfigurasi receives 403
        $this->actingAs($this->coordinatorA, 'admin')
            ->post('/admin/scheduler/ai-briefing', ['period' => 'daily'])
            ->assertForbidden();

        // Admin with akses-konfigurasi succeeds and triggers ai:briefing
        $this->operatorA->givePermissionTo('akses-konfigurasi');

        $this->actingAs($this->operatorA, 'admin')
            ->post('/admin/scheduler/ai-briefing', ['period' => 'daily'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertGreaterThanOrEqual(1, AiBriefing::where('period_type', 'daily')->count());
        Notification::assertSentTo($this->superAdmin, AiOperationalBriefingNotification::class);
    }
}
