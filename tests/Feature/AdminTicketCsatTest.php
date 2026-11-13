<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Csat;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTicketCsatTest extends TestCase
{
    use RefreshDatabase;

    private Admin $requesterAdmin;
    private Admin $otherAdmin;
    private Unit $unit;
    private SubUnit $subUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requesterAdmin = Admin::create([
            'username' => 'admin_pemohon',
            'email' => 'pemohon@example.com',
            'name' => 'Admin Pemohon',
            'password' => Hash::make('password123'),
        ]);

        $this->otherAdmin = Admin::create([
            'username' => 'admin_unit',
            'email' => 'unit@example.com',
            'name' => 'Admin Unit',
            'password' => Hash::make('password123'),
        ]);

        $this->unit = Unit::create([
            'nama_unit' => 'IT Support',
            'deskripsi' => 'Unit IT Support',
            'aktif' => true,
        ]);

        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Perbaikan Jaringan',
            'deskripsi' => 'Perbaikan Jaringan',
            'aktif' => true,
            'is_revision_enabled' => true,
        ]);
    }

    public function test_admin_can_see_pending_csat_and_rate_own_submitted_ticket_without_matching_user(): void
    {
        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->requesterAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'WiFi mati'],
            'status' => 'solve',
        ]);

        Sanctum::actingAs($this->requesterAdmin, ['*']);

        // 1. Detail ticket identifies admin as requester
        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.isSubmittedByMe', true);

        // 2. Pending CSAT lists the solved admin-submitted ticket
        $this->getJson('/api/ratings/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $ticket->id);

        // 3. Admin submits rating
        $this->postJson("/api/tickets/{$ticket->id}/rate", [
            'rating' => 5,
            'komentar' => 'Penanganan cepat sekali',
        ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.comment', 'Penanganan cepat sekali');

        $this->assertDatabaseHas('csats', [
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'admin_id' => $this->requesterAdmin->id,
            'rating' => 5,
            'komentar' => 'Penanganan cepat sekali',
        ]);

        // 4. Pending CSAT is now empty
        $this->getJson('/api/ratings/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // 5. Submitted rating appears under scope=submitted
        $this->getJson('/api/ratings?scope=submitted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.score', 5);
    }

    public function test_other_admin_cannot_rate_ticket_they_did_not_submit(): void
    {
        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->requesterAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'Printer rusak'],
            'status' => 'solve',
        ]);

        Sanctum::actingAs($this->otherAdmin, ['*']);

        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.isSubmittedByMe', false);

        $this->postJson("/api/tickets/{$ticket->id}/rate", [
            'rating' => 4,
        ])->assertForbidden();

        $this->assertEquals(0, Csat::count());
    }

    public function test_admin_can_rate_ticket_linked_by_matching_user_email_and_request_revision(): void
    {
        $linkedUser = User::factory()->create([
            'username' => $this->requesterAdmin->username,
            'email' => $this->requesterAdmin->email,
        ]);

        $ticket = Ticket::create([
            'user_id' => $linkedUser->id,
            'created_by_admin_id' => null,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'AC bocor'],
            'status' => 'solve',
        ]);

        Sanctum::actingAs($this->requesterAdmin, ['*']);

        $this->postJson("/api/tickets/{$ticket->id}/revision", [
            'catatan' => 'Masih menetes airnya',
        ])->assertOk();

        $ticket->refresh();
        $this->assertEquals('need_revision', $ticket->status);

        $ticket->update(['status' => 'solve']);

        $this->postJson("/api/tickets/{$ticket->id}/rate", [
            'rating' => 5,
        ])->assertCreated();

        $this->assertDatabaseHas('csats', [
            'ticket_id' => $ticket->id,
            'user_id' => $linkedUser->id,
            'admin_id' => $this->requesterAdmin->id,
            'rating' => 5,
        ]);
    }
}
