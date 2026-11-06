<?php

namespace Tests\Feature;

use App\Models\FormField;
use App\Models\SubUnit;
use App\Models\SystemConfig;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\User;
use App\Services\SparcIntegrationService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SparcIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Unit $humasUnit;

    protected SubUnit $videoSubUnit;

    protected SubUnit $desainSubUnit;

    protected FormField $videoField;

    protected FormField $desainField;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);

        $this->humasUnit = Unit::create([
            'nama_unit' => 'Humas',
            'deskripsi' => 'Urusan Hubungan Masyarakat',
            'aktif' => true,
        ]);

        $this->videoSubUnit = SubUnit::create([
            'unit_id' => $this->humasUnit->id,
            'nama_layanan' => 'Editing & Produksi Video',
            'aktif' => true,
            'is_revision_enabled' => true,
        ]);

        $this->desainSubUnit = SubUnit::create([
            'unit_id' => $this->humasUnit->id,
            'nama_layanan' => 'Pengajuan Desain Grafis',
            'aktif' => true,
            'is_revision_enabled' => true,
        ]);

        $this->videoField = FormField::create([
            'sub_unit_id' => $this->videoSubUnit->id,
            'label' => 'Jenis Video',
            'tipe_field' => 'dropdown',
            'wajib' => true,
            'opsi' => ['Event,Profile', 'Reels', 'Motion Graphic'],
            'urutan' => 1,
        ]);

        $this->desainField = FormField::create([
            'sub_unit_id' => $this->desainSubUnit->id,
            'label' => 'Jenis Produksi Desain',
            'tipe_field' => 'dropdown',
            'wajib' => true,
            'opsi' => ['Feed Instagram / Meme', 'Spanduk', 'Meme Al-Kisah'],
            'urutan' => 1,
        ]);

        $this->user = User::create([
            'name' => 'Budi Pemohon',
            'username' => 'budi_pemohon',
            'email' => 'budi@example.com',
            'password' => bcrypt('password'),
            'no_wa' => '081234567890',
        ]);
    }

    public function test_filter_matches_reels_ticket(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->humasUnit->id,
            'sub_unit_id' => $this->videoSubUnit->id,
            'form_data' => [(string) $this->videoField->id => 'Reels'],
            'status' => 'open',
        ]);

        $service = app(SparcIntegrationService::class);
        $this->assertSame('video_reels', $service->matchesSparcFilter($ticket));
    }

    public function test_filter_matches_meme_ticket(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->humasUnit->id,
            'sub_unit_id' => $this->desainSubUnit->id,
            'form_data' => [(string) $this->desainField->id => 'Feed Instagram / Meme'],
            'status' => 'open',
        ]);

        $service = app(SparcIntegrationService::class);
        $this->assertSame('desain_feed', $service->matchesSparcFilter($ticket));
    }

    public function test_filter_rejects_non_matching_ticket(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->humasUnit->id,
            'sub_unit_id' => $this->desainSubUnit->id,
            'form_data' => [(string) $this->desainField->id => 'Spanduk'],
            'status' => 'open',
        ]);

        $service = app(SparcIntegrationService::class);
        $this->assertNull($service->matchesSparcFilter($ticket));
    }

    public function test_api_requires_valid_token(): void
    {
        SystemConfig::setValue('sparc_api_token', 'super-secret-token');

        $this->getJson('/api/integrations/sparc/tickets')->assertStatus(401);

        $this->withHeaders(['Authorization' => 'Bearer wrong-token'])
            ->getJson('/api/integrations/sparc/tickets')
            ->assertStatus(401);

        $this->withHeaders(['Authorization' => 'Bearer super-secret-token'])
            ->getJson('/api/integrations/sparc/tickets')
            ->assertOk();
    }

    public function test_status_on_proses_records_responded_at(): void
    {
        SystemConfig::setValue('sparc_api_token', 'valid-token');

        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->humasUnit->id,
            'sub_unit_id' => $this->videoSubUnit->id,
            'form_data' => [(string) $this->videoField->id => 'Reels'],
            'status' => 'open',
        ]);

        $sla = TicketSlaTracking::create([
            'ticket_id' => $ticket->id,
            'sla_response_deadline' => now()->addHours(2),
            'sla_resolution_deadline' => now()->addHours(24),
            'current_tier' => 0,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->postJson("/api/integrations/sparc/tickets/{$ticket->id}/status", [
                'status' => 'on_proses',
                'catatan' => 'Mulai diproses tim Humas SPARC',
            ]);

        $response->assertOk();

        $this->assertSame('on_proses', $ticket->fresh()->status);
        $this->assertNotNull($sla->fresh()->responded_at);
    }

    public function test_status_solve_records_resolved_at(): void
    {
        SystemConfig::setValue('sparc_api_token', 'valid-token');

        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->humasUnit->id,
            'sub_unit_id' => $this->videoSubUnit->id,
            'form_data' => [(string) $this->videoField->id => 'Reels'],
            'status' => 'on_proses',
        ]);

        $sla = TicketSlaTracking::create([
            'ticket_id' => $ticket->id,
            'sla_response_deadline' => now()->addHours(2),
            'sla_resolution_deadline' => now()->addHours(24),
            'responded_at' => now()->subHour(),
            'current_tier' => 0,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer valid-token'])
            ->postJson("/api/integrations/sparc/tickets/{$ticket->id}/status", [
                'status' => 'solve',
                'catatan' => 'Konten selesai',
                'deliverable_url' => 'https://instagram.com/reel/123456',
            ]);

        $response->assertOk();

        $this->assertSame('solve', $ticket->fresh()->status);
        $this->assertNotNull($sla->fresh()->resolved_at);
        $this->assertFalse((bool) $ticket->fresh()->is_result_accepted);
    }

    public function test_revision_request_dispatches_webhook(): void
    {
        Http::fake([
            'http://sparc.test/api/webhooks/haloapu' => Http::response(['message' => 'ok'], 200),
        ]);

        SystemConfig::setValue('sparc_integration_enabled', true);
        SystemConfig::setValue('sparc_base_url', 'http://sparc.test');
        SystemConfig::setValue('sparc_webhook_secret', 'test-secret');

        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->humasUnit->id,
            'sub_unit_id' => $this->videoSubUnit->id,
            'form_data' => [(string) $this->videoField->id => 'Reels'],
            'status' => 'solve',
            'revision_count' => 0,
        ]);

        $this->actingAs($this->user)
            ->post(route('tiket.request-revision', $ticket->id), [
                'catatan' => 'Mohon revisi audio dubbing agar lebih jernih.',
            ])
            ->assertRedirect();

        $this->assertSame('need_revision', $ticket->fresh()->status);
        $this->assertSame(1, $ticket->fresh()->revision_count);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/webhooks/haloapu')
                && $request->header('X-HaloAPU-Event')[0] === 'ticket.revision_requested';
        });
    }

    public function test_cancel_dispatches_webhook(): void
    {
        Http::fake([
            'http://sparc.test/api/webhooks/haloapu' => Http::response(['message' => 'ok'], 200),
        ]);

        SystemConfig::setValue('sparc_integration_enabled', true);
        SystemConfig::setValue('sparc_base_url', 'http://sparc.test');
        SystemConfig::setValue('sparc_webhook_secret', 'test-secret');

        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->humasUnit->id,
            'sub_unit_id' => $this->videoSubUnit->id,
            'form_data' => [(string) $this->videoField->id => 'Reels'],
            'status' => 'open',
        ]);

        $this->actingAs($this->user)
            ->patch(route('tiket.batal', $ticket->id))
            ->assertRedirect();

        $this->assertSame('dibatalkan', $ticket->fresh()->status);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/webhooks/haloapu')
                && $request->header('X-HaloAPU-Event')[0] === 'ticket.cancelled';
        });
    }
}
