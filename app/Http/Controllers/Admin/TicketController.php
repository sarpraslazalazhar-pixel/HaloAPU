<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Events\ActiveAssignedTicketChanged;
use App\Models\Admin;
use App\Models\Csat;
use App\Models\FormField;
use App\Models\OrgDivisi;
use App\Models\OrgUnit;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\Unit;
use App\Notifications\BrowserNotification;
use App\Notifications\TicketRatedAdminNotification;
use App\Notifications\TicketStatusUpdatedNotification;
use App\Services\AdminTicketCreator;
use App\Services\SlaCalculator;
use App\Services\SparcIntegrationService;
use App\Services\TicketChatBridgeService;
use App\Services\TicketFormEditor;
use App\Services\TicketSlaSchedule;
use App\Services\TicketStatusService;
use App\Services\TicketWorkQueue;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        TicketWorkQueue::syncOpenWorkHoursThrottled();

        $query = Ticket::with(['user.divisi', 'createdByAdmin', 'unit', 'subUnit', 'slaTracking', 'assignedAdmin', 'booking:id,ticket_id,status,tanggal_mulai,tanggal_selesai', 'csat:id,ticket_id,rating,komentar']);

        $admin = auth('admin')->user();
        $tab = $request->input('tab') === 'saya_ajukan' ? 'saya_ajukan' : 'masuk';
        if ($tab === 'saya_ajukan') {
            $query->submittedBy($admin);
        } else {
            // Tiket yang diajukan admin sendiri tetap harus terlihat di sini.
            $query->accessibleBy($admin);
        }

        // Tiket selesai (solve/reject/dibatalkan) tidak tampil di daftar Tiketing utama, melainkan di Laporan Tiket
        $activeStatuses = ['open', 'on_proses', 'pending', 'need_revision'];

        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }
        if ($request->filled('sub_unit_id')) {
            $query->where('sub_unit_id', $request->sub_unit_id);
        }
        if ($request->filled('status')) {
            $statuses = is_array($request->status) ? $request->status : [$request->status];
            $query->whereIn('status', $statuses);
        } elseif ($tab === 'masuk') {
            $query->whereIn('status', $activeStatuses);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('divisi_id')) {
            $query->where('divisi_id', $request->divisi_id);
        }
        if ($request->filled('org_unit_id')) {
            $query->where('org_unit_id', $request->org_unit_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('tickets.id', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                    ->orWhereHas('subUnit', fn ($s) => $s->where('nama_layanan', 'like', "%{$search}%"));
            });
        }

        $tickets = $query->latest()->paginate(10)->withQueryString();

        if ($tab === 'saya_ajukan') {
            $tickets->getCollection()->each(function (Ticket $t) use ($admin): void {
                $t->setAttribute('can_cancel', $t->canBeCancelledByAdmin($admin, isCreatorOverride: true));
            });
        }

        $units = Unit::aktif()->select('id', 'nama_unit')->orderBy('nama_unit')->get();
        $divisiList = OrgDivisi::select('id', 'nama_divisi')->orderBy('nama_divisi')->get();
        $orgUnitList = OrgUnit::select('id', 'nama_unit_organisasi')->orderBy('nama_unit_organisasi')->get();

        $activeCount = fn ($scope) => Ticket::query()->$scope($admin)->whereIn('status', $activeStatuses)->count();

        // Beban kerja tiap petugas. Detail tiket hanya untuk yang berhak menugaskan.
        $canSeeQueues = $admin->hasRole(['superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
            || $admin->isCoordinator()
            || $admin->hasPermissionTo('akses-assign-operator');

        $workHoursOpen = (new SlaCalculator)->workingHoursState(Carbon::now())['is_open'];

        $operatorLoads = Ticket::query()
            ->accessibleBy($admin)
            ->whereIn('status', $activeStatuses)
            ->whereNotNull('assigned_admin_id')
            ->with(['assignedAdmin:id,name,username', 'subUnit:id,nama_layanan'])
            ->get([
                'id',
                'assigned_admin_id',
                'status',
                'priority',
                'sub_unit_id',
                'assignment_queued_at',
                'assignment_resume_status',
                'work_hours_resume_status',
                'created_at',
            ])
            ->groupBy('assigned_admin_id')
            ->map(function ($rows) use ($canSeeQueues, $workHoursOpen) {
                $isQueued = fn ($t): bool => $t->status === 'pending'
                    && $t->assignment_queued_at !== null
                    && $t->assignment_resume_status !== null;

                $working = $rows->whereIn('status', TicketWorkQueue::ACTIVE_STATUSES)->values();
                $queued = $rows->filter($isQueued)
                    ->sortBy(fn ($t) => [
                        $t->priority === 'Urgen' ? 0 : 1,
                        (string) $t->assignment_queued_at,
                        (string) $t->created_at,
                        (int) $t->id,
                    ])
                    ->values();
                $paused = $rows->filter(fn ($t) => $t->status === 'pending')
                    ->reject($isQueued)
                    ->sortBy(fn ($t) => [
                        $t->work_hours_resume_status !== null ? 0 : 1,
                        $t->priority === 'Urgen' ? 0 : 1,
                        (string) $t->created_at,
                        (int) $t->id,
                    ])
                    ->values();

                $mapRow = fn ($t, ?int $position) => [
                    'id' => (int) $t->id,
                    'formatted_id' => $t->formatted_id,
                    'service' => $t->subUnit?->nama_layanan ?? 'Layanan tidak tersedia',
                    'status' => $t->status,
                    'urgent' => $t->priority === 'Urgen',
                    'position' => $position,
                    'is_work_hours_paused' => $position === null && $t->work_hours_resume_status !== null,
                    'is_manual_pending' => $position === null && $t->status === 'pending' && $t->work_hours_resume_status === null,
                    'waiting_for_work_hours' => $position !== null && ! $workHoursOpen,
                ];

                return [
                    'admin_id' => (int) $rows->first()->assigned_admin_id,
                    'name' => $rows->first()->assignedAdmin?->name ?? $rows->first()->assignedAdmin?->username ?? 'Petugas',
                    'total' => $rows->count(),
                    'working' => $working->count(),
                    'paused' => $paused->count(),
                    'queued' => $queued->count(),
                    'work_hours_open' => $workHoursOpen,
                    'tickets' => $canSeeQueues
                        ? $working->map(fn ($t) => $mapRow($t, null))
                            ->concat($queued->map(fn ($t, $i) => $mapRow($t, $i + 1)))
                            ->concat($paused->map(fn ($t) => $mapRow($t, null)))
                            ->values()->all()
                        : [],
                ];
            })
            ->sortBy('name')
            ->values();

        return Inertia::render('Admin/Tiketing/Index', [
            'tickets' => $tickets,
            'filters' => $request->only(['unit_id', 'sub_unit_id', 'status', 'date_from', 'date_to', 'divisi_id', 'org_unit_id', 'search']),
            'units' => $units,
            'divisiList' => $divisiList,
            'orgUnitList' => $orgUnitList,
            'currentTab' => $tab,
            'canBulkCreate' => $admin->hasRole(['Admin', 'superadmin', 'Super Admin']),
            'operatorLoads' => $operatorLoads,
            'tabCounts' => [
                'masuk' => $activeCount('accessibleBy'),
                'saya_ajukan' => $activeCount('submittedBy'),
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Tiketing/Create', [
            'unitList' => Unit::aktif()->orderBy('nama_unit')->get(),
        ]);
    }

    public function store(Request $request, AdminTicketCreator $creator)
    {
        if (is_string($request->form_data)) {
            $request->merge(['form_data' => json_decode($request->form_data, true) ?? []]);
        }

        $request->validate([
            'unit_id' => 'required|exists:units,id',
            'sub_unit_id' => 'required|exists:sub_units,id',
            'form_data' => 'required|array',
            'attachments' => 'nullable|array',
            'attachments.*' => 'nullable|array|max:3',
            'attachments.*.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
            'general_attachments' => 'nullable|array|max:3',
            'general_attachments.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
        ]);

        $formFields = FormField::where('sub_unit_id', $request->sub_unit_id)->get();
        foreach ($formFields as $field) {
            if (! $field->wajib || $field->isUpload() || $field->tipe_field === 'info_peraturan') {
                continue;
            }
            if ($field->parent_field_id) {
                $parentValue = $request->form_data[(string) $field->parent_field_id] ?? null;
                $normalize = fn (string $s): string => rtrim(strtolower(trim($s)), ': ');
                $triggerNorm = $normalize((string) $field->trigger_value);
                $isMatched = is_array($parentValue)
                    ? collect($parentValue)->contains(fn ($v) => $normalize((string) $v) === $triggerNorm)
                    : $normalize((string) $parentValue) === $triggerNorm;
                if (! $isMatched) {
                    continue;
                }
            }
            $fieldKey = (string) $field->id;
            if (empty($request->form_data[$fieldKey])) {
                return back()->withErrors([
                    'form_data.'.$fieldKey => "Field \"{$field->label}\" wajib diisi.",
                ]);
            }
        }

        $subUnit = SubUnit::findOrFail($request->sub_unit_id);
        $subUnit->load('formFields');
        $window = TicketSlaSchedule::window(TicketSlaSchedule::inputs($subUnit, $formFields, $request->form_data));
        $formDeadline = TicketSlaSchedule::extensionDeadline(TicketSlaSchedule::extensionInput($subUnit, $formFields, $request->form_data));

        $admin = auth('admin')->user();

        $ticket = $creator->create($admin, [[
            'data' => [
                'unit_id' => (int) $request->unit_id,
                'sub_unit_id' => (int) $request->sub_unit_id,
                'form_data' => $request->form_data,
                'attachments' => (array) $request->file('attachments'),
                'general_attachments' => (array) $request->file('general_attachments'),
            ],
            'subUnit' => $subUnit,
            'window' => $window,
            'formDeadline' => $formDeadline,
        ]])[0];

        $creator->notifyAdmins($ticket, $admin->id);

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.created');

        return redirect()->route('admin.tiket.index')->with('success', 'Tiket berhasil diajukan!');
    }

    public function activeAssigned(Request $request): JsonResponse
    {
        return response()->json([
            'data' => TicketWorkQueue::payload((int) $request->user('admin')->id),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Ticket $ticket)
    {
        $admin = auth('admin')->user();
        $canManage = $ticket->isAccessibleBy($admin);
        if (! $canManage && ! $admin->hasRole(['Admin', 'admin'])) {
            abort(403);
        }

        $ticket = TicketWorkQueue::syncTicketIfWorkHoursOpen($ticket);

        $ticket->load([
            'user', 'user.divisi', 'user.orgUnit', 'user.jabatan', 'createdByAdmin',
            'unit', 'subUnit', 'orgDivisi', 'orgUnit', 'jabatan',
            'attachments.field', 'attachments.log', 'attachments.log.admin', 'slaTracking',
            'booking', 'csat',
            'logs' => fn ($q) => $q->latest('timestamp'),
            'logs.admin',
            'logs.attachments',
            'editHistories.editor',
        ]);

        $formFields = FormField::where('sub_unit_id', $ticket->sub_unit_id)
            ->orderBy('urutan')
            ->get();

        $operators = Admin::whereHas('units', function ($query) use ($ticket) {
            $query->where('units.id', $ticket->unit_id);
        })->get(['id', 'name', 'username']);

        return Inertia::render('Admin/Tiketing/Detail', [
            'ticket' => $ticket,
            'formFields' => $formFields,
            'operators' => $operators,
            'isSubmittedByMe' => $ticket->isSubmittedBy($admin),
            'canManage' => $canManage,
            'canCancelTicket' => $ticket->canBeCancelledByAdmin($admin),
            'canEditForm' => $canManage && in_array(strtolower((string) $ticket->status), TicketFormEditor::EDITABLE_STATUSES, true),
            'canActivateFromQueue' => $canManage && $this->canManageQueueAction($admin, $ticket),
            'work_hours' => TicketWorkQueue::workHoursFor($ticket, $admin),
        ]);
    }

    public function updateStatus(Request $request, Ticket $ticket, SlaCalculator $slaCalculator)
    {
        $admin = auth('admin')->user();
        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengubah status tiket ini.');
        }

        $request->validate([
            'status' => 'required|string',
            'catatan' => 'nullable|string|max:1000',
            // Status changes no longer carry files: extra attachments go to chat.
            'general_attachments' => 'prohibited',
        ], [
            'general_attachments.prohibited' => 'Kirim lampiran tambahan melalui chat tiket.',
        ]);

        try {
            TicketStatusService::applyTransition(
                $ticket,
                $request->status,
                $request->catatan,
                $admin,
                []
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $queued = $ticket->assignment_queued_at !== null;

        return redirect()->back()->with(
            'success',
            $queued
                ? 'Tiket dimasukkan ke antrean petugas dan akan otomatis diproses saat giliran tiba.'
                : 'Status tiket berhasil diubah.'
        );
    }

    public function storeCsat(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! $admin || ! $ticket->isSubmittedBy($admin)) {
            abort(403, 'Hanya pengaju tiket yang dapat memberikan rating.');
        }

        if (! in_array(strtolower((string) $ticket->status), ['solve', 'selesai'], true)) {
            return back()->withErrors([
                'rating' => 'Rating hanya bisa diberikan untuk tiket yang sudah diselesaikan.',
            ]);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'komentar' => 'nullable|string|max:1000',
        ]);

        Csat::updateOrCreate(
            ['ticket_id' => $ticket->id],
            [
                'user_id' => $ticket->user_id,
                'admin_id' => $admin->id,
                'rating' => (int) $validated['rating'],
                'komentar' => $validated['komentar'] ?? null,
            ]
        );

        if ($ticket->status !== 'Selesai' && Ticket::whereKey($ticket->id)
            ->whereRaw('LOWER(status) IN (?, ?)', ['solve', 'selesai'])
            ->update(['status' => 'Selesai'])) {
            $ticket->status = 'Selesai';
        }

        try {
            $ticket->loadMissing('subUnit');
            $unitId = $ticket->subUnit?->unit_id ?? $ticket->unit_id;
            $assignedAdminId = $ticket->assigned_admin_id;

            $notifiedAdmins = Admin::query()
                ->where('id', '!=', $admin->id)
                ->where(function ($q) use ($unitId, $assignedAdminId) {
                    if ($unitId !== null) {
                        $q->whereHas('units', fn ($u) => $u->where('units.id', $unitId));
                    }
                    if ($assignedAdminId !== null) {
                        $q->orWhere('id', $assignedAdminId);
                    }
                    $q->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['superadmin', 'Super Admin', 'super_admin', 'Superadmin']));
                })
                ->get();

            if ($notifiedAdmins->isNotEmpty()) {
                \Illuminate\Support\Facades\Notification::send(
                    $notifiedAdmins,
                    new TicketRatedAdminNotification($ticket, (int) $validated['rating'], $validated['komentar'] ?? '')
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('Gagal mengirim notifikasi rating tiket admin: '.$e->getMessage());
        }

        return back()->with('success', 'Terima kasih atas rating kamu!');
    }

    private function canManageQueueAction(Admin $admin, Ticket $ticket): bool
    {
        return $admin->hasRole(['superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
            || $admin->isCoordinator()
            || $admin->checkPermissionTo('akses-assign-operator')
            || ($ticket->assigned_admin_id !== null && (int) $ticket->assigned_admin_id === (int) $admin->id);
    }

    public function enqueuePending(Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengelola tiket ini.');
        }

        if (! $this->canManageQueueAction($admin, $ticket)) {
            abort(403, 'Kamu tidak memiliki hak akses untuk memasukkan tiket ini ke antrean.');
        }

        try {
            $updated = TicketWorkQueue::enqueuePending($ticket, $admin);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $queued = $updated->assignment_queued_at !== null;

        return redirect()->back()->with(
            'success',
            $queued
                ? 'Tiket berhasil dimasukkan ke antrean petugas.'
                : 'Slot petugas kosong; tiket langsung diaktifkan untuk dikerjakan.'
        );
    }

    public function activateFromQueue(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengelola tiket ini.');
        }

        if (! $this->canManageQueueAction($admin, $ticket)) {
            abort(403, 'Kamu tidak memiliki hak akses untuk menukar tiket antrean ini.');
        }

        try {
            TicketWorkQueue::swapWithActive($ticket, $admin);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Tiket antrean berhasil dijadikan tiket aktif.');
    }

    public function resumeWork(Ticket $ticket, SlaCalculator $slaCalculator)
    {
        $admin = auth('admin')->user();
        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak melanjutkan tiket ini.');
        }
        if ($ticket->assigned_admin_id === null || (int) $ticket->assigned_admin_id !== (int) $admin->id) {
            abort(403, 'Hanya petugas yang ditugaskan yang dapat melanjutkan tiket ini.');
        }

        try {
            TicketWorkQueue::resumeAfterHours($ticket, $admin, $slaCalculator);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Tiket dilanjutkan.');
    }

    public function updateForm(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengedit formulir tiket ini.');
        }

        $validated = $request->validate([
            'form_data' => 'required|array',
            'priority' => 'nullable|string',
            'edit_reason' => 'required|string|min:5|max:500',
        ]);

        $history = TicketFormEditor::apply(
            $ticket,
            $validated['form_data'],
            $validated['priority'] ?? null,
            $validated['edit_reason'],
            $admin
        );

        if (! $history) {
            return redirect()->back()->with('error', 'Tidak ada data yang berubah.');
        }

        return redirect()->back()->with('success', 'Formulir tiket berhasil diperbarui.');
    }

    public function updatePriority(Request $request, Ticket $ticket, SlaCalculator $slaCalculator)
    {
        if (! $ticket->isAccessibleBy(auth('admin')->user())) {
            abort(403, 'Kamu tidak berhak mengubah prioritas tiket ini.');
        }

        $request->validate([
            'priority' => 'required|string|in:Rendah,Sedang,Tinggi,Urgen',
        ]);

        DB::transaction(function () use ($ticket, $request, $slaCalculator) {
            $ticket = Ticket::lockForUpdate()->findOrFail($ticket->id);
            $oldPriority = $ticket->priority;
            $newPriority = $request->priority;
            if ($oldPriority === $newPriority) {
                return;
            }

            $ticket->update(['priority' => $newPriority]);

            $sla = $ticket->slaTracking()->lockForUpdate()->first();
            if ($sla) {
                $sla->setRelation('ticket', $ticket);
                $slaCalculator->updatePriorityDeadlines($sla);
            }

            TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => auth('admin')->id(),
                'aksi' => 'update_priority',
                'catatan' => 'Prioritas diubah dari '.($oldPriority ?? 'Belum diset')." menjadi $newPriority",
            ]);

            // Antrean diurutkan per prioritas, jadi posisi petugas bisa berubah.
            if ($ticket->status === 'pending' && $ticket->assigned_admin_id !== null) {
                $adminId = (int) $ticket->assigned_admin_id;
                DB::afterCommit(function () use ($adminId) {
                    try {
                        broadcast(new ActiveAssignedTicketChanged($adminId));
                    } catch (\Throwable $e) {
                        \Log::warning("Gagal mengirim pembaruan antrean untuk petugas #{$adminId}: ".$e->getMessage());
                    }
                });
            }
        });

        return redirect()->back()->with('success', 'Prioritas tiket berhasil diatur.');
    }

    public function downloadAttachment(TicketAttachment $attachment)
    {
        $admin = auth('admin')->user();
        $ticket = $attachment->ticket;
        if (! $ticket || (! $admin->hasRole(['Admin', 'admin']) && ! $ticket->isAccessibleBy($admin))) {
            abort(403, 'Kamu tidak berhak mengakses lampiran ini.');
        }

        if (! Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }

    public function viewAttachment(TicketAttachment $attachment)
    {
        $admin = auth('admin')->user();
        $ticket = $attachment->ticket;
        if (! $ticket || (! $admin->hasRole(['Admin', 'admin']) && ! $ticket->isAccessibleBy($admin))) {
            abort(403, 'Kamu tidak berhak mengakses lampiran ini.');
        }

        if (! Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        $headers = [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.$attachment->original_name.'"',
        ];

        return response()->file(Storage::disk('public')->path($attachment->file_path), $headers);
    }

    public function assignOperator(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak menugaskan tiket ini.');
        }

        if (! $admin->hasRole(['superadmin', 'Super Admin']) && ! $admin->isCoordinator()) {
            abort(403, 'Kamu tidak memiliki hak akses untuk menugaskan operator.');
        }

        $request->validate([
            'assigned_admin_id' => 'required|exists:admins,id',
        ]);

        $operator = Admin::find($request->assigned_admin_id);
        if (! $operator) {
            return redirect()->back()->with('error', 'Petugas tidak ditemukan.');
        }

        if (! $operator->units()->where('units.id', $ticket->unit_id)->exists()) {
            return redirect()->back()->with('error', 'Operator tidak terdaftar pada unit layanan ini.');
        }

        try {
            $assigned = TicketWorkQueue::assign($ticket, $operator, $admin);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $queued = $assigned->assignment_queued_at !== null && (int) $ticket->assigned_admin_id !== (int) $operator->id;

        return redirect()->back()->with('success', $queued ? 'Tiket masuk antrean petugas.' : 'Operator berhasil ditugaskan.');
    }

    public function moveInQueue(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();

        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengubah antrean tiket ini.');
        }

        $validated = $request->validate([
            'direction' => 'required|in:up,down',
        ]);

        try {
            TicketWorkQueue::moveInQueue($ticket, $validated['direction'] === 'up' ? -1 : 1);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Urutan antrean diperbarui.');
    }

    public function cancel(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = $admin->hasRole(['superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
            || str_contains(strtolower($admin->username ?? ''), 'superadmin');
        $isCreator = $ticket->isSubmittedBy($admin);

        if (! $isSuperAdmin && ! $isCreator) {
            abort(403, 'Hanya pengaju tiket yang dapat membatalkan tiket ini.');
        }

        $targetStatus = $isCreator ? 'dibatalkan' : 'reject';
        $catatan = $isCreator
            ? 'Tiket dibatalkan oleh pengaju ('.($admin->name ?? $admin->username).')'
            : 'Tiket ditolak/dibatalkan oleh Admin '.($admin->name ?? $admin->username);

        try {
            $ticket = TicketWorkQueue::mutate($ticket, null, function (Ticket $locked, Carbon $at) use ($admin, $isCreator, $targetStatus, $catatan) {
                $locked->loadMissing('booking');

                if (in_array(strtolower((string) $locked->status), ['selesai', 'solve', 'dibatalkan', 'reject'], true)) {
                    throw new \InvalidArgumentException('Tiket yang sudah selesai, ditolak, atau dibatalkan tidak dapat dibatalkan.');
                }

                if ($locked->booking?->tanggal_selesai && $at->gte($locked->booking->tanggal_selesai)) {
                    throw new \InvalidArgumentException('Masa peminjaman telah berakhir, tidak dapat dibatalkan.');
                }

                if (! $isCreator && ! $locked->isOpenForCancellation()) {
                    throw new \InvalidArgumentException('Tiket hanya dapat dibatalkan saat berstatus open.');
                }

                $locked->update(['status' => $targetStatus]);
                $locked->booking?->update(['status' => $targetStatus]);

                TicketLog::create([
                    'ticket_id' => $locked->id,
                    'admin_id' => $admin->id,
                    'aksi' => $targetStatus,
                    'catatan' => $catatan,
                    'timestamp' => $at,
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        if ($isCreator) {
            try {
                $ticket->load('subUnit.unit.admins', 'assignedAdmin');
                $actorName = $admin->name ?? $admin->username ?? 'Admin';
                $recipients = collect();

                if ($ticket->assignedAdmin) {
                    $recipients->push($ticket->assignedAdmin);
                }

                if ($ticket->subUnit?->unit?->admins) {
                    $recipients = $recipients->merge($ticket->subUnit->unit->admins);
                }

                $recipients
                    ->unique('id')
                    ->reject(fn (Admin $recipient) => (int) $recipient->id === (int) $admin->id)
                    ->each(function (Admin $recipient) use ($actorName, $ticket): void {
                        $recipient->notify(new BrowserNotification(
                            'Tiket Dibatalkan',
                            "{$actorName} membatalkan tiket #{$ticket->formatted_id}",
                            "/admin/tiket/{$ticket->id}"
                        ));
                    });
            } catch (\Throwable $e) {
                \Log::error('Gagal kirim notif batal creator admin: '.$e->getMessage());
            }
        } else {
            try {
                $ticket->load('user');
                if ($ticket->user) {
                    $ticket->user->notify(new TicketStatusUpdatedNotification($ticket, $catatan));
                }
            } catch (\Exception $e) {
                \Log::error('Gagal kirim notif reject/cancel admin: '.$e->getMessage());
            }
        }

        app(SparcIntegrationService::class)->notifyIfEligible(
            $ticket,
            $isCreator ? 'ticket.cancelled' : 'ticket.status_updated'
        );

        return redirect()->back()->with(
            'success',
            $isCreator ? 'Tiket berhasil dibatalkan.' : 'Tiket berhasil ditolak.'
        );
    }

    public function cancelBooking(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak membatalkan booking tiket ini.');
        }

        $rawService = $ticket->subUnit?->nama_layanan ?? $ticket->booking?->tipe ?? 'Aset';
        $layananTitle = preg_match('/^peminjaman\s+/i', $rawService)
            ? $rawService
            : (preg_match('/^penggunaan\s+/i', $rawService)
                ? preg_replace('/^penggunaan\s+/i', 'Peminjaman ', $rawService)
                : "Peminjaman {$rawService}");

        $isCreator = $ticket->isSubmittedBy($admin);
        $targetStatus = $isCreator ? 'dibatalkan' : 'reject';

        $alasan = $request->input('catatan') ?: (
            $isCreator
                ? "{$layananTitle} dibatalkan oleh pengaju dan aset telah dibebaskan."
                : "{$layananTitle} ditolak oleh Admin dan aset telah dibebaskan."
        );

        try {
            $ticket = TicketWorkQueue::mutate($ticket, null, function (Ticket $locked, Carbon $at) use ($admin, $targetStatus, $alasan) {
                $booking = $locked->booking;
                if (! $booking) {
                    throw new \InvalidArgumentException('Tidak ada data booking untuk tiket ini.');
                }
                if ($booking->tanggal_selesai && $at->gt($booking->tanggal_selesai)) {
                    throw new \InvalidArgumentException('Masa peminjaman telah berakhir, tidak dapat dibatalkan.');
                }

                $booking->update(['status' => $targetStatus]);
                $locked->update(['status' => $targetStatus]);

                TicketLog::create([
                    'ticket_id' => $locked->id,
                    'admin_id' => $admin->id,
                    'aksi' => $targetStatus,
                    'catatan' => $alasan,
                    'timestamp' => $at,
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        try {
            $ticket->load('user');
            if ($ticket->user) {
                $ticket->user->notify(new TicketStatusUpdatedNotification($ticket, $alasan));
                $ticket->user->notify(new BrowserNotification(
                    $isCreator ? 'Peminjaman Dibatalkan' : 'Peminjaman Ditolak',
                    "{$layananTitle} tiket #{$ticket->formatted_id} telah ".($isCreator ? 'dibatalkan' : 'ditolak')." oleh Admin. Kini tersedia kembali di Live Monitor.",
                    "/tiket/{$ticket->id}"
                ));
            }
        } catch (\Exception $e) {
            \Log::error('Gagal kirim notif cancel booking admin: '.$e->getMessage());
        }

        app(SparcIntegrationService::class)->notifyIfEligible(
            $ticket,
            $isCreator ? 'ticket.cancelled' : 'ticket.status_updated'
        );

        return redirect()->back()->with(
            'success',
            $isCreator
                ? 'Booking berhasil dibatalkan. Aset seketika tersedia kembali di Live Monitor.'
                : 'Booking ditolak. Aset seketika tersedia kembali di Live Monitor.'
        );
    }

    public function kembalikanAlat(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengakses tiket ini.');
        }

        if (! in_array(strtolower($ticket->status), ['solve', 'selesai'])) {
            return redirect()->back()->with('error', 'Tiket harus berstatus selesai terlebih dahulu sebelum alat dapat dikembalikan.');
        }

        $isWajibKembali = $ticket->subUnit?->wajib_kembali
            || str_contains(strtolower($ticket->subUnit?->nama_layanan ?? ''), 'peminjaman alat')
            || str_contains(strtolower($ticket->subUnit?->nama_layanan ?? ''), 'alat');

        if (! $isWajibKembali) {
            return redirect()->back()->with('error', 'Jenis layanan ini tidak memerlukan pengembalian alat.');
        }

        $validated = $request->validate([
            'kondisi_kembali' => 'nullable|string|in:baik,rusak,tidak_lengkap',
            'catatan_kembali' => 'nullable|string|max:1000',
        ]);

        $kondisi = $validated['kondisi_kembali'] ?? 'baik';
        $catatan = ! empty($validated['catatan_kembali']) ? trim($validated['catatan_kembali']) : null;

        $ticket->update([
            'dikembalikan_at' => now(),
            'kondisi_kembali' => $kondisi,
            'catatan_kembali' => $catatan,
        ]);

        $kondisiLabel = match ($kondisi) {
            'rusak' => 'Rusak',
            'tidak_lengkap' => 'Tidak Lengkap (Aksesoris Kurang)',
            default => 'Baik (Normal)',
        };

        $logCatatan = 'Alat dikembalikan [Kondisi: '.$kondisiLabel.'], ditandai oleh Admin '.($admin->name ?: $admin->username);
        if ($catatan) {
            $logCatatan .= ' — Catatan: '.$catatan;
        }

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $admin->id,
            'aksi' => 'alat_dikembalikan',
            'catatan' => $logCatatan,
        ]);

        return redirect()->back()->with('success', 'Alat berhasil ditandai sudah dikembalikan.');
    }

    public function reply(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak membalas tiket ini.');
        }

        if (in_array($ticket->status, ['solve', 'selesai', 'reject', 'dibatalkan'])) {
            return redirect()->back()->with('error', 'Tidak dapat membalas tiket yang sudah selesai atau dibatalkan.');
        }

        $request->validate([
            'catatan' => 'required_without:general_attachments|nullable|string|max:1000',
            'general_attachments' => 'nullable|array|max:3',
            'general_attachments.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
        ]);

        $storedPaths = [];

        try {
            $log = DB::transaction(function () use ($ticket, $admin, $request, &$storedPaths) {
                $log = TicketChatBridgeService::createReplyLog(
                    $ticket,
                    $admin,
                    filled($request->catatan) ? $request->catatan : '[Lampiran Tiket]'
                );

                $generalFiles = $request->file('general_attachments');
                if (! empty($generalFiles) && is_array($generalFiles)) {
                    foreach ($generalFiles as $file) {
                        if (! $file || ! is_a($file, UploadedFile::class) || ! $file->isValid()) {
                            continue;
                        }

                        $path = Storage::disk('public')->putFileAs(
                            "ticket-attachments/{$ticket->id}",
                            $file->getPathname(),
                            $file->hashName()
                        );
                        $storedPaths[] = $path;

                        TicketAttachment::create([
                            'ticket_id' => $ticket->id,
                            'field_id' => null,
                            'ticket_log_id' => $log->id,
                            'file_path' => $path,
                            'original_name' => $file->getClientOriginalName(),
                            'mime_type' => $file->getMimeType(),
                            'file_size' => $file->getSize(),
                            'wajib' => false,
                        ]);
                    }
                }

                $log->load('attachments');
                TicketChatBridgeService::syncTicketReplyToChat($ticket, $log);

                return $log;
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($storedPaths);

            throw $e;
        }

        TicketChatBridgeService::afterCommit(fn () => $log->broadcastTimeline());

        TicketChatBridgeService::notifyReplyRecipients($ticket->loadMissing('user'), $admin, $log->catatan);

        try {
            if ($ticket->user) {
                $ticket->user->notify(new BrowserNotification(
                    "Balasan Baru Tiket #{$ticket->formatted_id}",
                    'Admin '.($admin->name ?? $admin->username).' membalas tiket Anda: '.Str::limit($log->catatan, 80),
                    "/tiket/{$ticket->id}"
                ));
            }
        } catch (\Throwable $e) {
            \Log::error('Gagal kirim notif balasan admin: '.$e->getMessage());
        }

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.replied');

        return redirect()->back()->with('success', 'Balasan berhasil dikirim.');
    }
}
