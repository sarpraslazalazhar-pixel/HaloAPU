<?php

namespace Tests\Feature\Ai;

use App\Models\Admin;
use App\Models\AiBriefing;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Csat;
use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\User;
use App\Services\Ai\Tools\AiToolRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HaloApuAiWorkspaceTest extends TestCase
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

    private Admin $operatorB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Koordinator', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'akses-assign-operator', 'guard_name' => 'admin']);

        $this->unitA = Unit::create(['nama_unit' => 'Unit IT', 'deskripsi' => 'Layanan IT', 'aktif' => true]);
        $this->unitB = Unit::create(['nama_unit' => 'Unit Sarpras', 'deskripsi' => 'Layanan Sarpras', 'aktif' => true]);

        $this->subUnitA = SubUnit::create([
            'unit_id' => $this->unitA->id,
            'nama_layanan' => 'Jaringan & Infrastruktur',
            'deskripsi' => 'Jaringan',
            'aktif' => true,
        ]);

        $this->subUnitB = SubUnit::create([
            'unit_id' => $this->unitB->id,
            'nama_layanan' => 'Kendaraan Operasional',
            'deskripsi' => 'Kendaraan',
            'aktif' => true,
        ]);

        $this->requester = User::factory()->create([
            'name' => 'Pemohon Reguler',
            'username' => 'pemohon_reguler',
            'email' => 'pemohon_reguler@example.com',
        ]);

        $this->superAdmin = Admin::factory()->create([
            'name' => 'Super Admin',
            'username' => 'superadmin',
            'email' => 'superadmin@example.com',
        ]);
        $this->superAdmin->assignRole('superadmin');

        $this->coordinatorA = Admin::factory()->create([
            'name' => 'Koordinator IT',
            'username' => 'koord_it',
            'email' => 'koord_it@example.com',
        ]);
        $this->coordinatorA->assignRole('Koordinator');
        $this->coordinatorA->givePermissionTo('akses-assign-operator');
        $this->coordinatorA->units()->attach($this->unitA->id);

        $this->operatorA = Admin::factory()->create([
            'name' => 'Operator IT',
            'username' => 'operator_it',
            'email' => 'operator_it@example.com',
        ]);
        $this->operatorA->assignRole('Operator');
        $this->operatorA->units()->attach($this->unitA->id);

        $this->operatorB = Admin::factory()->create([
            'name' => 'Operator Sarpras',
            'username' => 'operator_sarpras',
            'email' => 'operator_sarpras@example.com',
        ]);
        $this->operatorB->assignRole('Operator');
        $this->operatorB->units()->attach($this->unitB->id);
    }

    public function test_guest_is_redirected_to_login_and_authenticated_admin_receives_workspace_props(): void
    {
        $this->get('/admin/ai')->assertRedirect(route('login'));
        $this->actingAs($this->requester, 'web')->get('/admin/ai')->assertRedirect(route('login'));
        $this->postJson('/admin/ai/sessions', ['title' => 'Test'])->assertRedirect(route('login'));

        $response = $this->actingAs($this->operatorA, 'admin')
            ->get('/admin/ai');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AiBot/Index')
                ->has('hasApiKey')
                ->has('geminiModel')
                ->has('quickMetrics', fn (Assert $m) => $m
                    ->hasAll(['total_tickets', 'open_tickets', 'in_progress', 'breached_active', 'avg_csat', 'scope'])
                )
                ->has('scopeInfo', fn (Assert $s) => $s
                    ->where('level', 'unit')
                    ->hasAll(['label', 'unit_names', 'can_view_team_operators'])
                )
                ->has('sessions')
                ->has('briefings')
                ->has('unreadBriefingsCount')
            );
    }

    public function test_multi_session_crud_and_strict_ownership_isolation(): void
    {
        $createResp = $this->actingAs($this->operatorA, 'admin')
            ->postJson('/admin/ai/sessions', [
                'title' => 'Audit SLA Mingguan',
                'scope_filters' => ['period' => '7d'],
            ]);

        $createResp->assertCreated()
            ->assertJsonPath('conversation.title', 'Audit SLA Mingguan')
            ->assertJsonPath('conversation.admin_id', $this->operatorA->id);

        $sessionId = (int) $createResp->json('conversation.id');

        AiMessage::create([
            'ai_conversation_id' => $sessionId,
            'role' => 'user',
            'content' => 'Cek SLA hari ini',
        ]);

        // List on workspace index
        $this->actingAs($this->operatorA, 'admin')
            ->get('/admin/ai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sessions', 1)
                ->where('sessions.0.id', $sessionId)
            );

        // Show session
        $this->actingAs($this->operatorA, 'admin')
            ->getJson("/admin/ai/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('conversation.id', $sessionId)
            ->assertJsonCount(1, 'messages');

        // Rename session
        $this->actingAs($this->operatorA, 'admin')
            ->patchJson("/admin/ai/sessions/{$sessionId}", ['title' => 'Audit SLA Unit IT'])
            ->assertOk()
            ->assertJsonPath('conversation.title', 'Audit SLA Unit IT');

        $briefingA = AiBriefing::create([
            'admin_id' => $this->operatorA->id,
            'period_type' => 'daily',
            'briefing_date' => Carbon::now('Asia/Jakarta')->format('Y-m-d'),
            'scope_label' => 'Unit: Unit IT',
            'title' => 'Briefing Unit IT',
            'summary_markdown' => 'Ringkasan IT',
            'metrics_snapshot' => ['new_tickets' => 1],
            'is_fallback' => true,
        ]);

        // Strict ownership isolation: Operator B gets 403 on every session/briefing action of Operator A
        $this->actingAs($this->operatorB, 'admin')
            ->get("/admin/ai?session={$sessionId}")
            ->assertForbidden();

        $this->actingAs($this->operatorB, 'admin')
            ->getJson("/admin/ai/sessions/{$sessionId}")
            ->assertForbidden();

        $this->actingAs($this->operatorB, 'admin')
            ->patchJson("/admin/ai/sessions/{$sessionId}", ['title' => 'Hijack'])
            ->assertForbidden();

        $this->actingAs($this->operatorB, 'admin')
            ->deleteJson("/admin/ai/sessions/{$sessionId}")
            ->assertForbidden();

        $this->actingAs($this->operatorB, 'admin')
            ->postJson('/admin/ai/chat', [
                'message' => 'Cek tiket',
                'conversation_id' => $sessionId,
            ])
            ->assertForbidden();

        $this->actingAs($this->operatorB, 'admin')
            ->get("/admin/ai/sessions/{$sessionId}/export-pdf")
            ->assertForbidden();

        $this->actingAs($this->operatorB, 'admin')
            ->getJson("/admin/ai/briefings/{$briefingA->id}")
            ->assertForbidden();

        // Owner deletes session
        $this->actingAs($this->operatorA, 'admin')
            ->deleteJson("/admin/ai/sessions/{$sessionId}")
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertDatabaseMissing('ai_conversations', ['id' => $sessionId]);
    }

    public function test_strict_unit_access_scoping_across_quick_metrics_all_tools_and_local_fallback(): void
    {
        config(['services.gemini.key' => '']);

        // Unit A tickets (2 total: 1 open breached + booking, 1 solve + CSAT 5)
        $ticketA1 = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => ['topik' => 'Switch Ruang Server IT'],
            'status' => 'open',
            'priority' => 'Urgen',
            'assigned_admin_id' => $this->operatorA->id,
            'source' => 'web',
        ]);
        TicketSlaTracking::create([
            'ticket_id' => $ticketA1->id,
            'sla_response_deadline' => now()->subHour(),
            'sla_resolution_deadline' => now()->addHours(2),
            'is_response_breached' => true,
            'is_resolution_breached' => false,
            'current_tier' => 1,
        ]);
        RoomVehicleBooking::create([
            'ticket_id' => $ticketA1->id,
            'tipe' => 'Ruang',
            'nama_aset' => 'Ruang Rapat IT',
            'tanggal_mulai' => now()->addDay()->setTime(9, 0),
            'tanggal_selesai' => now()->addDay()->setTime(11, 0),
            'status' => 'disetujui',
        ]);

        $ticketA2 = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => ['topik' => 'Reset Email Unit IT'],
            'status' => 'solve',
            'priority' => 'Sedang',
            'assigned_admin_id' => $this->operatorA->id,
            'source' => 'web',
        ]);
        TicketLog::create([
            'ticket_id' => $ticketA2->id,
            'admin_id' => $this->operatorA->id,
            'aksi' => 'solve',
            'catatan' => 'Selesai IT',
        ]);
        Csat::create([
            'ticket_id' => $ticketA2->id,
            'user_id' => $this->requester->id,
            'rating' => 5,
            'komentar' => 'Layanan IT sangat cepat',
        ]);

        // Unit B tickets (3 total: 1 open breached + booking, 1 on_proses breached, 1 solve + CSAT 1)
        $ticketB1 = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitB->id,
            'sub_unit_id' => $this->subUnitB->id,
            'form_data' => ['topik' => 'RAHASIA_UNIT_B Mobil Dinas'],
            'status' => 'open',
            'priority' => 'Urgen',
            'assigned_admin_id' => $this->operatorB->id,
            'source' => 'web',
        ]);
        TicketSlaTracking::create([
            'ticket_id' => $ticketB1->id,
            'sla_response_deadline' => now()->subHours(2),
            'sla_resolution_deadline' => now()->addHour(),
            'is_response_breached' => true,
            'is_resolution_breached' => true,
            'current_tier' => 2,
        ]);
        RoomVehicleBooking::create([
            'ticket_id' => $ticketB1->id,
            'tipe' => 'Kendaraan',
            'nama_aset' => 'Avanza Operasional Unit B',
            'tanggal_mulai' => now()->addDay()->setTime(13, 0),
            'tanggal_selesai' => now()->addDay()->setTime(16, 0),
            'status' => 'disetujui',
        ]);

        $ticketB2 = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitB->id,
            'sub_unit_id' => $this->subUnitB->id,
            'form_data' => ['topik' => 'Servis AC Unit B'],
            'status' => 'on_proses',
            'priority' => 'Tinggi',
            'assigned_admin_id' => $this->operatorB->id,
            'source' => 'web',
        ]);
        TicketSlaTracking::create([
            'ticket_id' => $ticketB2->id,
            'sla_response_deadline' => now()->subHours(3),
            'sla_resolution_deadline' => now()->subMinute(),
            'responded_at' => now()->subHours(4),
            'is_response_breached' => false,
            'is_resolution_breached' => true,
            'current_tier' => 2,
        ]);

        $ticketB3 = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitB->id,
            'sub_unit_id' => $this->subUnitB->id,
            'form_data' => ['topik' => 'Pinjam Proyektor Unit B'],
            'status' => 'solve',
            'priority' => 'Rendah',
            'assigned_admin_id' => $this->operatorB->id,
            'source' => 'web',
        ]);
        TicketLog::create([
            'ticket_id' => $ticketB3->id,
            'admin_id' => $this->operatorB->id,
            'aksi' => 'solve',
            'catatan' => 'Selesai Sarpras',
        ]);
        Csat::create([
            'ticket_id' => $ticketB3->id,
            'user_id' => $this->requester->id,
            'rating' => 1,
            'komentar' => 'Keluhan rahasia Unit B',
        ]);

        // 1. quickMetrics strictly scoped for Operator A vs global for Superadmin
        $this->actingAs($this->operatorA, 'admin')
            ->get('/admin/ai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('quickMetrics.total_tickets', 2)
                ->where('quickMetrics.open_tickets', 1)
                ->where('quickMetrics.in_progress', 0)
                ->where('quickMetrics.breached_active', 1)
                ->where('quickMetrics.avg_csat', 5)
                ->where('scopeInfo.level', 'unit')
            );

        $this->actingAs($this->superAdmin, 'admin')
            ->get('/admin/ai')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('quickMetrics.total_tickets', 5)
                ->where('quickMetrics.open_tickets', 2)
                ->where('quickMetrics.in_progress', 1)
                ->where('quickMetrics.breached_active', 3)
                ->where('quickMetrics.avg_csat', 3)
                ->where('scopeInfo.level', 'global')
            );

        // 2. All 7 AI tools strictly scoped for Operator A
        $registry = app(AiToolRegistry::class);

        $summaryOpA = $registry->execute('get_ticket_summary', $this->operatorA, ['period' => '30d'])->data;
        $this->assertSame(2, $summaryOpA['total_tickets']);

        $summaryDeniedUnitB = $registry->execute('get_ticket_summary', $this->operatorA, [
            'period' => '30d',
            'unit_id' => $this->unitB->id,
        ])->data;
        $this->assertArrayHasKey('scope_note', $summaryDeniedUnitB);
        $this->assertSame(2, $summaryDeniedUnitB['total_tickets']);

        $atRiskOpA = $registry->execute('list_at_risk_sla_tickets', $this->operatorA, ['unit_id' => $this->unitB->id])->data;
        $this->assertArrayHasKey('scope_note', $atRiskOpA);
        $this->assertSame(1, $atRiskOpA['total_at_risk']);
        $this->assertSame([$ticketA1->id], array_column($atRiskOpA['tickets'], 'ticket_id'));

        $atRiskSuper = $registry->execute('list_at_risk_sla_tickets', $this->superAdmin, [])->data;
        $this->assertSame(3, $atRiskSuper['total_at_risk']);

        $searchLeak = $registry->execute('search_tickets', $this->operatorA, ['query' => 'RAHASIA_UNIT_B'])->data;
        $this->assertSame(0, $searchLeak['total_matched']);

        $searchAllowed = $registry->execute('search_tickets', $this->operatorA, ['query' => 'Switch Ruang Server'])->data;
        $this->assertSame(1, $searchAllowed['total_matched']);
        $this->assertSame($ticketA1->id, $searchAllowed['tickets'][0]['ticket_id']);

        $detailDenied = $registry->execute('get_ticket_detail', $this->operatorA, ['ticket_id' => (string) $ticketB1->id])->data;
        $this->assertArrayHasKey('error', $detailDenied);

        $detailAllowed = $registry->execute('get_ticket_detail', $this->operatorA, ['ticket_id' => $ticketA1->formatted_id])->data;
        $this->assertArrayNotHasKey('error', $detailAllowed);
        $this->assertSame($ticketA1->id, $detailAllowed['ticket_id']);

        $csatOpA = $registry->execute('get_csat_analysis', $this->operatorA, ['period' => '30d'])->data;
        $this->assertSame(1, $csatOpA['total_reviews']);
        $this->assertEquals(5.0, $csatOpA['avg_rating']);
        $this->assertStringNotContainsString('Keluhan rahasia Unit B', json_encode($csatOpA) ?: '');

        $bookingOpA = $registry->execute('get_booking_schedule', $this->operatorA, [])->data;
        $this->assertSame(1, $bookingOpA['total_bookings']);
        $this->assertSame('Ruang Rapat IT', $bookingOpA['bookings'][0]['nama_aset']);

        $bookingSuper = $registry->execute('get_booking_schedule', $this->superAdmin, [])->data;
        $this->assertSame(2, $bookingSuper['total_bookings']);

        $perfOpA = $registry->execute('get_operator_performance', $this->operatorA, ['period' => '30d'])->data;
        $this->assertSame([$this->operatorA->id], array_column($perfOpA['operators'], 'id'));

        $perfCoordA = $registry->execute('get_operator_performance', $this->coordinatorA, ['period' => '30d'])->data;
        $coordOpIds = array_column($perfCoordA['operators'], 'id');
        $this->assertContains($this->operatorA->id, $coordOpIds);
        $this->assertNotContains($this->operatorB->id, $coordOpIds);

        $perfSuper = $registry->execute('get_operator_performance', $this->superAdmin, ['period' => '30d'])->data;
        $superOpIds = array_column($perfSuper['operators'], 'id');
        $this->assertContains($this->operatorA->id, $superOpIds);
        $this->assertContains($this->operatorB->id, $superOpIds);

        // 3. Local fallback mode also enforces unit scope
        $fallbackChat = $this->actingAs($this->operatorA, 'admin')
            ->postJson('/admin/ai/chat', [
                'message' => 'Berikan ringkasan operasional tiket saat ini',
                'period_filter' => '30d',
            ]);

        $fallbackChat->assertOk()
            ->assertJsonPath('is_fallback', true)
            ->assertJsonPath('model_name', 'local-fallback');
        $this->assertStringContainsString('Unit IT', (string) $fallbackChat->json('reply'));
        $this->assertStringNotContainsString('Unit Sarpras', (string) $fallbackChat->json('reply'));

        $fallbackDetailDenied = $this->actingAs($this->operatorA, 'admin')
            ->postJson('/admin/ai/chat', [
                'message' => "Tampilkan detail tiket #{$ticketB1->id}",
            ]);
        $fallbackDetailDenied->assertOk();
        $this->assertStringContainsString('di luar cakupan akses unit kamu', (string) $fallbackDetailDenied->json('reply'));
        $this->assertStringNotContainsString('RAHASIA_UNIT_B', (string) $fallbackDetailDenied->json('reply'));
    }

    public function test_end_to_end_tool_calling_via_http_fake_persists_messages_tools_used_and_charts(): void
    {
        config([
            'services.gemini.key' => 'AIzaFakeWorkspaceKey123',
            'services.gemini.model' => 'gemini-3.6-flash',
        ]);

        $ticket = Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => ['topik' => 'Router mati'],
            'status' => 'open',
            'priority' => 'Urgen',
            'source' => 'web',
        ]);
        TicketSlaTracking::create([
            'ticket_id' => $ticket->id,
            'sla_response_deadline' => now()->subMinutes(10),
            'sla_resolution_deadline' => now()->addHours(2),
            'is_response_breached' => true,
            'current_tier' => 1,
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [
                                [
                                    'thoughtSignature' => 'sig-1',
                                    'functionCall' => [
                                        'id' => 'call_sla_risk',
                                        'name' => 'list_at_risk_sla_tickets',
                                        'args' => ['only_breached' => false],
                                    ],
                                ],
                                [
                                    'thoughtSignature' => 'sig-2',
                                    'functionCall' => [
                                        'id' => 'call_sum',
                                        'name' => 'get_ticket_summary',
                                        'args' => ['period' => '30d'],
                                    ],
                                ],
                            ],
                        ],
                    ]],
                ], 200)
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [[
                                'thoughtSignature' => 'sig-3',
                                'functionCall' => [
                                    'id' => 'call_cmp',
                                    'name' => 'compare_periods',
                                    'args' => ['comparison' => 'this_month_vs_last_month'],
                                ],
                            ]],
                        ],
                    ]],
                ], 200)
                ->push([
                    'candidates' => [[
                        'content' => [
                            'role' => 'model',
                            'parts' => [[
                                'text' => "Ditemukan 1 tiket rawan SLA: [#{$ticket->formatted_id}](/admin/tiket/{$ticket->id}).",
                            ]],
                        ],
                    ]],
                ], 200),
        ]);

        $response = $this->actingAs($this->superAdmin, 'admin')
            ->postJson('/admin/ai/chat', [
                'message' => 'Analisis tiket rawan SLA dan perbandingan bulan ini',
                'period_filter' => '30d',
            ]);

        $response->assertOk()
            ->assertJsonPath('is_fallback', false)
            ->assertJsonPath('model_name', 'gemini-3.6-flash')
            ->assertJsonPath('tools_used', [
                'list_at_risk_sla_tickets',
                'get_ticket_summary',
                'compare_periods',
            ]);

        $charts = $response->json('charts');
        $this->assertIsArray($charts);
        $this->assertNotEmpty($charts);
        $chartIds = array_column($charts, 'id');
        $this->assertContains('ticket_status_distribution', $chartIds);
        $this->assertContains('period_comparison', $chartIds);

        $conversationId = (int) $response->json('conversation.id');
        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversationId,
            'role' => 'user',
            'content' => 'Analisis tiket rawan SLA dan perbandingan bulan ini',
        ]);

        $assistantRow = AiMessage::where('ai_conversation_id', $conversationId)
            ->where('role', 'model')
            ->firstOrFail();

        $this->assertFalse($assistantRow->is_fallback);
        $this->assertSame(['list_at_risk_sla_tickets', 'get_ticket_summary', 'compare_periods'], $assistantRow->tools_used);
        $this->assertCount(2, $assistantRow->charts ?? []);
    }

    public function test_fallback_mode_handles_empty_key_http_500_and_off_topic_guardrails(): void
    {
        Ticket::create([
            'user_id' => $this->requester->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => ['topik' => 'VPN lambat'],
            'status' => 'open',
            'priority' => 'Sedang',
            'source' => 'web',
        ]);

        // 1. Empty GEMINI_API_KEY
        config(['services.gemini.key' => '']);
        $emptyKeyResp = $this->actingAs($this->operatorA, 'admin')
            ->postJson('/admin/ai/chat', [
                'message' => 'Bagaimana ringkasan tiket bulan ini?',
                'period_filter' => '30d',
            ]);

        $emptyKeyResp->assertOk()
            ->assertJsonPath('is_fallback', true)
            ->assertJsonPath('model_name', 'local-fallback');
        $this->assertNotEmpty($emptyKeyResp->json('charts'));

        // 2. Off-topic rejection (resep, politik)
        $offTopicResp = $this->actingAs($this->operatorA, 'admin')
            ->postJson('/admin/ai/chat', [
                'message' => 'Tolong berikan resep nasi goreng dan bahas politik terkini',
            ]);

        $offTopicResp->assertOk()
            ->assertJsonPath('is_fallback', true)
            ->assertJsonPath('tools_used', [])
            ->assertJsonPath('charts', []);
        $this->assertStringContainsString('HaloAPU', (string) $offTopicResp->json('reply'));

        // 3. HTTP 500 fallback
        config(['services.gemini.key' => 'AIzaFakeServerErrorKey']);
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Upstream Gemini 500 error'],
            ], 500),
        ]);

        $http500Resp = $this->actingAs($this->operatorA, 'admin')
            ->postJson('/admin/ai/chat', [
                'message' => 'Tampilkan status tiket unit IT',
                'period_filter' => '30d',
            ]);

        $http500Resp->assertOk()
            ->assertJsonPath('is_fallback', true)
            ->assertJsonPath('model_name', 'local-fallback');
        $this->assertStringContainsString('Menampilkan analisis langsung dari database HaloAPU', (string) $http500Resp->json('reply'));
        $this->assertNotEmpty($http500Resp->json('charts'));
    }

    public function test_rate_limiter_throttle_ai_chat_returns_429_after_15_requests_per_minute(): void
    {
        config(['services.gemini.key' => '']);
        RateLimiter::clear('ai-admin:' . $this->operatorA->id);

        for ($i = 1; $i <= 15; $i++) {
            $this->actingAs($this->operatorA, 'admin')
                ->postJson('/admin/ai/chat', ['message' => "Cek ringkasan tiket #{$i}"])
                ->assertOk();
        }

        $blocked = $this->actingAs($this->operatorA, 'admin')
            ->postJson('/admin/ai/chat', ['message' => 'Permintaan ke-16']);

        $blocked->assertStatus(429)
            ->assertJsonPath('error_code', 'AI_RATE_LIMITED')
            ->assertJsonStructure(['message', 'error_code', 'retry_after']);
    }

    public function test_pdf_export_succeeds_for_owner_session_and_single_message_and_forbids_other_admin(): void
    {
        $conversation = AiConversation::create([
            'admin_id' => $this->operatorA->id,
            'title' => 'Laporan Audit IT',
            'last_message_at' => now(),
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Ringkas tiket IT',
        ]);

        $assistantMsg = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'model',
            'content' => "### Ringkasan IT\n\n- **Total**: 2 tiket",
            'is_fallback' => true,
            'model_name' => 'local-fallback',
            'charts' => [[
                'id' => 'ticket_status_distribution',
                'type' => 'bar',
                'title' => 'Distribusi Status Tiket',
                'subtitle' => '30 Hari Terakhir',
                'labels' => ['Open', 'Selesai'],
                'series' => [['name' => 'Jumlah Tiket', 'data' => [1, 1]]],
                'unit' => 'tiket',
            ]],
        ]);

        // Full session PDF
        $fullPdf = $this->actingAs($this->operatorA, 'admin')
            ->get("/admin/ai/sessions/{$conversation->id}/export-pdf");
        $fullPdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $fullPdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $fullPdf->getContent());

        // Single message PDF
        $singlePdf = $this->actingAs($this->operatorA, 'admin')
            ->get("/admin/ai/sessions/{$conversation->id}/export-pdf?message_id={$assistantMsg->id}");
        $singlePdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $singlePdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $singlePdf->getContent());

        // Other admin gets 403
        $this->actingAs($this->operatorB, 'admin')
            ->get("/admin/ai/sessions/{$conversation->id}/export-pdf")
            ->assertForbidden();
    }
}
