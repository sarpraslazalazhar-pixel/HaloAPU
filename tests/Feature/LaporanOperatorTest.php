<?php

namespace Tests\Feature;

use App\Exports\LaporanKinerjaOperatorExport;
use App\Models\Admin;
use App\Models\Csat;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LaporanOperatorTest extends TestCase
{
    use RefreshDatabase;

    private Admin $viewer;

    private User $user;

    private Unit $unitA;

    private Unit $unitB;

    private SubUnit $subUnitA;

    private SubUnit $subUnitB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Koordinator', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'akses-laporan', 'guard_name' => 'admin']);

        $this->viewer = Admin::factory()->create([
            'name' => 'Super Administrator',
            'username' => 'superadmin',
            'email' => 'superadmin@example.com',
        ]);
        $this->viewer->assignRole('superadmin');
        $this->viewer->givePermissionTo('akses-laporan');

        $this->user = User::factory()->create([
            'name' => 'Pemohon Uji',
            'username' => 'pemohon_uji',
        ]);

        $this->unitA = Unit::create(['nama_unit' => 'Unit IT', 'deskripsi' => 'IT', 'aktif' => true]);
        $this->unitB = Unit::create(['nama_unit' => 'Unit Umum', 'deskripsi' => 'Umum', 'aktif' => true]);

        $this->subUnitA = SubUnit::create([
            'unit_id' => $this->unitA->id,
            'nama_layanan' => 'Jaringan',
            'deskripsi' => 'Jaringan',
            'aktif' => true,
        ]);
        $this->subUnitB = SubUnit::create([
            'unit_id' => $this->unitB->id,
            'nama_layanan' => 'Kendaraan',
            'deskripsi' => 'Kendaraan',
            'aktif' => true,
        ]);
    }

    private function createOperator(string $name, string $username, string $role = 'Operator'): Admin
    {
        $admin = Admin::factory()->create([
            'name' => $name,
            'username' => $username,
            'email' => "{$username}@example.com",
        ]);
        $admin->assignRole($role);

        return $admin;
    }

    private function createTicket(array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_id' => $this->user->id,
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'form_data' => ['Keluhan' => 'Tiket uji'],
            'status' => 'open',
            'priority' => 'normal',
            'source' => 'web',
        ], $overrides));
    }

    private function loadExportSpreadsheet(array $filters = []): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $export = new LaporanKinerjaOperatorExport($filters, $this->viewer);
        $raw = Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX);

        $tmpFile = tempnam(sys_get_temp_dir(), 'laporan_op_');
        file_put_contents($tmpFile, $raw);

        try {
            $spreadsheet = IOFactory::load($tmpFile);

            return $spreadsheet->getActiveSheet();
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_reassignment_attributes_active_ticket_only_to_last_assigned_operator_and_excludes_unassigned(): void
    {
        $opA = $this->createOperator('Operator A', 'op_a');
        $opB = $this->createOperator('Operator B', 'op_b');

        $ticket = $this->createTicket([
            'status' => 'on_proses',
            'assigned_admin_id' => $opA->id,
        ]);

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $this->viewer->id,
            'aksi' => 'assign_operator',
            'catatan' => 'Ditugaskan ke Operator A',
        ]);

        // Reassigned from A -> B
        $ticket->update(['assigned_admin_id' => $opB->id]);
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $this->viewer->id,
            'aksi' => 'assign_operator',
            'catatan' => 'Dialihkan ke Operator B',
        ]);

        // Unassigned active ticket should be excluded from all operators
        $this->createTicket([
            'status' => 'open',
            'assigned_admin_id' => null,
        ]);

        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Laporan/KinerjaOperator')
                ->where('summary.totalActive', 1)
                ->where('summary.totalAssigned', 1)
                ->where('summary.activeOperators', 1)
                ->where('operators.0.id', $opB->id)
                ->where('operators.0.active_tickets', 1)
                ->where('operators.0.total_tickets', 1)
                ->where('operators.1.id', $opA->id)
                ->where('operators.1.active_tickets', 0)
                ->where('operators.1.total_tickets', 0)
            );
    }

    public function test_unassigned_ticket_solved_by_superadmin_is_credited_across_web_ulasan_pdf_and_excel(): void
    {
        $idleOp = $this->createOperator('Operator Pertama', 'operator1');

        $ticket = $this->createTicket([
            'status' => 'solve',
            'assigned_admin_id' => null,
        ]);

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $this->viewer->id,
            'aksi' => 'solve',
            'catatan' => 'Diselesaikan oleh Super Administrator',
        ]);

        TicketSlaTracking::create([
            'ticket_id' => $ticket->id,
            'resolved_at' => now(),
            'is_resolution_breached' => false,
            'is_response_breached' => false,
        ]);

        Csat::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'rating' => 5,
            'komentar' => 'Sangat cepat',
        ]);

        // 1. Web index includes Super Administrator automatically and ranks them above idle Operator Pertama
        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.totalOperators', 2)
                ->where('summary.activeOperators', 1)
                ->where('summary.totalSolved', 1)
                ->where('summary.totalReviews', 1)
                ->where('summary.avgCompliance', 100)
                ->where('summary.overallAvgRating', 5)
                ->where('operators.0.id', $this->viewer->id)
                ->where('operators.0.solved_tickets', 1)
                ->where('operators.0.resolution_compliance', 100)
                ->where('operators.0.avg_rating', 5)
                ->where('operators.1.id', $idleOp->id)
                ->where('operators.1.solved_tickets', 0)
                ->where('operators.1.resolution_compliance', null)
                ->has('workloadChartData', 1)
                ->where('workloadChartData.0.name', 'Super Administrator')
                ->where('workloadChartData.0.solved', 1)
                ->has('performanceChartData', 1)
                ->where('performanceChartData.0.name', 'Super Administrator')
            );

        // 2. Ulasan credits resolver (Super Administrator), not idle operator
        $this->actingAs($this->viewer, 'admin')
            ->getJson(route('admin.laporan.operator.ulasan', $this->viewer->id))
            ->assertOk()
            ->assertJsonCount(1, 'reviews')
            ->assertJsonPath('reviews.0.ticket_raw_id', $ticket->id)
            ->assertJsonPath('reviews.0.rating', 5);

        $this->actingAs($this->viewer, 'admin')
            ->getJson(route('admin.laporan.operator.ulasan', $idleOp->id))
            ->assertOk()
            ->assertJsonCount(0, 'reviews');

        // 3. Export PDF returns valid PDF
        $pdfResponse = $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator.export-pdf'));
        $pdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdfResponse->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $pdfResponse->getContent());

        // 4. Excel export includes Super Administrator first with solved=1 and idle operator second with '-' SLA
        $sheet = $this->loadExportSpreadsheet();
        $this->assertSame('Kinerja Individu', $sheet->getTitle());
        $this->assertSame('LAPORAN BEBAN & KINERJA INDIVIDU', $sheet->getCell('A1')->getValue());
        $this->assertSame('Nama Individu', $sheet->getCell('B6')->getValue());
        $this->assertSame('Total 2 individu', $sheet->getCell('B10')->getValue());
        $this->assertStringContainsString('HaloAPU - Laporan Kinerja Individu', $sheet->getHeaderFooter()->getOddFooter());

        $this->assertSame('Super Administrator', $sheet->getCell('B7')->getValue());
        $this->assertNotNull($sheet->getCell('G7')->getValue());
        $this->assertSame(0, (int) $sheet->getCell('G7')->getValue());
        $this->assertSame(1, (int) $sheet->getCell('H7')->getValue());
        $this->assertEquals(100, $sheet->getCell('K7')->getValue());
        $this->assertSame('0.0"%"', $sheet->getStyle('K7')->getNumberFormat()->getFormatCode());

        $this->assertSame('Operator Pertama', $sheet->getCell('B8')->getValue());
        $this->assertNotNull($sheet->getCell('G8')->getValue());
        $this->assertNotNull($sheet->getCell('H8')->getValue());
        $this->assertNotNull($sheet->getCell('I8')->getValue());
        $this->assertNotNull($sheet->getCell('J8')->getValue());
        $this->assertNotNull($sheet->getCell('L8')->getValue());
        $this->assertNotNull($sheet->getCell('N8')->getValue());
        $this->assertSame(0, (int) $sheet->getCell('H8')->getValue());
        $this->assertSame(0, (int) $sheet->getCell('J8')->getValue());
        $this->assertSame('-', $sheet->getCell('K8')->getValue());
        $this->assertNotSame('0.0"%"', $sheet->getStyle('K8')->getNumberFormat()->getFormatCode());
    }

    public function test_assigned_to_a_solved_by_b_and_multiple_solves_credits_latest_resolver(): void
    {
        $opA = $this->createOperator('Operator A', 'op_a');
        $opB = $this->createOperator('Operator B', 'op_b');
        $opC = $this->createOperator('Operator C', 'op_c');

        // Ticket 1: assigned to A, solved by B, rated by user -> status becomes 'Selesai' (capital S)
        $ticket1 = $this->createTicket([
            'status' => 'Selesai',
            'assigned_admin_id' => $opA->id,
        ]);
        TicketLog::create([
            'ticket_id' => $ticket1->id,
            'admin_id' => $opB->id,
            'aksi' => 'solve',
            'catatan' => 'Diselesaikan B',
        ]);
        TicketSlaTracking::create([
            'ticket_id' => $ticket1->id,
            'resolved_at' => now(),
            'is_resolution_breached' => true,
        ]);
        Csat::create([
            'ticket_id' => $ticket1->id,
            'user_id' => $this->user->id,
            'rating' => 4,
            'komentar' => 'Baik',
        ]);

        // Ticket 2: assigned to A, first solved by B, reopened, then finally solved by C -> latest resolver C wins
        $ticket2 = $this->createTicket([
            'status' => 'solve',
            'assigned_admin_id' => $opA->id,
        ]);
        TicketLog::create([
            'ticket_id' => $ticket2->id,
            'admin_id' => $opB->id,
            'aksi' => 'solve',
            'catatan' => 'Solve pertama oleh B',
        ]);
        TicketLog::create([
            'ticket_id' => $ticket2->id,
            'admin_id' => null,
            'aksi' => 'need_revision',
            'catatan' => 'Revisi user',
        ]);
        TicketLog::create([
            'ticket_id' => $ticket2->id,
            'admin_id' => $opC->id,
            'aksi' => 'solve',
            'catatan' => 'Solve terakhir oleh C',
        ]);
        TicketSlaTracking::create([
            'ticket_id' => $ticket2->id,
            'resolved_at' => now(),
            'is_resolution_breached' => false,
        ]);

        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($opA, $opB, $opC) {
                $operators = collect($page->toArray()['props']['operators'])->keyBy('id');

                $this->assertSame(0, $operators[$opA->id]['solved_tickets']);
                $this->assertSame(0, $operators[$opA->id]['total_tickets']);
                $this->assertNull($operators[$opA->id]['resolution_compliance']);

                $this->assertSame(1, $operators[$opB->id]['solved_tickets']);
                $this->assertSame(1, $operators[$opB->id]['resolution_breaches']);
                $this->assertEquals(0.0, $operators[$opB->id]['resolution_compliance']);
                $this->assertEquals(4.0, $operators[$opB->id]['avg_rating']);
                $this->assertSame(1, $operators[$opB->id]['total_reviews']);

                $this->assertSame(1, $operators[$opC->id]['solved_tickets']);
                $this->assertSame(0, $operators[$opC->id]['resolution_breaches']);
                $this->assertEquals(100.0, $operators[$opC->id]['resolution_compliance']);
                $this->assertNull($operators[$opC->id]['avg_rating']);
            });

        // Ulasan goes to B (resolver of ticket1), NOT A (assigned_admin_id)
        $this->actingAs($this->viewer, 'admin')
            ->getJson(route('admin.laporan.operator.ulasan', $opB->id))
            ->assertOk()
            ->assertJsonCount(1, 'reviews')
            ->assertJsonPath('reviews.0.ticket_raw_id', $ticket1->id);

        $this->actingAs($this->viewer, 'admin')
            ->getJson(route('admin.laporan.operator.ulasan', $opA->id))
            ->assertOk()
            ->assertJsonCount(0, 'reviews');
    }

    public function test_solved_ticket_without_solve_log_falls_back_to_assigned_admin_and_rejected_attribution_works(): void
    {
        $opA = $this->createOperator('Operator A', 'op_a');
        $opB = $this->createOperator('Operator B', 'op_b');

        // Solved ticket with NO solve log -> fallback to assigned_admin_id (opA)
        $this->createTicket([
            'status' => 'Selesai',
            'assigned_admin_id' => $opA->id,
        ]);

        // Rejected ticket assigned to A, rejected by B -> credited to B
        $rejectedByB = $this->createTicket([
            'status' => 'reject',
            'assigned_admin_id' => $opA->id,
        ]);
        TicketLog::create([
            'ticket_id' => $rejectedByB->id,
            'admin_id' => $opB->id,
            'aksi' => 'reject',
            'catatan' => 'Ditolak oleh B',
        ]);

        // User-cancelled ticket (admin_id null in log) with assigned_admin_id = opA -> fallback to opA
        $cancelledByUser = $this->createTicket([
            'status' => 'dibatalkan',
            'assigned_admin_id' => $opA->id,
        ]);
        TicketLog::create([
            'ticket_id' => $cancelledByUser->id,
            'admin_id' => null,
            'aksi' => 'dibatalkan',
            'catatan' => 'Dibatalkan user',
        ]);

        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($opA, $opB) {
                $operators = collect($page->toArray()['props']['operators'])->keyBy('id');

                $this->assertSame(1, $operators[$opA->id]['solved_tickets']);
                $this->assertSame(1, $operators[$opA->id]['rejected_tickets']);
                $this->assertSame(2, $operators[$opA->id]['total_tickets']);

                $this->assertSame(0, $operators[$opB->id]['solved_tickets']);
                $this->assertSame(1, $operators[$opB->id]['rejected_tickets']);
                $this->assertSame(1, $operators[$opB->id]['total_tickets']);
            });
    }

    public function test_zero_ticket_operators_excluded_from_charts_and_null_sla_rendered_as_dash(): void
    {
        $this->createOperator('Operator Kosong', 'op_kosong');

        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.totalOperators', 1)
                ->where('summary.activeOperators', 0)
                ->where('summary.avgCompliance', null)
                ->where('operators.0.resolution_compliance', null)
                ->where('workloadChartData', [])
                ->where('performanceChartData', [])
            );

        // Verify Blade PDF view renders '-' and fixed title when avgCompliance & resolution_compliance are null
        $report = app(\App\Services\OperatorPerformanceReportService::class)->buildReport([]);
        $html = view('exports.laporan-kinerja-operator-pdf', [
            'operators' => $report['operators']->map(fn (array $o) => array_merge($o, ['units' => implode(', ', $o['units'])])),
            'summary' => [
                'totalOperators' => $report['summary']['totalOperators'],
                'totalTickets' => $report['summary']['totalAssigned'],
                'totalSolved' => $report['summary']['totalSolved'],
                'totalActive' => $report['summary']['totalActive'],
                'avgCompliance' => $report['summary']['avgCompliance'],
                'avgRating' => $report['summary']['overallAvgRating'],
            ],
            'totals' => [
                'active' => $report['summary']['totalActive'],
                'solved' => $report['summary']['totalSolved'],
                'rejected' => $report['summary']['totalRejected'],
                'total' => $report['summary']['totalAssigned'],
                'avgCompliance' => $report['summary']['avgCompliance'],
                'breaches' => $report['summary']['totalResolutionBreaches'],
                'avgRating' => $report['summary']['overallAvgRating'],
                'reviews' => $report['summary']['totalReviews'],
            ],
            'periodePrint' => 'Tahun ' . date('Y'),
            'filterPrint' => null,
            'logo' => null,
            'userName' => 'Super Administrator',
        ])->render();

        $bodyText = strip_tags((string) preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html));

        $this->assertStringContainsString('<title>Laporan Kinerja Individu</title>', $html);
        $this->assertStringContainsString('<div class="summary-label">Total Individu</div>', $html);
        $this->assertStringContainsString('<th style="width: 12%;">Individu</th>', $html);
        $this->assertStringContainsString('<div class="summary-value teal">-</div>', $html);
        $this->assertStringNotContainsString('100%', $bodyText);
        $this->assertStringNotContainsString('null%', $bodyText);

        // Verify Excel renders '-' for both operator SLA and summary avgCompliance
        $sheet = $this->loadExportSpreadsheet();
        $this->assertSame('-', $sheet->getCell('K7')->getValue());
        $this->assertSame('-', $sheet->getCell('K9')->getValue());
        $this->assertNotSame('0.0"%"', $sheet->getStyle('K9')->getNumberFormat()->getFormatCode());
    }

    public function test_tie_breaker_sorting_and_weighted_csat_are_consistent_across_web_pdf_and_excel_without_duplicate_queries(): void
    {
        $opAlphaIdle = $this->createOperator('Alpha Idle', 'alpha_idle');
        $opBetaRejected = $this->createOperator('Beta Rejected', 'beta_rejected');
        $opZetaSolved = $this->createOperator('Zeta Solved', 'zeta_solved');
        $opGammaActive = $this->createOperator('Gamma Active', 'gamma_active');
        $opCharlieIdle = $this->createOperator('Charlie Idle', 'charlie_idle');

        // Gamma: 1 active, 0 solved, 1 total -> Rank 1
        $this->createTicket(['status' => 'open', 'assigned_admin_id' => $opGammaActive->id]);

        // Zeta: 0 active, 3 solved, 3 total, 3x 5-star ratings -> Rank 2
        for ($i = 0; $i < 3; $i++) {
            $t = $this->createTicket(['status' => 'solve', 'assigned_admin_id' => $opZetaSolved->id]);
            TicketLog::create(['ticket_id' => $t->id, 'admin_id' => $opZetaSolved->id, 'aksi' => 'solve', 'catatan' => 'ok']);
            TicketSlaTracking::create(['ticket_id' => $t->id, 'resolved_at' => now(), 'is_resolution_breached' => false]);
            Csat::create(['ticket_id' => $t->id, 'user_id' => $this->user->id, 'rating' => 5]);
        }

        // Beta: 0 active, 0 solved, 2 rejected (total=2) -> Rank 3; plus give 1 rating of 1-star on a rejected? Better: put 1-star on a solved ticket for a separate check?
        // Instead give Beta 2 rejected tickets (no CSAT) and give Gamma 1 solved? No, keep tie-breaker pure and add weighted rating on Gamma:
        // Wait: let's add 1 solved ticket with 1-star to opGammaActive (1 active, 1 solved, total 2) — Gamma still ranks #1 (active=1)!
        $gammaSolved = $this->createTicket(['status' => 'solve', 'assigned_admin_id' => $opGammaActive->id]);
        TicketLog::create(['ticket_id' => $gammaSolved->id, 'admin_id' => $opGammaActive->id, 'aksi' => 'solve', 'catatan' => 'ok']);
        TicketSlaTracking::create(['ticket_id' => $gammaSolved->id, 'resolved_at' => now(), 'is_resolution_breached' => true]);
        Csat::create(['ticket_id' => $gammaSolved->id, 'user_id' => $this->user->id, 'rating' => 1]);

        // Beta: 0 active, 0 solved, 2 rejected (total=2) -> Rank 3
        for ($i = 0; $i < 2; $i++) {
            $t = $this->createTicket(['status' => 'reject', 'assigned_admin_id' => $opBetaRejected->id]);
            TicketLog::create(['ticket_id' => $t->id, 'admin_id' => $opBetaRejected->id, 'aksi' => 'reject', 'catatan' => 'reject']);
        }

        // Weighted CSAT: (3*5 + 1*1) / 4 = 16 / 4 = 4.00 (unweighted would be (5 + 1) / 2 = 3.00!)
        // avgCompliance: (0.0 + 100.0) / 2 = 50.0 (idle operators with null excluded!)
        $expectedOrder = [
            $opGammaActive->name,
            $opZetaSolved->name,
            $opBetaRejected->name,
            $opAlphaIdle->name,
            $opCharlieIdle->name,
        ];

        // 1. Web index check
        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($expectedOrder) {
                $props = $page->toArray()['props'];
                $actualOrder = collect($props['operators'])->pluck('name')->all();

                $this->assertSame($expectedOrder, $actualOrder);
                $this->assertEquals(4.0, $props['summary']['overallAvgRating']);
                $this->assertSame(4, $props['summary']['totalReviews']);
                $this->assertEquals(50.0, $props['summary']['avgCompliance']);
                $this->assertSame(2, $props['summary']['totalRejected']);

                // Workload chart excludes Beta (0 active, 0 solved) and idle operators
                $this->assertSame(
                    ['Gamma Active', 'Zeta Solved'],
                    collect($props['workloadChartData'])->pluck('name')->all()
                );

                // Performance chart includes only Gamma and Zeta (both have non-null SLA/rating)
                $this->assertSame(
                    ['Gamma Active', 'Zeta Solved'],
                    collect($props['performanceChartData'])->pluck('name')->all()
                );
            });

        // 2. PDF HTML check via service & Blade view (exact same order & weighted rating)
        $report = app(\App\Services\OperatorPerformanceReportService::class)->buildReport([]);
        $this->assertSame($expectedOrder, $report['operators']->pluck('name')->all());
        $this->assertEquals(4.0, $report['summary']['overallAvgRating']);

        $pdfHtml = view('exports.laporan-kinerja-operator-pdf', [
            'operators' => $report['operators']->map(fn (array $o) => array_merge($o, ['units' => implode(', ', $o['units'])])),
            'summary' => [
                'totalOperators' => $report['summary']['totalOperators'],
                'totalTickets' => $report['summary']['totalAssigned'],
                'totalSolved' => $report['summary']['totalSolved'],
                'totalActive' => $report['summary']['totalActive'],
                'avgCompliance' => $report['summary']['avgCompliance'],
                'avgRating' => $report['summary']['overallAvgRating'],
            ],
            'totals' => [
                'active' => $report['summary']['totalActive'],
                'solved' => $report['summary']['totalSolved'],
                'rejected' => $report['summary']['totalRejected'],
                'total' => $report['summary']['totalAssigned'],
                'avgCompliance' => $report['summary']['avgCompliance'],
                'breaches' => $report['summary']['totalResolutionBreaches'],
                'avgRating' => $report['summary']['overallAvgRating'],
                'reviews' => $report['summary']['totalReviews'],
            ],
            'periodePrint' => 'Tahun ' . date('Y'),
            'filterPrint' => null,
            'logo' => null,
            'userName' => 'Super Administrator',
        ])->render();

        $positions = array_map(fn (string $name) => strpos($pdfHtml, "<strong>{$name}</strong>"), $expectedOrder);
        $sortedPositions = $positions;
        sort($sortedPositions);
        $this->assertSame($sortedPositions, $positions);
        $this->assertStringContainsString('<div class="summary-value purple">4.00</div>', $pdfHtml);
        $this->assertStringContainsString('<div class="summary-value teal">50%</div>', $pdfHtml);

        // 3. Excel check: exact order, weighted CSAT in summary row, and no duplicate query in AfterSheet
        $ticketsQueries = 0;
        DB::listen(function ($query) use (&$ticketsQueries) {
            if (str_contains($query->sql, 'ticket_logs') || str_contains($query->sql, 'attributed_tickets')) {
                $ticketsQueries++;
            }
        });

        $sheet = $this->loadExportSpreadsheet();

        // Expect exactly 1 aggregation query (AfterSheet reuses cached report!)
        $this->assertSame(1, $ticketsQueries);

        $excelOrder = [];
        for ($row = 7; $row <= 11; $row++) {
            $excelOrder[] = $sheet->getCell("B{$row}")->getValue();
        }
        $this->assertSame($expectedOrder, $excelOrder);

        // Gamma Active (row 7) has 0.0% SLA -> must write numeric 0 (not blank null) with 0.0"%" format
        $gammaSlaCell = $sheet->getCell('K7')->getValue();
        $this->assertNotNull($gammaSlaCell);
        $this->assertIsNumeric($gammaSlaCell);
        $this->assertEquals(0.0, (float) $gammaSlaCell);
        $this->assertSame('0.0"%"', $sheet->getStyle('K7')->getNumberFormat()->getFormatCode());

        // Summary row is row 13 (lastDataRow 11 + 2)
        $this->assertSame('RINGKASAN', $sheet->getCell('A13')->getValue());
        $this->assertEquals(50.0, $sheet->getCell('K13')->getValue());
        $this->assertEquals(4.0, $sheet->getCell('M13')->getValue());
        $this->assertEquals(4, $sheet->getCell('N13')->getValue());
    }

    public function test_multiple_sla_tracking_rows_for_one_ticket_do_not_multiply_counts_or_csat(): void
    {
        $op = $this->createOperator('Operator Multi SLA', 'op_multi_sla');

        $ticket = $this->createTicket([
            'status' => 'Selesai',
            'assigned_admin_id' => $op->id,
        ]);
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $op->id,
            'aksi' => 'solve',
            'catatan' => 'Selesai',
        ]);

        // Two SLA tracking rows for the same ticket: older breached row + newer non-breached row
        TicketSlaTracking::create([
            'ticket_id' => $ticket->id,
            'resolved_at' => now()->subHour(),
            'is_response_breached' => true,
            'is_resolution_breached' => true,
        ]);
        TicketSlaTracking::create([
            'ticket_id' => $ticket->id,
            'resolved_at' => now(),
            'is_response_breached' => false,
            'is_resolution_breached' => false,
        ]);

        Csat::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->user->id,
            'rating' => 5,
            'komentar' => 'Bagus',
        ]);

        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.totalAssigned', 1)
                ->where('summary.totalSolved', 1)
                ->where('summary.totalReviews', 1)
                ->where('summary.overallAvgRating', 5)
                ->where('operators.0.id', $op->id)
                ->where('operators.0.total_tickets', 1)
                ->where('operators.0.solved_tickets', 1)
                ->where('operators.0.total_resolved', 1)
                ->where('operators.0.resolution_breaches', 0)
                ->where('operators.0.response_breaches', 0)
                ->where('operators.0.resolution_compliance', 100)
                ->where('operators.0.total_reviews', 1)
                ->where('operators.0.avg_rating', 5)
            );
    }

    public function test_performance_chart_keeps_null_rating_instead_of_zero_when_unrated(): void
    {
        $op = $this->createOperator('Operator Tanpa Rating', 'op_no_rating');
        $ticket = $this->createTicket(['status' => 'solve', 'assigned_admin_id' => $op->id]);
        TicketLog::create(['ticket_id' => $ticket->id, 'admin_id' => $op->id, 'aksi' => 'solve', 'catatan' => 'ok']);
        TicketSlaTracking::create(['ticket_id' => $ticket->id, 'resolved_at' => now(), 'is_resolution_breached' => false]);

        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('performanceChartData', 1)
                ->where('performanceChartData.0.name', 'Operator Tanpa Rating')
                ->where('performanceChartData.0.sla', 100)
                ->where('performanceChartData.0.rating', null)
            );
    }

    public function test_unit_filter_includes_direct_units_sub_units_and_ticket_resolvers_and_respects_search(): void
    {
        $opDirectUnit = $this->createOperator('Operator Direct Unit', 'op_direct');
        $opDirectUnit->units()->attach($this->unitA->id);

        $opSubUnitOnly = $this->createOperator('Operator SubUnit Only', 'op_subunit');
        $opSubUnitOnly->subUnits()->attach($this->subUnitA->id);

        $opOtherUnit = $this->createOperator('Operator Unit B', 'op_unit_b');
        $opOtherUnit->units()->attach($this->unitB->id);

        // Coordinator not attached to Unit A at all, but solved a ticket in Unit A
        $coordinator = $this->createOperator('Koordinator Resolver', 'koor_resolver', 'Koordinator');
        $ticketInUnitA = $this->createTicket([
            'unit_id' => $this->unitA->id,
            'sub_unit_id' => $this->subUnitA->id,
            'status' => 'solve',
            'assigned_admin_id' => null,
        ]);
        TicketLog::create([
            'ticket_id' => $ticketInUnitA->id,
            'admin_id' => $coordinator->id,
            'aksi' => 'solve',
            'catatan' => 'Solved in Unit A',
        ]);

        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator', ['unit_id' => $this->unitA->id]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($opDirectUnit, $opSubUnitOnly, $opOtherUnit, $coordinator) {
                $ids = collect($page->toArray()['props']['operators'])->pluck('id')->all();

                $this->assertContains($opDirectUnit->id, $ids);
                $this->assertContains($opSubUnitOnly->id, $ids);
                $this->assertContains($coordinator->id, $ids);
                $this->assertNotContains($opOtherUnit->id, $ids);
            });

        // Search filter still applies together with unit_id
        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator', ['unit_id' => $this->unitA->id, 'search' => 'SubUnit']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('operators', 1)
                ->where('operators.0.id', $opSubUnitOnly->id)
            );
    }

    public function test_ulasan_matches_index_default_year_and_date_range_filtering(): void
    {
        $op = $this->createOperator('Operator Period', 'op_period');

        $currentYearTicket = $this->createTicket(['status' => 'Selesai', 'assigned_admin_id' => $op->id]);
        TicketLog::create(['ticket_id' => $currentYearTicket->id, 'admin_id' => $op->id, 'aksi' => 'solve', 'catatan' => 'ok']);
        Csat::create(['ticket_id' => $currentYearTicket->id, 'user_id' => $this->user->id, 'rating' => 5, 'komentar' => 'Tahun ini']);

        $lastYear = (int) date('Y') - 1;
        $lastYearTicket = $this->createTicket(['status' => 'Selesai', 'assigned_admin_id' => $op->id]);
        $lastYearTicket->forceFill(['created_at' => "{$lastYear}-06-15 10:00:00"])->save();
        TicketLog::create(['ticket_id' => $lastYearTicket->id, 'admin_id' => $op->id, 'aksi' => 'solve', 'catatan' => 'ok']);
        Csat::create(['ticket_id' => $lastYearTicket->id, 'user_id' => $this->user->id, 'rating' => 3, 'komentar' => 'Tahun lalu']);

        // Default request without year param matches index() -> current year only
        $this->actingAs($this->viewer, 'admin')
            ->getJson(route('admin.laporan.operator.ulasan', $op->id))
            ->assertOk()
            ->assertJsonCount(1, 'reviews')
            ->assertJsonPath('reviews.0.ticket_raw_id', $currentYearTicket->id);

        // Explicit previous year filter returns previous year's review
        $this->actingAs($this->viewer, 'admin')
            ->getJson(route('admin.laporan.operator.ulasan', ['admin' => $op->id, 'year' => $lastYear]))
            ->assertOk()
            ->assertJsonCount(1, 'reviews')
            ->assertJsonPath('reviews.0.ticket_raw_id', $lastYearTicket->id);

        // Explicit date range filter overrides default year
        $this->actingAs($this->viewer, 'admin')
            ->getJson(route('admin.laporan.operator.ulasan', [
                'admin' => $op->id,
                'date_from' => "{$lastYear}-06-01",
                'date_to' => "{$lastYear}-06-30",
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'reviews')
            ->assertJsonPath('reviews.0.ticket_raw_id', $lastYearTicket->id);
    }

    public function test_malformed_date_filters_fall_back_gracefully_across_index_pdf_and_excel(): void
    {
        $op = $this->createOperator('Operator Tanggal', 'op_date_safe');
        $ticket = $this->createTicket(['status' => 'solve', 'assigned_admin_id' => $op->id]);
        TicketLog::create(['ticket_id' => $ticket->id, 'admin_id' => $op->id, 'aksi' => 'solve', 'catatan' => 'ok']);

        $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator', ['date_from' => 'invalid-date', 'date_to' => 'also-bad']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.date_from', null)
                ->where('filters.date_to', null)
                ->where('summary.totalSolved', 1)
            );

        $pdfResponse = $this->actingAs($this->viewer, 'admin')
            ->get(route('admin.laporan.operator.export-pdf', ['date_from' => 'invalid']));
        $pdfResponse->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdfResponse->getContent());

        $sheet = $this->loadExportSpreadsheet(['date_from' => 'invalid', 'month' => '99']);
        $this->assertSame('LAPORAN BEBAN & KINERJA INDIVIDU', $sheet->getCell('A1')->getValue());
    }
}
