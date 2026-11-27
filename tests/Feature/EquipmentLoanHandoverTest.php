<?php

namespace Tests\Feature;

use App\Channels\WhatsAppChannel;
use App\Models\Admin;
use App\Models\FormField;
use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\EquipmentReturnReportedNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EquipmentLoanHandoverTest extends TestCase
{
    use RefreshDatabase;

    private Unit $gaUnit;
    private Unit $otherUnit;
    private SubUnit $loanSubUnit;
    private FormField $alatField;
    private FormField $tglMulaiField;
    private FormField $tglSelesaiField;
    private Admin $gaOperator;
    private Admin $otherOperator;
    private User $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Operator', 'Admin', 'Super Admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'admin']);
        }

        $this->gaUnit = Unit::create([
            'nama_unit' => 'GA',
            'deskripsi' => 'General Affairs',
            'aktif' => true,
        ]);

        $this->otherUnit = Unit::create([
            'nama_unit' => 'IT',
            'deskripsi' => 'IT Support',
            'aktif' => true,
        ]);

        $this->loanSubUnit = SubUnit::create([
            'unit_id' => $this->gaUnit->id,
            'nama_layanan' => 'Peminjaman Alat',
            'deskripsi' => 'Layanan Peminjaman Alat',
            'aktif' => true,
            'wajib_kembali' => true,
        ]);

        $this->alatField = FormField::create([
            'sub_unit_id' => $this->loanSubUnit->id,
            'label' => 'Pilih Alat',
            'tipe_field' => 'multi_pilih',
            'wajib' => true,
            'opsi' => ['Infokus', 'Kabel HDMI', 'Yang lain:'],
            'urutan' => 1,
        ]);

        $this->tglMulaiField = FormField::create([
            'sub_unit_id' => $this->loanSubUnit->id,
            'label' => 'Tanggal Mulai',
            'tipe_field' => 'tanggal',
            'wajib' => true,
            'opsi' => [],
            'urutan' => 2,
        ]);

        $this->tglSelesaiField = FormField::create([
            'sub_unit_id' => $this->loanSubUnit->id,
            'label' => 'Tanggal Selesai',
            'tipe_field' => 'tanggal',
            'wajib' => true,
            'opsi' => [],
            'urutan' => 3,
        ]);

        $this->gaOperator = Admin::factory()->create(['username' => 'ga_operator', 'name' => 'Operator GA']);
        $this->gaOperator->assignRole('Operator');
        $this->gaOperator->units()->attach($this->gaUnit->id);

        $this->otherOperator = Admin::factory()->create(['username' => 'it_operator', 'name' => 'Operator IT']);
        $this->otherOperator->assignRole('Operator');
        $this->otherOperator->units()->attach($this->otherUnit->id);

        $this->borrower = User::factory()->create(['name' => 'Budi Peminjam', 'username' => 'budi']);
    }

    private function createLoanTicket(
        string $status = 'solve',
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
        array $tools = ['Infokus']
    ): Ticket {
        $start = ($startDate ?? Carbon::now()->addDays(7))->format('Y-m-d');
        $end = ($endDate ?? Carbon::now()->addDays(8))->format('Y-m-d');

        return Ticket::create([
            'user_id' => $this->borrower->id,
            'unit_id' => $this->gaUnit->id,
            'sub_unit_id' => $this->loanSubUnit->id,
            'assigned_admin_id' => $this->gaOperator->id,
            'status' => $status,
            'priority' => 'Sedang',
            'source' => 'web',
            'form_data' => [
                (string) $this->alatField->id => $tools,
                (string) $this->tglMulaiField->id => $start,
                (string) $this->tglSelesaiField->id => $end,
            ],
        ]);
    }

    public function test_future_solved_loan_appears_in_peminjaman_alat_tab_and_live_monitor(): void
    {
        $now = Carbon::create(2026, 11, 25, 10, 0, 0);
        Carbon::setTestNow($now);

        $ticket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy()->addDays(7),
            endDate: $now->copy()->addDays(9),
            tools: ['Infokus', 'Kabel HDMI']
        );

        // Admin index ?tab=peminjaman_alat
        $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'peminjaman_alat']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('currentTab', 'peminjaman_alat')
                ->where('tabCounts.peminjaman_alat', 1)
                ->where('loanStage', 'aktif')
                ->where('loanStageCounts.menunggu_diserahkan', 1)
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $ticket->id)
                ->where('tickets.data.0.can_serahkan', true)
                ->where('tickets.data.0.can_kembalikan', true)
                ->where('tickets.data.0.loan_summary.status_peminjaman', 'Menunggu Diserahkan')
                ->where('tickets.data.0.loan_summary.alat_text', 'Infokus, Kabel HDMI')
            );

        // Admin Live Monitor
        $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.monitor.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Monitor/Index')
                ->has('alatPinjam', 1, fn (Assert $item) => $item
                    ->where('ticket_id', $ticket->id)
                    ->where('status_pengembalian', 'Menunggu Diserahkan')
                    ->where('can_serahkan', true)
                    ->where('can_kembalikan', true)
                    ->etc()
                )
            );

        // User Live Monitor (read-only: can_serahkan & can_kembalikan must be false)
        $this->actingAs($this->borrower, 'web')
            ->get(route('monitor'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Monitor/Index')
                ->has('alatPinjam', 1, fn (Assert $item) => $item
                    ->where('ticket_id', $ticket->id)
                    ->where('status_pengembalian', 'Menunggu Diserahkan')
                    ->where('can_serahkan', false)
                    ->where('can_kembalikan', false)
                    ->etc()
                )
            );

        Carbon::setTestNow();
    }

    public function test_admin_can_mark_equipment_as_handed_over_with_validations_and_monitor_transitions(): void
    {
        $now = Carbon::create(2026, 11, 25, 10, 0, 0);
        Carbon::setTestNow($now);

        $openTicket = $this->createLoanTicket(
            status: 'open',
            startDate: $now->copy(),
            endDate: $now->copy()->addDays(2),
            tools: ['Infokus']
        );

        // Non-solve ticket is rejected
        $this->actingAs($this->gaOperator, 'admin')
            ->patch(route('admin.tiket.serahkan-alat', $openTicket->id), [
                'penerima_alat_user_id' => $this->borrower->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($openTicket->fresh()->diserahkan_at);

        // Unauthorized admin gets 403
        $solvedTicket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy(),
            endDate: $now->copy()->addDays(2),
            tools: ['Kabel HDMI']
        );

        $this->actingAs($this->otherOperator, 'admin')
            ->patch(route('admin.tiket.serahkan-alat', $solvedTicket->id), [
                'penerima_alat_user_id' => $this->borrower->id,
            ])
            ->assertForbidden();

        // Authorized admin marks handed over without recipient user -> defaults automatically to admin name
        $this->actingAs($this->gaOperator, 'admin')
            ->patch(route('admin.tiket.serahkan-alat', $solvedTicket->id), [
                'kondisi_serah' => 'baik',
                'catatan_serah' => 'Lengkap dengan tas dan remote',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $solvedTicket->refresh();
        $this->assertNotNull($solvedTicket->diserahkan_at);
        $this->assertSame($this->gaOperator->id, $solvedTicket->diserahkan_oleh_admin_id);
        $this->assertNull($solvedTicket->penerima_alat_user_id);
        $this->assertSame('Operator GA', $solvedTicket->penerima_alat);
        $this->assertSame('baik', $solvedTicket->kondisi_serah);
        $this->assertSame('Lengkap dengan tas dan remote', $solvedTicket->catatan_serah);

        $log = TicketLog::where('ticket_id', $solvedTicket->id)
            ->where('aksi', 'alat_diserahkan')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($this->gaOperator->id, $log->admin_id);
        $this->assertStringContainsString('Operator GA', $log->catatan);
        $this->assertStringContainsString('Bagus (Baik)', $log->catatan);

        // Live Monitor shows Sedang Dipinjam while now <= tanggal_selesai
        $monitorProps = $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.monitor.index'))
            ->assertOk()
            ->original->getData()['page']['props'];
        $loanRow = collect($monitorProps['alatPinjam'])->firstWhere('ticket_id', $solvedTicket->id);
        $this->assertSame('Sedang Dipinjam', $loanRow['status_pengembalian']);
        $this->assertSame('baik', $loanRow['kondisi_serah']);
        $this->assertFalse($loanRow['can_serahkan']);
        $this->assertTrue($loanRow['can_kembalikan']);

        // Duplicate handover is rejected
        $this->actingAs($this->gaOperator, 'admin')
            ->patch(route('admin.tiket.serahkan-alat', $solvedTicket->id), [
                'penerima_alat_user_id' => $this->borrower->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        // After tanggal_selesai passes without return, Monitor status becomes Belum Dikembalikan
        Carbon::setTestNow($now->copy()->addDays(5));
        $overdueProps = $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.monitor.index'))
            ->assertOk()
            ->original->getData()['page']['props'];
        $overdueRow = collect($overdueProps['alatPinjam'])->firstWhere('ticket_id', $solvedTicket->id);
        $this->assertSame('Belum Dikembalikan', $overdueRow['status_pengembalian']);

        Carbon::setTestNow();
    }

    public function test_admin_can_mark_equipment_returned_syncs_booking_logs_and_updates_tab_counts(): void
    {
        $now = Carbon::create(2026, 11, 25, 10, 0, 0);
        Carbon::setTestNow($now);

        $ticket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy(),
            endDate: $now->copy()->addDay(),
            tools: ['Infokus']
        );

        RoomVehicleBooking::create([
            'ticket_id' => $ticket->id,
            'tipe' => 'Alat',
            'nama_aset' => 'Infokus',
            'tanggal_mulai' => $now->copy(),
            'tanggal_selesai' => $now->copy()->addDay(),
            'status' => 'disetujui',
        ]);

        // Hand over to registered borrower user
        $this->actingAs($this->gaOperator, 'admin')
            ->patch(route('admin.tiket.serahkan-alat', $ticket->id), [
                'penerima_alat_user_id' => $this->borrower->id,
            ])
            ->assertSessionHas('success');

        $this->assertSame($this->borrower->id, $ticket->fresh()->penerima_alat_user_id);
        $this->assertSame('Budi Peminjam', $ticket->fresh()->penerima_alat);

        // Return equipment
        $this->actingAs($this->gaOperator, 'admin')
            ->patch(route('admin.tiket.kembalikan-alat', $ticket->id), [
                'kondisi_kembali' => 'tidak_lengkap',
                'catatan_kembali' => 'Kabel VGA tertinggal',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ticket->refresh();
        $this->assertNotNull($ticket->dikembalikan_at);
        $this->assertSame($this->gaOperator->id, $ticket->diterima_kembali_oleh_admin_id);
        $this->assertSame('tidak_lengkap', $ticket->kondisi_kembali);
        $this->assertSame('Kabel VGA tertinggal', $ticket->catatan_kembali);
        $this->assertSame('selesai', $ticket->booking->fresh()->status);

        $log = TicketLog::where('ticket_id', $ticket->id)
            ->where('aksi', 'alat_dikembalikan')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($this->gaOperator->id, $log->admin_id);
        $this->assertStringContainsString('Kurang Lengkap', $log->catatan);
        $this->assertStringContainsString('Kabel VGA tertinggal', $log->catatan);

        // Duplicate return is rejected
        $this->actingAs($this->gaOperator, 'admin')
            ->patch(route('admin.tiket.kembalikan-alat', $ticket->id), [
                'kondisi_kembali' => 'baik',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        // tabCounts.peminjaman_alat drops to 0
        $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'peminjaman_alat']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tabCounts.peminjaman_alat', 0)
                ->where('loanStageCounts.aktif', 0)
                ->where('loanStageCounts.dikembalikan', 1)
            );

        Carbon::setTestNow();
    }

    public function test_admin_can_shortcut_return_without_prior_handover_leaving_diserahkan_at_null(): void
    {
        $ticket = $this->createLoanTicket(status: 'solve');

        $this->actingAs($this->gaOperator, 'admin')
            ->patch(route('admin.tiket.kembalikan-alat', $ticket->id), [
                'kondisi_kembali' => 'baik',
                'catatan_kembali' => 'Langsung dikembalikan ke gudang',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ticket->refresh();
        $this->assertNull($ticket->diserahkan_at);
        $this->assertNull($ticket->diserahkan_oleh_admin_id);
        $this->assertNotNull($ticket->dikembalikan_at);
        $this->assertSame($this->gaOperator->id, $ticket->diterima_kembali_oleh_admin_id);
        $this->assertSame('baik', $ticket->kondisi_kembali);
    }

    public function test_user_cannot_confirm_equipment_return_and_does_not_mutate_sub_unit(): void
    {
        $nonLoanSubUnit = SubUnit::create([
            'unit_id' => $this->gaUnit->id,
            'nama_layanan' => 'Perbaikan Gedung',
            'aktif' => true,
            'wajib_kembali' => false,
        ]);

        $ticket = Ticket::create([
            'user_id' => $this->borrower->id,
            'unit_id' => $this->gaUnit->id,
            'sub_unit_id' => $nonLoanSubUnit->id,
            'status' => 'solve',
            'priority' => 'Sedang',
            'source' => 'web',
            'form_data' => [],
        ]);

        $this->actingAs($this->borrower, 'web')
            ->post(route('tiket.kembalikan-alat', $ticket->id), [
                'kondisi_kembali' => 'baik',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($ticket->fresh()->dikembalikan_at);
        $this->assertFalse((bool) $nonLoanSubUnit->fresh()->wajib_kembali);

        $this->actingAs($this->borrower, 'web')
            ->postJson(route('tiket.kembalikan-alat', $ticket->id), [
                'kondisi_kembali' => 'baik',
            ])
            ->assertForbidden();
    }

    public function test_api_handover_and_return_endpoints_enforce_roles_status_and_return_fields(): void
    {
        $openTicket = $this->createLoanTicket(status: 'open');
        $solvedTicket = $this->createLoanTicket(status: 'solve');

        // Non-admin user gets 403
        Sanctum::actingAs($this->borrower, ['*']);
        $this->postJson("/api/tickets/{$solvedTicket->id}/serahkan-alat", [
            'penerima_alat_user_id' => $this->borrower->id,
        ])->assertForbidden();

        $this->postJson("/api/tickets/{$solvedTicket->id}/kembalikan-alat", [
            'kondisi_kembali' => 'baik',
        ])->assertForbidden();

        // Admin gets 422 on non-solve ticket
        Sanctum::actingAs($this->gaOperator, ['*']);
        $this->postJson("/api/tickets/{$openTicket->id}/serahkan-alat", [
            'penerima_alat_user_id' => $this->borrower->id,
        ])->assertStatus(422);

        $this->postJson("/api/tickets/{$openTicket->id}/kembalikan-alat", [
            'kondisi_kembali' => 'baik',
        ])->assertStatus(422);

        // Admin succeeds on solve ticket with automatic recipient (no user selected)
        $handoverResponse = $this->postJson("/api/tickets/{$solvedTicket->id}/serahkan-alat", [
            'kondisi_serah' => 'tidak_lengkap',
            'catatan_serah' => 'Tas & charger lengkap',
        ])->assertOk();

        $handoverResponse
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.penerima_alat', 'Operator GA')
            ->assertJsonPath('data.penerima_alat_user_id', null)
            ->assertJsonPath('data.kondisi_serah', 'tidak_lengkap')
            ->assertJsonPath('data.catatan_serah', 'Tas & charger lengkap')
            ->assertJsonPath('data.diserahkan_oleh', 'Operator GA')
            ->assertJsonPath('data.status_peminjaman', 'Sedang Dipinjam')
            ->assertJsonPath('data.can_serahkan', false)
            ->assertJsonPath('data.can_kembalikan', true);
        $this->assertNotNull($handoverResponse->json('data.diserahkan_at'));

        $returnResponse = $this->postJson("/api/tickets/{$solvedTicket->id}/kembalikan-alat", [
            'kondisi_kembali' => 'baik',
            'catatan_kembali' => 'Dikembalikan mulus',
        ])->assertOk();

        $returnResponse
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kondisi_kembali', 'baik')
            ->assertJsonPath('data.catatan_kembali', 'Dikembalikan mulus')
            ->assertJsonPath('data.diterima_kembali_oleh', 'Operator GA')
            ->assertJsonPath('data.penerima_alat', 'Operator GA')
            ->assertJsonPath('data.kondisi_serah', 'tidak_lengkap')
            ->assertJsonPath('data.status_peminjaman', 'Dikembalikan')
            ->assertJsonPath('data.can_serahkan', false)
            ->assertJsonPath('data.can_kembalikan', false);
        $this->assertNotNull($returnResponse->json('data.dikembalikan_at'));
    }

    public function test_handover_recipient_selection_and_invalid_user_validation(): void
    {
        $ticket = $this->createLoanTicket(status: 'solve');

        // Invalid user id is rejected with validation error
        $this->actingAs($this->gaOperator, 'admin')
            ->from(route('admin.tiket.show', $ticket->id))
            ->patch(route('admin.tiket.serahkan-alat', $ticket->id), [
                'penerima_alat_user_id' => 999999,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('penerima_alat_user_id');

        $this->assertNull($ticket->fresh()->diserahkan_at);

        // Selecting a valid registered user fills penerima_alat from user's name
        $this->actingAs($this->gaOperator, 'admin')
            ->patch(route('admin.tiket.serahkan-alat', $ticket->id), [
                'penerima_alat_user_id' => $this->borrower->id,
                'kondisi_serah' => 'rusak',
                'catatan_serah' => 'Port HDMI longgar saat dicek',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $ticket->refresh();
        $this->assertSame($this->borrower->id, $ticket->penerima_alat_user_id);
        $this->assertSame($this->borrower->name, $ticket->penerima_alat);
        $this->assertSame('rusak', $ticket->kondisi_serah);
    }

    public function test_recipient_options_endpoints_for_web_admin_and_mobile_api(): void
    {
        $otherUser = User::factory()->create(['name' => 'Andi Karyawan', 'username' => 'andi_k']);
        $ticket = $this->createLoanTicket(status: 'solve');

        // Web Admin endpoint
        $webResponse = $this->actingAs($this->gaOperator, 'admin')
            ->getJson(route('admin.tiket.penerima-alat-options', ['ticket_id' => $ticket->id]))
            ->assertOk()
            ->assertJsonPath('data.default_recipient.type', 'admin')
            ->assertJsonPath('data.default_recipient.id', $this->gaOperator->id)
            ->assertJsonPath('data.default_recipient.name', 'Operator GA')
            ->assertJsonPath('data.options.0.id', $this->borrower->id)
            ->assertJsonPath('data.options.0.is_requester', true)
            ->assertJsonPath('data.options.1.id', $otherUser->id)
            ->assertJsonPath('data.options.1.is_requester', false);

        $this->assertCount(2, $webResponse->json('data.options'));

        // Search filter works on Web endpoint
        $this->actingAs($this->gaOperator, 'admin')
            ->getJson(route('admin.tiket.penerima-alat-options', [
                'ticket_id' => $ticket->id,
                'search' => 'Andi',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.options')
            ->assertJsonPath('data.options.0.id', $otherUser->id);

        // Mobile API endpoint enforces admin role (403 for non-admin)
        Sanctum::actingAs($this->borrower, ['*']);
        $this->getJson('/api/tickets/loan-recipients?ticket_id='.$ticket->id)
            ->assertForbidden();

        // Mobile API endpoint returns identical structure for Admin
        Sanctum::actingAs($this->gaOperator, ['*']);
        $this->getJson('/api/tickets/loan-recipients?ticket_id='.$ticket->id)
            ->assertOk()
            ->assertJsonPath('data.default_recipient.name', 'Operator GA')
            ->assertJsonPath('data.options.0.id', $this->borrower->id)
            ->assertJsonPath('data.options.0.is_requester', true);
    }

    public function test_handover_and_return_conditions_with_attachments_appear_in_web_admin_and_user_detail(): void
    {
        Storage::fake('public');

        $ticket = $this->createLoanTicket(status: 'solve');
        $handoverPhoto = UploadedFile::fake()->image('serah.jpg', 100, 100);
        $returnPhoto = UploadedFile::fake()->image('kembali.png', 100, 100);

        // Simulate Inertia POST with _method=patch + multipart files
        $this->actingAs($this->gaOperator, 'admin')
            ->post(route('admin.tiket.serahkan-alat', $ticket->id), [
                '_method' => 'patch',
                'penerima_alat_user_id' => $this->borrower->id,
                'kondisi_serah' => 'tidak_lengkap',
                'catatan_serah' => 'Tas tidak ada',
                'lampiran' => [$handoverPhoto],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $serahLog = TicketLog::where('ticket_id', $ticket->id)->where('aksi', 'alat_diserahkan')->firstOrFail();
        $serahAttachment = TicketAttachment::where('ticket_log_id', $serahLog->id)->first();
        $this->assertNotNull($serahAttachment);
        Storage::disk('public')->assertExists($serahAttachment->file_path);

        // Return with PATCH + attachment
        $this->actingAs($this->gaOperator, 'admin')
            ->patch(route('admin.tiket.kembalikan-alat', $ticket->id), [
                'kondisi_kembali' => 'rusak',
                'catatan_kembali' => 'Lensa retak',
                'lampiran' => [$returnPhoto],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $kembaliLog = TicketLog::where('ticket_id', $ticket->id)->where('aksi', 'alat_dikembalikan')->firstOrFail();
        $kembaliAttachment = TicketAttachment::where('ticket_log_id', $kembaliLog->id)->first();
        $this->assertNotNull($kembaliAttachment);
        Storage::disk('public')->assertExists($kembaliAttachment->file_path);

        // Verify Admin Detail props
        $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.tiket.show', $ticket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Detail')
                ->where('ticket.kondisi_serah', 'tidak_lengkap')
                ->where('ticket.kondisi_kembali', 'rusak')
                ->has('loanAttachments.serah', 1)
                ->where('loanAttachments.serah.0.id', $serahAttachment->id)
                ->has('loanAttachments.kembali', 1)
                ->where('loanAttachments.kembali.0.id', $kembaliAttachment->id)
            );

        // Verify User Detail props
        $this->actingAs($this->borrower, 'web')
            ->get(route('tiket.show', $ticket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Tiket/Detail')
                ->where('ticket.kondisi_serah', 'tidak_lengkap')
                ->where('ticket.kondisi_kembali', 'rusak')
                ->has('loanAttachments.serah', 1)
                ->where('loanAttachments.serah.0.id', $serahAttachment->id)
                ->has('loanAttachments.kembali', 1)
                ->where('loanAttachments.kembali.0.id', $kembaliAttachment->id)
            );

        // Verify Admin Index & Live Monitor attachment counts
        $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'peminjaman_alat', 'loan_stage' => 'dikembalikan']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.data.0.kondisi_serah', 'tidak_lengkap')
                ->where('tickets.data.0.lampiran_serah_count', 1)
                ->where('tickets.data.0.lampiran_kembali_count', 1)
            );
    }

    public function test_mobile_api_handover_and_return_with_condition_user_recipient_and_attachments(): void
    {
        Storage::fake('public');

        $ticket = $this->createLoanTicket(status: 'solve');
        $handoverFile = UploadedFile::fake()->image('mobile-serah.jpg', 100, 100);
        $returnFile = UploadedFile::fake()->create('mobile-kembali.pdf', 120, 'application/pdf');

        Sanctum::actingAs($this->gaOperator, ['*']);

        // Invalid user rejected with 422
        $this->postJson("/api/tickets/{$ticket->id}/serahkan-alat", [
            'penerima_alat_user_id' => 999999,
        ])->assertStatus(422);

        // Handover with user selection + kondisi_serah + attachment
        $handoverResponse = $this->post("/api/tickets/{$ticket->id}/serahkan-alat", [
            'penerima_alat_user_id' => $this->borrower->id,
            'kondisi_serah' => 'baik',
            'catatan_serah' => 'Dicek lengkap sebelum diserahkan',
            'lampiran' => [$handoverFile],
        ], ['Accept' => 'application/json'])->assertOk();

        $handoverResponse
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.penerima_alat_user_id', $this->borrower->id)
            ->assertJsonPath('data.penerima_alat', $this->borrower->name)
            ->assertJsonPath('data.kondisi_serah', 'baik')
            ->assertJsonPath('data.status_peminjaman', 'Sedang Dipinjam')
            ->assertJsonPath('data.can_serahkan', false)
            ->assertJsonPath('data.can_kembalikan', true)
            ->assertJsonCount(1, 'data.lampiran_serah')
            ->assertJsonCount(0, 'data.lampiran_kembali');

        // Return with kondisi_kembali + attachment
        $returnResponse = $this->post("/api/tickets/{$ticket->id}/kembalikan-alat", [
            'kondisi_kembali' => 'rusak',
            'catatan_kembali' => 'Kabel HDMI putus',
            'lampiran' => [$returnFile],
        ], ['Accept' => 'application/json'])->assertOk();

        $returnResponse
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.kondisi_serah', 'baik')
            ->assertJsonPath('data.kondisi_kembali', 'rusak')
            ->assertJsonPath('data.status_peminjaman', 'Dikembalikan')
            ->assertJsonPath('data.can_serahkan', false)
            ->assertJsonPath('data.can_kembalikan', false)
            ->assertJsonCount(1, 'data.lampiran_serah')
            ->assertJsonCount(1, 'data.lampiran_kembali');

        // Show endpoint also returns full parity fields
        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.penerima_alat_user_id', $this->borrower->id)
            ->assertJsonPath('data.kondisi_serah', 'baik')
            ->assertJsonPath('data.status_peminjaman', 'Dikembalikan')
            ->assertJsonCount(1, 'data.lampiran_serah')
            ->assertJsonCount(1, 'data.lampiran_kembali');
    }

    public function test_loan_tab_visibility_depends_on_super_admin_loan_service_or_assignment(): void
    {
        $superAdmin = Admin::factory()->create(['username' => 'superadmin_ga', 'name' => 'Super Admin']);
        $superAdmin->assignRole('Super Admin');

        $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'peminjaman_alat']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('canViewEquipmentLoans', true)
                ->where('currentTab', 'peminjaman_alat')
            );

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'peminjaman_alat']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('canViewEquipmentLoans', true)
                ->where('currentTab', 'peminjaman_alat')
            );

        $this->actingAs($this->otherOperator, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'peminjaman_alat']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('canViewEquipmentLoans', false)
                ->where('currentTab', 'masuk')
            );
    }

    public function test_non_loan_admin_who_self_submitted_loan_ticket_cannot_view_tab_or_manage_handover(): void
    {
        $now = Carbon::create(2026, 11, 25, 10, 0, 0);
        Carbon::setTestNow($now);

        $selfSubmittedTicket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->otherOperator->id,
            'unit_id' => $this->gaUnit->id,
            'sub_unit_id' => $this->loanSubUnit->id,
            'assigned_admin_id' => $this->gaOperator->id,
            'status' => 'solve',
            'priority' => 'Sedang',
            'source' => 'web',
            'form_data' => [
                (string) $this->alatField->id => ['Infokus'],
                (string) $this->tglMulaiField->id => $now->format('Y-m-d'),
                (string) $this->tglSelesaiField->id => $now->copy()->addDay()->format('Y-m-d'),
            ],
        ]);

        // Index tab visibility & fallback
        $this->actingAs($this->otherOperator, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'peminjaman_alat']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('canViewEquipmentLoans', false)
                ->where('currentTab', 'masuk')
                ->where('tabCounts.peminjaman_alat', 0)
            );

        // Detail Admin shows read-only loan card (canManageEquipmentLoan = false)
        $this->actingAs($this->otherOperator, 'admin')
            ->get(route('admin.tiket.show', $selfSubmittedTicket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Detail')
                ->where('isEquipmentLoan', true)
                ->where('canManageEquipmentLoan', false)
            );

        // Live Monitor Admin shows can_serahkan = false & can_kembalikan = false
        $this->actingAs($this->otherOperator, 'admin')
            ->get(route('admin.monitor.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Monitor/Index')
                ->has('alatPinjam', 1, fn (Assert $item) => $item
                    ->where('ticket_id', $selfSubmittedTicket->id)
                    ->where('can_serahkan', false)
                    ->where('can_kembalikan', false)
                    ->etc()
                )
            );

        // Web Admin endpoints return 403
        $this->actingAs($this->otherOperator, 'admin')
            ->patch(route('admin.tiket.serahkan-alat', $selfSubmittedTicket->id), [
                'penerima_alat_user_id' => $this->borrower->id,
            ])
            ->assertForbidden();

        $this->actingAs($this->otherOperator, 'admin')
            ->patch(route('admin.tiket.kembalikan-alat', $selfSubmittedTicket->id), [
                'kondisi_kembali' => 'baik',
            ])
            ->assertForbidden();

        // API endpoints return 403
        Sanctum::actingAs($this->otherOperator, ['*']);
        $this->postJson("/api/tickets/{$selfSubmittedTicket->id}/serahkan-alat", [
            'penerima_alat_user_id' => $this->borrower->id,
        ])->assertForbidden();

        $this->postJson("/api/tickets/{$selfSubmittedTicket->id}/kembalikan-alat", [
            'kondisi_kembali' => 'baik',
        ])->assertForbidden();

        $this->assertNull($selfSubmittedTicket->fresh()->diserahkan_at);
        $this->assertNull($selfSubmittedTicket->fresh()->dikembalikan_at);

        Carbon::setTestNow();
    }

    public function test_non_ga_operator_explicitly_assigned_to_loan_ticket_can_view_tab_and_manage_handover(): void
    {
        $now = Carbon::create(2026, 11, 25, 10, 0, 0);
        Carbon::setTestNow($now);

        $assignedTicket = Ticket::create([
            'user_id' => $this->borrower->id,
            'unit_id' => $this->gaUnit->id,
            'sub_unit_id' => $this->loanSubUnit->id,
            'assigned_admin_id' => $this->otherOperator->id,
            'status' => 'solve',
            'priority' => 'Sedang',
            'source' => 'web',
            'form_data' => [
                (string) $this->alatField->id => ['Kabel HDMI'],
                (string) $this->tglMulaiField->id => $now->format('Y-m-d'),
                (string) $this->tglSelesaiField->id => $now->copy()->addDay()->format('Y-m-d'),
            ],
        ]);

        // Unassigned loan ticket in GA unit should not be visible to IT operator
        $unassignedGaTicket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy(),
            endDate: $now->copy()->addDay(),
            tools: ['Infokus']
        );

        $this->actingAs($this->otherOperator, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'peminjaman_alat']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('canViewEquipmentLoans', true)
                ->where('currentTab', 'peminjaman_alat')
                ->where('tabCounts.peminjaman_alat', 1)
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $assignedTicket->id)
                ->where('tickets.data.0.can_serahkan', true)
                ->where('tickets.data.0.can_kembalikan', true)
            );

        $this->actingAs($this->otherOperator, 'admin')
            ->get(route('admin.tiket.show', $assignedTicket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Detail')
                ->where('canManageEquipmentLoan', true)
            );

        // Can handover & return the explicitly assigned ticket
        $this->actingAs($this->otherOperator, 'admin')
            ->patch(route('admin.tiket.serahkan-alat', $assignedTicket->id), [
                'penerima_alat_user_id' => $this->borrower->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->otherOperator, 'admin')
            ->patch(route('admin.tiket.kembalikan-alat', $assignedTicket->id), [
                'kondisi_kembali' => 'baik',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($assignedTicket->fresh()->diserahkan_at);
        $this->assertNotNull($assignedTicket->fresh()->dikembalikan_at);
        $this->assertNull($unassignedGaTicket->fresh()->diserahkan_at);

        Carbon::setTestNow();
    }

    public function test_api_tickets_peminjaman_alat_tab_enforces_visibility_stage_counts_and_urgency_order(): void
    {
        $now = Carbon::create(2026, 11, 25, 10, 0, 0);
        Carbon::setTestNow($now);

        $superAdmin = Admin::factory()->create(['username' => 'superadmin_api', 'name' => 'Super Admin API']);
        $superAdmin->assignRole('Super Admin');

        // 1. Overdue (Belum Dikembalikan): diserahkan_at set, endDate in past -> urgency 0
        $overdueTicket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy()->subDays(5),
            endDate: $now->copy()->subDays(2),
            tools: ['Infokus']
        );
        $overdueTicket->update([
            'diserahkan_at' => $now->copy()->subDays(5),
            'diserahkan_oleh_admin_id' => $this->gaOperator->id,
            'penerima_alat' => $this->borrower->name,
            'kondisi_serah' => 'baik',
        ]);

        // 2. Waiting handover (Menunggu Diserahkan): future schedule -> urgency 1
        $waitingTicket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy()->addDays(2),
            endDate: $now->copy()->addDays(3),
            tools: ['Kabel HDMI']
        );

        // 3. Returned (Dikembalikan)
        $returnedTicket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy()->subDay(),
            endDate: $now->copy()->addDay(),
            tools: ['Tripod']
        );
        $returnedTicket->update([
            'diserahkan_at' => $now->copy()->subDay(),
            'dikembalikan_at' => $now->copy(),
            'kondisi_kembali' => 'baik',
        ]);

        // 4. Open (Menunggu Persetujuan) - appears only in 'semua'
        $openTicket = $this->createLoanTicket(
            status: 'open',
            startDate: $now->copy()->addDays(5),
            endDate: $now->copy()->addDays(6),
            tools: ['Sound System']
        );

        // 5. Self-submitted loan ticket by IT operator (non-GA)
        $selfSubmittedTicket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->otherOperator->id,
            'unit_id' => $this->gaUnit->id,
            'sub_unit_id' => $this->loanSubUnit->id,
            'assigned_admin_id' => $this->gaOperator->id,
            'status' => 'solve',
            'priority' => 'Sedang',
            'source' => 'mobile',
            'form_data' => [
                (string) $this->alatField->id => ['Microphone'],
                (string) $this->tglMulaiField->id => $now->copy()->addDays(4)->format('Y-m-d'),
                (string) $this->tglSelesaiField->id => $now->copy()->addDays(5)->format('Y-m-d'),
            ],
        ]);

        // GA operator sees tab=peminjaman_alat with accurate counts & urgency order (overdue first)
        Sanctum::actingAs($this->gaOperator, ['*']);
        $gaResponse = $this->getJson('/api/tickets?tab=peminjaman_alat&loan_stage=aktif')
            ->assertOk()
            ->assertJsonPath('meta.can_view_equipment_loans', true)
            ->assertJsonPath('meta.peminjaman_alat_count', 3)
            ->assertJsonPath('meta.current_tab', 'peminjaman_alat')
            ->assertJsonPath('meta.loan_stage', 'aktif')
            ->assertJsonPath('meta.loan_stage_counts.aktif', 3)
            ->assertJsonPath('meta.loan_stage_counts.menunggu_diserahkan', 2)
            ->assertJsonPath('meta.loan_stage_counts.sedang_dipinjam', 1)
            ->assertJsonPath('meta.loan_stage_counts.dikembalikan', 1)
            ->assertJsonPath('meta.loan_stage_counts.semua', 5)
            ->assertJsonCount(3, 'data');

        $this->assertSame($overdueTicket->formatted_id, $gaResponse->json('data.0.id'));
        $this->assertSame('Belum Dikembalikan', $gaResponse->json('data.0.status_peminjaman'));
        $this->assertSame($waitingTicket->formatted_id, $gaResponse->json('data.1.id'));
        $this->assertSame($selfSubmittedTicket->formatted_id, $gaResponse->json('data.2.id'));

        // Stage filter: sedang_dipinjam
        $this->getJson('/api/tickets?tab=peminjaman_alat&loan_stage=sedang_dipinjam')
            ->assertOk()
            ->assertJsonPath('meta.loan_stage', 'sedang_dipinjam')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdueTicket->formatted_id);

        // Stage filter: dikembalikan
        $this->getJson('/api/tickets?tab=peminjaman_alat&loan_stage=dikembalikan')
            ->assertOk()
            ->assertJsonPath('meta.loan_stage', 'dikembalikan')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $returnedTicket->formatted_id);

        // Stage filter: semua + search filter
        $this->getJson('/api/tickets?tab=peminjaman_alat&loan_stage=semua&search=Sound')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $openTicket->formatted_id);

        // SuperAdmin sees can_view_equipment_loans=true
        Sanctum::actingAs($superAdmin, ['*']);
        $this->getJson('/api/tickets?tab=peminjaman_alat')
            ->assertOk()
            ->assertJsonPath('meta.can_view_equipment_loans', true)
            ->assertJsonPath('meta.current_tab', 'peminjaman_alat')
            ->assertJsonPath('meta.peminjaman_alat_count', 3);

        // IT operator (who only self-submitted a loan ticket) gets can_view_equipment_loans=false & fallback current_tab='masuk'
        Sanctum::actingAs($this->otherOperator, ['*']);
        $this->getJson('/api/tickets?tab=peminjaman_alat')
            ->assertOk()
            ->assertJsonPath('meta.can_view_equipment_loans', false)
            ->assertJsonPath('meta.peminjaman_alat_count', 0)
            ->assertJsonPath('meta.current_tab', 'masuk');

        // Explicitly assigned non-GA operator sees can_view_equipment_loans=true & only assigned loan ticket
        $assignedTicket = Ticket::create([
            'user_id' => $this->borrower->id,
            'unit_id' => $this->gaUnit->id,
            'sub_unit_id' => $this->loanSubUnit->id,
            'assigned_admin_id' => $this->otherOperator->id,
            'status' => 'solve',
            'priority' => 'Sedang',
            'source' => 'mobile',
            'form_data' => [
                (string) $this->alatField->id => ['Webcam'],
                (string) $this->tglMulaiField->id => $now->copy()->addDays(1)->format('Y-m-d'),
                (string) $this->tglSelesaiField->id => $now->copy()->addDays(2)->format('Y-m-d'),
            ],
        ]);

        $this->getJson('/api/tickets?tab=peminjaman_alat&loan_stage=menunggu_diserahkan')
            ->assertOk()
            ->assertJsonPath('meta.can_view_equipment_loans', true)
            ->assertJsonPath('meta.current_tab', 'peminjaman_alat')
            ->assertJsonPath('meta.peminjaman_alat_count', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedTicket->formatted_id);

        Carbon::setTestNow();
    }

    public function test_user_can_report_equipment_return_via_web_sending_notification_without_whatsapp_and_prioritizing_monitor_and_tab(): void
    {
        Storage::fake('public');
        Notification::fake();

        $now = Carbon::create(2026, 11, 25, 11, 0, 0);
        Carbon::setTestNow($now);

        // Give gaOperator both fcm_token and no_wa to assert WhatsAppChannel is NEVER used
        $this->gaOperator->update([
            'fcm_token' => 'fake-fcm-token-ga',
            'no_wa' => '081234567890',
        ]);

        // An overdue active ticket (Belum Dikembalikan) to verify Menunggu Konfirmasi Kembali ranks higher
        $overdueTicket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy()->subDays(5),
            endDate: $now->copy()->subDays(2),
            tools: ['Kabel HDMI']
        );
        $overdueTicket->update([
            'diserahkan_at' => $now->copy()->subDays(5),
            'diserahkan_oleh_admin_id' => $this->gaOperator->id,
            'penerima_alat' => $this->borrower->name,
            'kondisi_serah' => 'baik',
        ]);

        $reportedTicket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy(),
            endDate: $now->copy()->addDay(),
            tools: ['Infokus']
        );
        $reportedTicket->update([
            'diserahkan_at' => $now->copy()->subHours(2),
            'diserahkan_oleh_admin_id' => $this->gaOperator->id,
            'penerima_alat_user_id' => $this->borrower->id,
            'penerima_alat' => $this->borrower->name,
            'kondisi_serah' => 'baik',
        ]);

        // Before reporting, user detail shows canReportReturn = true
        $this->actingAs($this->borrower, 'web')
            ->get(route('tiket.show', $reportedTicket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('User/Tiket/Detail')
                ->where('canReportReturn', true)
            );

        // Non-owner user receives 403
        $stranger = User::factory()->create(['username' => 'stranger_user', 'name' => 'Stranger']);
        $this->actingAs($stranger, 'web')
            ->post(route('tiket.lapor-kembali-alat', $reportedTicket->id), [
                'catatan_pengajuan_kembali' => 'Harus ditolak',
            ])
            ->assertForbidden();

        // Owner reports return with note & photo
        $photo = UploadedFile::fake()->image('bukti-kembali.jpg', 120, 120);
        $this->actingAs($this->borrower, 'web')
            ->post(route('tiket.lapor-kembali-alat', $reportedTicket->id), [
                'catatan_pengajuan_kembali' => 'Sudah ditaruh di meja piket GA',
                'lampiran' => [$photo],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reportedTicket->refresh();
        $this->assertNotNull($reportedTicket->pengembalian_diajukan_at);
        $this->assertNull($reportedTicket->dikembalikan_at);
        $this->assertSame('Sudah ditaruh di meja piket GA', $reportedTicket->catatan_pengajuan_kembali);

        $reportLog = TicketLog::where('ticket_id', $reportedTicket->id)
            ->where('aksi', 'pengajuan_kembali_alat')
            ->first();
        $this->assertNotNull($reportLog);
        $this->assertNull($reportLog->admin_id);
        $reportAttachment = TicketAttachment::where('ticket_log_id', $reportLog->id)->first();
        $this->assertNotNull($reportAttachment);
        Storage::disk('public')->assertExists($reportAttachment->file_path);

        // Notification sent to gaOperator (not otherOperator) and NEVER via WhatsAppChannel
        Notification::assertSentTo(
            $this->gaOperator,
            EquipmentReturnReportedNotification::class,
            function (EquipmentReturnReportedNotification $notification) {
                $channels = $notification->via($this->gaOperator);
                $db = $notification->toDatabase($this->gaOperator);
                $fcm = $notification->toFcm($this->gaOperator);

                return ! in_array(WhatsAppChannel::class, $channels, true)
                    && in_array('database', $channels, true)
                    && ($db['type'] ?? null) === 'equipment_return_reported'
                    && ($fcm['type'] ?? null) === 'equipment_return_reported';
            }
        );
        Notification::assertNotSentTo($this->otherOperator, EquipmentReturnReportedNotification::class);

        // Duplicate report is rejected
        $this->actingAs($this->borrower, 'web')
            ->post(route('tiket.lapor-kembali-alat', $reportedTicket->id), [
                'catatan_pengajuan_kembali' => 'Kirim ulang',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        // User detail now shows canReportReturn = false & loanAttachments.pengajuan_kembali = 1
        $this->actingAs($this->borrower, 'web')
            ->get(route('tiket.show', $reportedTicket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canReportReturn', false)
                ->where('loanSummary.status_peminjaman', 'Menunggu Konfirmasi Kembali')
                ->where('loanSummary.is_return_reported', true)
                ->has('loanAttachments.pengajuan_kembali', 1)
                ->where('loanAttachments.pengajuan_kembali.0.id', $reportAttachment->id)
            );

        // Admin Tab Peminjaman Alat prioritizes Menunggu Konfirmasi Kembali above Belum Dikembalikan
        $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'peminjaman_alat']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loanStageCounts.aktif', 2)
                ->where('loanStageCounts.menunggu_konfirmasi', 1)
                ->has('tickets.data', 2)
                ->where('tickets.data.0.id', $reportedTicket->id)
                ->where('tickets.data.0.loan_summary.status_peminjaman', 'Menunggu Konfirmasi Kembali')
                ->where('tickets.data.0.lampiran_pengajuan_kembali_count', 1)
                ->where('tickets.data.1.id', $overdueTicket->id)
            );

        // Admin Tab filter loan_stage=menunggu_konfirmasi
        $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'peminjaman_alat', 'loan_stage' => 'menunggu_konfirmasi']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loanStage', 'menunggu_konfirmasi')
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $reportedTicket->id)
            );

        // Live Monitor shows Menunggu Konfirmasi Kembali at the top of alatPinjam
        $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.monitor.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('alatPinjam.0.ticket_id', $reportedTicket->id)
                ->where('alatPinjam.0.status_pengembalian', 'Menunggu Konfirmasi Kembali')
                ->where('alatPinjam.0.catatan_pengajuan_kembali', 'Sudah ditaruh di meja piket GA')
                ->where('alatPinjam.0.lampiran_pengajuan_kembali_count', 1)
                ->where('alatPinjam.0.can_kembalikan', true)
            );

        // Operator confirms return -> official status becomes Dikembalikan
        $this->actingAs($this->gaOperator, 'admin')
            ->patch(route('admin.tiket.kembalikan-alat', $reportedTicket->id), [
                'kondisi_kembali' => 'baik',
                'catatan_kembali' => 'Sudah dicek lengkap',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $reportedTicket->refresh();
        $this->assertNotNull($reportedTicket->dikembalikan_at);

        $this->actingAs($this->gaOperator, 'admin')
            ->get(route('admin.tiket.show', $reportedTicket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loanSummary.status_peminjaman', 'Dikembalikan')
                ->where('loanSummary.is_return_reported', false)
            );

        Carbon::setTestNow();
    }

    public function test_non_handler_admin_requester_and_mobile_api_can_report_equipment_return(): void
    {
        Storage::fake('public');
        Notification::fake();

        $now = Carbon::create(2026, 11, 25, 13, 0, 0);
        Carbon::setTestNow($now);

        // 1. Non-GA admin requester creates a loan ticket handled by GA
        $adminTicket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->otherOperator->id,
            'unit_id' => $this->gaUnit->id,
            'sub_unit_id' => $this->loanSubUnit->id,
            'assigned_admin_id' => $this->gaOperator->id,
            'status' => 'solve',
            'priority' => 'Sedang',
            'source' => 'web',
            'form_data' => [
                (string) $this->alatField->id => ['Infokus'],
                (string) $this->tglMulaiField->id => $now->format('Y-m-d'),
                (string) $this->tglSelesaiField->id => $now->copy()->addDay()->format('Y-m-d'),
            ],
        ]);

        $this->actingAs($this->otherOperator, 'admin')
            ->get(route('admin.tiket.show', $adminTicket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManageEquipmentLoan', false)
                ->where('canReportEquipmentReturn', true)
            );

        // Handler admin cannot report return on behalf of requester via requester endpoint
        $this->actingAs($this->gaOperator, 'admin')
            ->post(route('admin.tiket.lapor-kembali-alat', $adminTicket->id), [
                'catatan_pengajuan_kembali' => 'Ditolak karena penangan',
            ])
            ->assertForbidden();

        // Non-handler admin requester reports return via Web Admin
        $this->actingAs($this->otherOperator, 'admin')
            ->post(route('admin.tiket.lapor-kembali-alat', $adminTicket->id), [
                'catatan_pengajuan_kembali' => 'Dikembalikan oleh tim IT',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $adminTicket->refresh();
        $this->assertNotNull($adminTicket->pengembalian_diajukan_at);
        $this->assertSame('Dikembalikan oleh tim IT', $adminTicket->catatan_pengajuan_kembali);

        $adminReportLog = TicketLog::where('ticket_id', $adminTicket->id)
            ->where('aksi', 'pengajuan_kembali_alat')
            ->first();
        $this->assertNotNull($adminReportLog);
        $this->assertSame($this->otherOperator->id, $adminReportLog->admin_id);

        // 2. Mobile API flow by borrower user
        $overdueTicket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy()->subDays(4),
            endDate: $now->copy()->subDay(),
            tools: ['Kabel HDMI']
        );
        $overdueTicket->update([
            'diserahkan_at' => $now->copy()->subDays(4),
            'diserahkan_oleh_admin_id' => $this->gaOperator->id,
            'penerima_alat' => $this->borrower->name,
        ]);

        $mobileTicket = $this->createLoanTicket(
            status: 'solve',
            startDate: $now->copy(),
            endDate: $now->copy()->addDays(2),
            tools: ['Infokus']
        );

        Sanctum::actingAs($this->borrower, ['*']);

        $this->getJson("/api/tickets/{$mobileTicket->id}")
            ->assertOk()
            ->assertJsonPath('data.can_lapor_kembali', true)
            ->assertJsonPath('data.pengembalian_diajukan_at', null)
            ->assertJsonCount(0, 'data.lampiran_pengajuan_kembali');

        $apiPhoto = UploadedFile::fake()->image('mobile-lapor.jpg', 100, 100);
        $apiResponse = $this->post("/api/tickets/{$mobileTicket->id}/lapor-kembali-alat", [
            'catatan_pengajuan_kembali' => 'Sudah taruh di resepsionis',
            'lampiran' => [$apiPhoto],
        ], ['Accept' => 'application/json'])->assertOk();

        $apiResponse
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_peminjaman', 'Menunggu Konfirmasi Kembali')
            ->assertJsonPath('data.can_lapor_kembali', false)
            ->assertJsonPath('data.catatan_pengajuan_kembali', 'Sudah taruh di resepsionis')
            ->assertJsonCount(1, 'data.lampiran_pengajuan_kembali');
        $this->assertNotNull($apiResponse->json('data.pengembalian_diajukan_at'));

        // Mobile API admin tab=peminjaman_alat filters & prioritizes Menunggu Konfirmasi Kembali
        Sanctum::actingAs($this->gaOperator, ['*']);

        $this->getJson('/api/tickets?tab=peminjaman_alat&loan_stage=menunggu_konfirmasi')
            ->assertOk()
            ->assertJsonPath('meta.loan_stage', 'menunggu_konfirmasi')
            ->assertJsonPath('meta.loan_stage_counts.menunggu_konfirmasi', 2)
            ->assertJsonCount(2, 'data');

        $activeApiList = $this->getJson('/api/tickets?tab=peminjaman_alat&loan_stage=aktif')
            ->assertOk();
        $this->assertSame('Menunggu Konfirmasi Kembali', $activeApiList->json('data.0.status_peminjaman'));
        $this->assertSame('Menunggu Konfirmasi Kembali', $activeApiList->json('data.1.status_peminjaman'));
        $this->assertSame('Belum Dikembalikan', $activeApiList->json('data.2.status_peminjaman'));

        Carbon::setTestNow();
    }
}
