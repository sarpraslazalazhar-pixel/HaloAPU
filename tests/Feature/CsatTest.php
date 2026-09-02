<?php

namespace Tests\Feature;

use App\Models\Csat;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsatTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Unit $unit;
    private SubUnit $subUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->unit = Unit::create([
            'nama_unit' => 'IT Support',
            'deskripsi' => 'Unit IT Support',
            'aktif' => true,
        ]);

        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Jaringan dan Internet',
            'deskripsi' => 'Jaringan dan Internet',
            'aktif' => true,
        ]);
    }

    /**
     * Helper to create a ticket.
     */
    private function createTicket(array $attributes = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => [],
            'status' => 'open',
        ], $attributes));
    }

    /**
     * User can submit rating successfully for their own ticket in 'Solve' or 'Selesai' status.
     */
    public function test_user_can_submit_rating_for_own_ticket_in_solve_or_selesai_status(): void
    {
        $ticketSolve = $this->createTicket(['status' => 'solve']);
        $ticketSelesai = $this->createTicket(['status' => 'selesai']);

        // Post rating for Solve ticket
        $responseSolve = $this->actingAs($this->user)
            ->post(route('csat.store', $ticketSolve), [
                'rating' => 5,
                'komentar' => 'Sangat cepat dan memuaskan',
            ]);

        $responseSolve->assertRedirect();
        $responseSolve->assertSessionHasNoErrors();
        $this->assertDatabaseHas('csats', [
            'ticket_id' => $ticketSolve->id,
            'user_id' => $this->user->id,
            'rating' => 5,
            'komentar' => 'Sangat cepat dan memuaskan',
        ]);
        // After rating Solve ticket, status should change to Selesai
        $this->assertEquals('Selesai', $ticketSolve->fresh()->status);

        // Post rating for Selesai ticket
        $responseSelesai = $this->actingAs($this->user)
            ->post(route('csat.store', $ticketSelesai), [
                'rating' => 4,
                'komentar' => 'Cukup memuaskan',
            ]);

        $responseSelesai->assertRedirect();
        $responseSelesai->assertSessionHasNoErrors();
        $this->assertDatabaseHas('csats', [
            'ticket_id' => $ticketSelesai->id,
            'user_id' => $this->user->id,
            'rating' => 4,
            'komentar' => 'Cukup memuaskan',
        ]);
    }

    /**
     * User cannot submit rating for non-owned tickets.
     */
    public function test_user_cannot_submit_rating_for_non_owned_tickets(): void
    {
        $otherUser = User::factory()->create();
        $otherTicket = $this->createTicket([
            'user_id' => $otherUser->id,
            'status' => 'solve',
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('csat.store', $otherTicket), [
                'rating' => 5,
                'komentar' => 'Nice',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('csats', [
            'ticket_id' => $otherTicket->id,
        ]);
    }

    /**
     * User cannot submit rating for tickets with other statuses.
     */
    public function test_user_cannot_submit_rating_for_tickets_with_other_statuses(): void
    {
        $ticketOpen = $this->createTicket(['status' => 'open']);
        $ticketPending = $this->createTicket(['status' => 'pending']);
        $ticketOnProses = $this->createTicket(['status' => 'on_proses']);
        $ticketReject = $this->createTicket(['status' => 'reject']);

        foreach ([$ticketOpen, $ticketPending, $ticketOnProses, $ticketReject] as $ticket) {
            $response = $this->actingAs($this->user)
                ->post(route('csat.store', $ticket), [
                    'rating' => 5,
                    'komentar' => 'Good',
                ]);

            $response->assertSessionHasErrors(['rating']);
            $this->assertDatabaseMissing('csats', [
                'ticket_id' => $ticket->id,
            ]);
        }
    }

    /**
     * User cannot submit rating twice for the same ticket.
     */
    public function test_user_cannot_submit_rating_twice_for_same_ticket(): void
    {
        $ticket = $this->createTicket(['status' => 'solve']);

        // First submit
        $response1 = $this->actingAs($this->user)
            ->post(route('csat.store', $ticket), [
                'rating' => 5,
                'komentar' => 'First rate',
            ]);
        $response1->assertSessionHasNoErrors();

        // Second submit
        $response2 = $this->actingAs($this->user)
            ->post(route('csat.store', $ticket), [
                'rating' => 4,
                'komentar' => 'Second rate',
            ]);

        $response2->assertSessionHasErrors(['rating']);
        $this->assertEquals(1, Csat::where('ticket_id', $ticket->id)->count());
    }

    /**
     * Validation rules are enforced (rating 1-5, komentar max 1000).
     */
    public function test_rating_validation_rules_are_enforced(): void
    {
        $ticket = $this->createTicket(['status' => 'solve']);

        // Rating out of bounds (0 or 6)
        $responseMin = $this->actingAs($this->user)
            ->post(route('csat.store', $ticket), [
                'rating' => 0,
            ]);
        $responseMin->assertSessionHasErrors(['rating']);

        $responseMax = $this->actingAs($this->user)
            ->post(route('csat.store', $ticket), [
                'rating' => 6,
            ]);
        $responseMax->assertSessionHasErrors(['rating']);

        // Rating is negative
        $responseNeg = $this->actingAs($this->user)
            ->post(route('csat.store', $ticket), [
                'rating' => -1,
            ]);
        $responseNeg->assertSessionHasErrors(['rating']);

        // Rating is null/missing
        $responseNull = $this->actingAs($this->user)
            ->post(route('csat.store', $ticket), [
                'rating' => null,
            ]);
        $responseNull->assertSessionHasErrors(['rating']);

        // Rating is non-integer string
        $responseStr = $this->actingAs($this->user)
            ->post(route('csat.store', $ticket), [
                'rating' => 'five',
            ]);
        $responseStr->assertSessionHasErrors(['rating']);

        // Rating is decimal/float
        $responseDec = $this->actingAs($this->user)
            ->post(route('csat.store', $ticket), [
                'rating' => 3.5,
            ]);
        $responseDec->assertSessionHasErrors(['rating']);

        // Comment exactly 1000 characters (should pass)
        $ticket2 = $this->createTicket(['status' => 'solve']);
        $responseComment1000 = $this->actingAs($this->user)
            ->post(route('csat.store', $ticket2), [
                'rating' => 5,
                'komentar' => str_repeat('a', 1000),
            ]);
        $responseComment1000->assertSessionHasNoErrors();

        // Comment too long (1001 characters)
        $ticket3 = $this->createTicket(['status' => 'solve']);
        $responseComment1001 = $this->actingAs($this->user)
            ->post(route('csat.store', $ticket3), [
                'rating' => 5,
                'komentar' => str_repeat('a', 1001),
            ]);
        $responseComment1001->assertSessionHasErrors(['komentar']);
    }

    /**
     * Status case insensitivity checks (e.g., ticket status is 'solve' or 'SOLVE' or 'Selesai').
     */
    public function test_status_case_insensitivity(): void
    {
        $statuses = ['SOLVE', 'Selesai', 'SELESAI', 'sOlVe', 'seLESAi'];

        foreach ($statuses as $status) {
            $ticket = $this->createTicket(['status' => $status]);

            $response = $this->actingAs($this->user)
                ->post(route('csat.store', $ticket), [
                    'rating' => 5,
                    'komentar' => 'Bagus',
                ]);

            $response->assertRedirect();
            $response->assertSessionHasNoErrors();
            $this->assertDatabaseHas('csats', [
                'ticket_id' => $ticket->id,
                'user_id' => $this->user->id,
                'rating' => 5,
            ]);
            $this->assertEquals('Selesai', $ticket->fresh()->status);
        }
    }

    /**
     * Database level unique constraint prevents duplicate CSAT for the same ticket.
     */
    public function test_database_enforces_unique_csat_per_ticket(): void
    {
        $ticket = $this->createTicket(['status' => 'solve']);

        Csat::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'rating' => 5,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Csat::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'rating' => 4,
        ]);
    }

    /**
     * CSAT reminder command fails to send notifications for 'solve' or 'SOLVE' status because of case sensitivity.
     */
    public function test_csat_reminder_command_casing_issue(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('notifications')) {
            \Illuminate\Support\Facades\Schema::create('notifications', function ($table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        \Illuminate\Support\Facades\Notification::fake();

        \App\Models\ReminderConfig::create([
            'jenis_reminder' => 'csat',
            'lead_time_value' => 2,
            'channel_aktif' => ['database'],
            'aktif' => true,
        ]);

        // Create tickets with different solve casings, updated 3 days ago (beyond 2 days cutoff)
        $ticketSolve1 = $this->createTicket(['status' => 'solve']);
        $ticketSolve1->updated_at = now()->subDays(3);
        $ticketSolve1->save();

        $ticketSolve2 = $this->createTicket(['status' => 'Solve']);
        $ticketSolve2->updated_at = now()->subDays(3);
        $ticketSolve2->save();

        $ticketSolve3 = $this->createTicket(['status' => 'SOLVE']);
        $ticketSolve3->updated_at = now()->subDays(3);
        $ticketSolve3->save();

        $this->artisan('reminder:csat')
            ->assertSuccessful();

        // Let's assert notification was sent for 'Solve' ticket
        \Illuminate\Support\Facades\Notification::assertSentTo($this->user, \App\Notifications\CsatReminderNotification::class, function ($notification) use ($ticketSolve2) {
            return $notification->toDatabase($this->user)['ticket_id'] === $ticketSolve2->id;
        });

        // Let's verify that 'solve' (lowercase) gets notification now because of case-insensitivity
        \Illuminate\Support\Facades\Notification::assertSentTo($this->user, \App\Notifications\CsatReminderNotification::class, function ($notification) use ($ticketSolve1) {
            return $notification->toDatabase($this->user)['ticket_id'] === $ticketSolve1->id;
        });

        // Let's verify that 'SOLVE' (uppercase) gets notification now because of case-insensitivity
        \Illuminate\Support\Facades\Notification::assertSentTo($this->user, \App\Notifications\CsatReminderNotification::class, function ($notification) use ($ticketSolve3) {
            return $notification->toDatabase($this->user)['ticket_id'] === $ticketSolve3->id;
        });
    }
}
