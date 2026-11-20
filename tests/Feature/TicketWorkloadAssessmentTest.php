<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketWorkloadAssessment;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\TicketWorkloadReviewRequestedNotification;
use App\Services\TicketStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketWorkloadAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Unit $unitA;
    private Unit $unitB;
    private SubUnit $serviceA;
    private SubUnit $serviceB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Koordinator', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'akses-assign-operator', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'akses-laporan', 'guard_name' => 'admin']);
        Role::findByName('Koordinator', 'admin')->syncPermissions(['akses-assign-operator']);

        $this->user = User::factory()->create();
        $this->unitA = Unit::create(['nama_unit' => 'Unit A', 'deskripsi' => 'A', 'aktif' => true]);
        $this->unitB = Unit::create(['nama_unit' => 'Unit B', 'deskripsi' => 'B', 'aktif' => true]);
        $this->serviceA = SubUnit::create(['unit_id' => $this->unitA->id, 'nama_layanan' => 'Layanan A', 'deskripsi' => 'A', 'aktif' => true]);
        $this->serviceB = SubUnit::create(['unit_id' => $this->unitB->id, 'nama_layanan' => 'Layanan B', 'deskripsi' => 'B', 'aktif' => true]);
    }

    private function coordinator(Unit|SubUnit|null $scope = null, string $username = 'koordinator'): Admin
    {
        $admin = Admin::factory()->create(['username' => $username]);
        $admin->assignRole('Koordinator');

        if ($scope instanceof Unit) {
            $admin->units()->attach($scope->id);
        } elseif ($scope instanceof SubUnit) {
            $admin->subUnits()->attach($scope->id);
        }

        return $admin;
    }

    private function ticket(string $status, SubUnit $service, array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_id' => $this->user->id,
            'unit_id' => $service->unit_id,
            'sub_unit_id' => $service->id,
            'form_data' => ['1' => 'Permintaan uji'],
            'status' => $status,
            'priority' => 'normal',
            'source' => 'web',
        ], $overrides));
    }

    public function test_coordinator_saves_and_filters_unassessed_workload(): void
    {
        $coordinator = $this->coordinator($this->unitA);
        $done = $this->ticket('Selesai', $this->serviceA);
        $other = $this->ticket('Selesai', $this->serviceB);
        $running = $this->ticket('on_proses', $this->serviceA);

        $listUrl = route('admin.laporan.tiket', ['status' => 'solve', 'workload' => 'unassessed']);

        $this->actingAs($coordinator, 'admin')
            ->get($listUrl)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Laporan/Tiket')
                ->where('filters.workload', 'unassessed')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $done->id)
                ->where('canExport', false)
                ->where('tickets.data.0.workload_assessment', null)
            );

        $this->assertNotContains($other->id, $this->listIds($coordinator, $listUrl));
        $this->assertNotContains($running->id, $this->listIds($coordinator, $listUrl));

        $this->actingAs($coordinator, 'admin')
            ->from($listUrl)
            ->put(route('admin.laporan.tiket.penilaian-beban', $done->id), [
                'score' => 4,
                'effort_hours' => '18.50',
                'note' => 'Pemasangan bertahap selama tiga hari',
            ])
            ->assertStatus(303)
            ->assertRedirect($listUrl)
            ->assertSessionHas('success', 'Penilaian beban tersimpan.');

        $assessment = TicketWorkloadAssessment::where('ticket_id', $done->id)->sole();
        $this->assertSame(4, $assessment->score);
        $this->assertSame('18.50', $assessment->effort_hours);
        $assessment->forceFill(['legacy_request_count' => 8])->save();
        $this->assertSame($coordinator->id, $assessment->assessed_by_admin_id);
        $this->assertSame('Selesai', $done->fresh()->status);

        $this->actingAs($coordinator, 'admin')
            ->get($listUrl)
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));

        // Correction keeps one row per ticket and clears the optional hours.
        $this->actingAs($coordinator, 'admin')
            ->put(route('admin.laporan.tiket.penilaian-beban', $done->id), [
                'score' => 5,
                'effort_hours' => '',
                'note' => 'Koreksi',
            ])
            ->assertStatus(303);

        $this->assertSame(1, TicketWorkloadAssessment::where('ticket_id', $done->id)->count());
        $this->assertNull($assessment->fresh()->effort_hours);
        $this->assertSame(8, $assessment->fresh()->getRawOriginal('legacy_request_count'));

        $this->actingAs($coordinator, 'admin')
            ->get(route('admin.laporan.tiket', ['status' => 'solve']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.workload_assessment.score', 5)
                ->where('tickets.data.0.workload_assessment.assessor.id', $coordinator->id)
                ->missing('tickets.data.0.workload_assessment.legacy_request_count')
            );
    }

    public function test_workload_count_matches_the_team_queue_across_years(): void
    {
        $coordinator = $this->coordinator($this->unitA);
        $coordinator->givePermissionTo('akses-laporan');
        $otherCoordinator = $this->coordinator($this->serviceB, 'koordinator-layanan-b');
        $older = $this->ticket('Selesai', $this->serviceA);
        $older->forceFill(['created_at' => now()->subYear()])->save();
        $this->ticket('solve', $this->serviceB);
        $this->ticket('open', $this->serviceA);

        $countUrl = route('admin.laporan.tiket.workload-count');
        $queueUrl = route('admin.laporan.tiket', ['workload' => 'unassessed']);

        $this->actingAs($coordinator, 'admin')->getJson($countUrl)->assertExactJson(['count' => 1]);
        $this->get($queueUrl)->assertInertia(fn (Assert $page) => $page
            ->where('tickets.total', 1)
            ->where('tickets.data.0.id', $older->id)
            ->where('totalTickets', 2)
        );
        $this->get(route('admin.laporan.tiket', ['workload' => 'unassessed', 'year' => now()->year]))
            ->assertInertia(fn (Assert $page) => $page->where('tickets.total', 0));

        $this->put(route('admin.laporan.tiket.penilaian-beban', $older->id), ['score' => 4])->assertStatus(303);
        $this->getJson($countUrl)->assertExactJson(['count' => 0]);
        $this->actingAs($otherCoordinator, 'admin')->getJson($countUrl)->assertExactJson(['count' => 1]);
    }

    private function listIds(Admin $admin, string $url): array
    {
        $response = $this->actingAs($admin, 'admin')->get($url)->assertOk();

        return collect($response->viewData('page')['props']['tickets']['data'])->pluck('id')->all();
    }

    public function test_scope_and_validation_are_enforced(): void
    {
        $coordinator = $this->coordinator($this->unitA);
        $unitTicket = $this->ticket('solve', $this->serviceA);
        $outside = $this->ticket('solve', $this->serviceB);

        $this->actingAs($coordinator, 'admin')
            ->put(route('admin.laporan.tiket.penilaian-beban', $outside->id), [
                'score' => 3,
            ])
            ->assertNotFound();

        $this->actingAs($coordinator, 'admin')
            ->get(route('admin.laporan.tiket.export'))
            ->assertForbidden();

        $running = $this->ticket('on_proses', $this->serviceA);
        $this->actingAs($coordinator, 'admin')
            ->put(route('admin.laporan.tiket.penilaian-beban', $running->id), [
                'score' => 3,
            ])
            ->assertSessionHasErrors('score');

        $this->assertSame(0, TicketWorkloadAssessment::count());

        $this->actingAs($coordinator, 'admin')
            ->put(route('admin.laporan.tiket.penilaian-beban', $unitTicket->id), ['score' => 6])
            ->assertSessionHasErrors('score');

        $this->actingAs($coordinator, 'admin')
            ->put(route('admin.laporan.tiket.penilaian-beban', $unitTicket->id), ['score' => 2, 'effort_hours' => '1.234'])
            ->assertSessionHasErrors('effort_hours');

        $this->assertSame(0, TicketWorkloadAssessment::count());

        // Report reader: no assessment relation, no unassessed filter, cannot write.
        Permission::firstOrCreate(['name' => 'akses-laporan', 'guard_name' => 'admin']);
        $reader = Admin::factory()->create(['username' => 'pembaca']);
        $reader->givePermissionTo('akses-laporan');
        $this->ticket('solve', $this->serviceA);
        Permission::where('name', 'akses-assign-operator')->delete();

        $this->actingAs($reader, 'admin')
            ->get(route('admin.laporan.tiket', ['status' => 'solve']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canExport', true)
                ->has('tickets.data', 3)
                ->missing('tickets.data.0.workload_assessment')
            );

        $this->getJson(route('admin.laporan.tiket.workload-count'))->assertForbidden();

        $this->actingAs($reader, 'admin')
            ->get(route('admin.laporan.tiket', ['workload' => 'unassessed']))
            ->assertForbidden();

        $this->actingAs($reader, 'admin')
            ->put(route('admin.laporan.tiket.penilaian-beban', $unitTicket->id), ['score' => 3])
            ->assertForbidden();
        Permission::firstOrCreate(['name' => 'akses-assign-operator', 'guard_name' => 'admin']);
        $assessor = Admin::factory()->create(['username' => 'pemegang-izin-assign']);
        $assessor->givePermissionTo('akses-assign-operator');
        $assessor->units()->attach($this->unitA->id);

        $this->actingAs($assessor, 'admin')
            ->get(route('admin.laporan.tiket', ['workload' => 'unassessed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can_assess_ticket_workload', true)
                ->has('tickets.data', 2)
            );

        $this->actingAs($assessor, 'admin')
            ->put(route('admin.laporan.tiket.penilaian-beban', $unitTicket->id), ['score' => 3])
            ->assertStatus(303);
    }

    public function test_username_matching_superadmin_does_not_gain_scope(): void
    {
        $spoof = Admin::factory()->create(['username' => 'superadmin-tiruan']);
        $spoof->assignRole('Koordinator');

        $outside = $this->ticket('solve', $this->serviceB);

        $this->actingAs($spoof, 'admin')
            ->get(route('admin.laporan.tiket', ['status' => 'solve']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));

        $this->actingAs($spoof, 'admin')
            ->put(route('admin.laporan.tiket.penilaian-beban', $outside->id), ['score' => 3])
            ->assertNotFound();
    }

    public function test_client_cannot_bypass_last_page_of_unassessed_filter(): void
    {
        $coordinator = $this->coordinator($this->unitA);

        for ($i = 0; $i < 16; $i++) {
            $ticket = $this->ticket('solve', $this->serviceA);
            $ticket->workloadAssessment()->create([
                'assessed_by_admin_id' => $coordinator->id,
                'score' => 3,
            ]);
        }

        $this->actingAs($coordinator, 'admin')
            ->get(route('admin.laporan.tiket', ['workload' => 'unassessed', 'page' => 2]))
            ->assertRedirect(route('admin.laporan.tiket', ['workload' => 'unassessed', 'page' => 1]));
    }

    public function test_completion_notifies_only_matching_coordinators_once(): void
    {
        Notification::fake();

        $operator = Admin::factory()->create(['username' => 'operator-a']);
        $operator->assignRole('Operator');
        $operator->units()->attach($this->unitA->id);

        $coordinatorUnit = $this->coordinator($this->unitA, 'koordinator-unit');
        $permissionOnly = Admin::factory()->create(['username' => 'koordinator-izin']);
        $permissionOnly->assignRole('Koordinator');
        $permissionOnly->subUnits()->attach($this->serviceA->id);
        $otherCoordinator = $this->coordinator($this->unitB, 'koordinator-lain');

        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        $superAdmin = Admin::factory()->create(['username' => 'superadmin']);
        $superAdmin->assignRole('superadmin');

        $ticket = $this->ticket('on_proses', $this->serviceA, ['assigned_admin_id' => $operator->id]);

        TicketStatusService::applyTransition($ticket->fresh(), 'solve', 'Selesai dikerjakan', $operator);

        $assertPayload = function (TicketWorkloadReviewRequestedNotification $notification): bool {
            $payload = $notification->toArray(new Admin);

            return $payload['type'] === 'ticket_workload_review_requested'
                && $payload['title'] === 'Tiket selesai perlu dinilai'
                && $payload['url'] === $payload['aksi_url']
                && str_ends_with($payload['url'], '#rincian-tiket')
                && str_contains($payload['url'], 'year=')
                && str_contains($payload['url'], 'search='.$payload['ticket_id']);
        };

        Notification::assertSentTo($coordinatorUnit, TicketWorkloadReviewRequestedNotification::class, $assertPayload);
        Notification::assertSentTo($permissionOnly, TicketWorkloadReviewRequestedNotification::class, $assertPayload);
        Notification::assertNotSentTo($otherCoordinator, TicketWorkloadReviewRequestedNotification::class);
        Notification::assertNotSentTo($operator, TicketWorkloadReviewRequestedNotification::class);
        Notification::assertNotSentTo($superAdmin, TicketWorkloadReviewRequestedNotification::class);

        // A second solve request must not resend while the assessment is missing.
        TicketStatusService::applyTransition($ticket->fresh(), 'solve', 'Selesai lagi', $operator);
        Notification::assertSentTimes(TicketWorkloadReviewRequestedNotification::class, 2);
    }

    public function test_superadmin_fallback_when_no_coordinator_matches(): void
    {
        Notification::fake();

        $operator = Admin::factory()->create(['username' => 'operator-b']);
        $operator->assignRole('Operator');
        $operator->units()->attach($this->unitB->id);

        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        $superAdmin = Admin::factory()->create(['username' => 'superadmin-cadangan']);
        $superAdmin->assignRole('superadmin');

        $ticket = $this->ticket('on_proses', $this->serviceA, ['assigned_admin_id' => $operator->id]);

        TicketStatusService::applyTransition($ticket->fresh(), 'solve', null, $operator);

        Notification::assertSentTo($superAdmin, TicketWorkloadReviewRequestedNotification::class);
        $unassigned = $this->ticket('Selesai', $this->serviceA);
        $this->actingAs($superAdmin, 'admin')
            ->put(route('admin.laporan.tiket.penilaian-beban', $unassigned->id), [
                'score' => 4,
            ])
            ->assertStatus(303);

        $this->assertDatabaseHas('ticket_workload_assessments', [
            'ticket_id' => $unassigned->id,
            'assessed_by_admin_id' => $superAdmin->id,
        ]);
    }

    public function test_assessed_ticket_does_not_trigger_a_new_notification(): void
    {
        Notification::fake();

        $coordinator = $this->coordinator($this->unitA, 'koordinator-terkait');
        $ticket = $this->ticket('need_revision', $this->serviceA);

        $ticket->workloadAssessment()->create([
            'assessed_by_admin_id' => $coordinator->id,
            'score' => 3,
        ]);

        TicketStatusService::applyTransition($ticket->fresh(), 'solve', null, $coordinator);

        Notification::assertNotSentTo($coordinator, TicketWorkloadReviewRequestedNotification::class);
    }
}
