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
use App\Services\DocumentContentService;
use App\Services\EquipmentLoanService;
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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        TicketWorkQueue::syncOpenWorkHoursThrottled();

        $query = Ticket::with(['user.divisi', 'createdByAdmin', 'unit', 'subUnit', 'slaTracking', 'assignedAdmin', 'booking:id,ticket_id,status,tanggal_mulai,tanggal_selesai', 'csat:id,ticket_id,rating,komentar']);

        $admin = auth('admin')->user();
        $canViewEquipmentLoans = EquipmentLoanService::canViewLoanTab($admin);
        $requestedTab = $request->input('tab');
        $tab = in_array($requestedTab, ['masuk', 'saya_ajukan', 'peminjaman_alat'], true)
            ? $requestedTab
            : 'masuk';
        if ($tab === 'peminjaman_alat' && ! $canViewEquipmentLoans) {
            $tab = 'masuk';
        }

        $activeStatuses = ['open', 'on_proses', 'pending', 'need_revision'];
        $assignment = $request->input('assignment');
        $loanStage = null;
        $loanStageCounts = [];

        if ($tab === 'peminjaman_alat') {
            $loanBaseQuery = EquipmentLoanService::scopeManageableLoanTickets(Ticket::query(), $admin)
                ->whereNotIn('status', ['reject', 'dibatalkan']);

            if ($request->filled('unit_id')) {
                $loanBaseQuery->where('unit_id', $request->unit_id);
            }
            if ($request->filled('sub_unit_id')) {
                $loanBaseQuery->where('sub_unit_id', $request->sub_unit_id);
            }
            if ($request->filled('date_from')) {
                $loanBaseQuery->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $loanBaseQuery->whereDate('created_at', '<=', $request->date_to);
            }
            if ($request->filled('divisi_id')) {
                $loanBaseQuery->where('divisi_id', $request->divisi_id);
            }
            if ($request->filled('org_unit_id')) {
                $loanBaseQuery->where('org_unit_id', $request->org_unit_id);
            }
            if ($request->filled('search')) {
                $search = $request->search;
                $loanBaseQuery->where(function ($q) use ($search) {
                    $q->where('tickets.id', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                        ->orWhereHas('createdByAdmin', fn ($a) => $a->where('username', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                        ->orWhereHas('subUnit', fn ($s) => $s->where('nama_layanan', 'like', "%{$search}%"));
                });
            }

            $assignedToMeCount = (clone $loanBaseQuery)
                ->where('tickets.assigned_admin_id', $admin->id)
                ->count();

            if ($assignment === 'me') {
                $loanBaseQuery->where('tickets.assigned_admin_id', $admin->id);
            } elseif ($assignment === 'unassigned') {
                $loanBaseQuery->whereNull('tickets.assigned_admin_id');
            } elseif (is_scalar($assignment) && ctype_digit((string) $assignment) && (int) $assignment > 0) {
                $loanBaseQuery->where('tickets.assigned_admin_id', (int) $assignment);
            }

            $finishedFilter = fn ($q) => $q->whereIn(DB::raw('LOWER(tickets.status)'), Ticket::WORKLOAD_FINISHED_STATUSES);

            $loanStageCounts = [
                'aktif' => $finishedFilter(clone $loanBaseQuery)->whereNull('dikembalikan_at')->count(),
                'menunggu_konfirmasi' => $finishedFilter(clone $loanBaseQuery)->whereNotNull('pengembalian_diajukan_at')->whereNull('dikembalikan_at')->count(),
                'menunggu_diserahkan' => $finishedFilter(clone $loanBaseQuery)->whereNull('diserahkan_at')->whereNull('dikembalikan_at')->count(),
                'sedang_dipinjam' => $finishedFilter(clone $loanBaseQuery)->whereNotNull('diserahkan_at')->whereNull('dikembalikan_at')->count(),
                'dikembalikan' => (clone $loanBaseQuery)->whereNotNull('dikembalikan_at')->count(),
                'semua' => (clone $loanBaseQuery)->count(),
            ];

            $requestedStage = $request->input('loan_stage');
            $loanStage = in_array($requestedStage, ['aktif', 'menunggu_konfirmasi', 'menunggu_diserahkan', 'sedang_dipinjam', 'dikembalikan', 'semua'], true)
                ? $requestedStage
                : 'aktif';

            $loanQuery = (clone $loanBaseQuery)->with([
                'user.divisi',
                'createdByAdmin',
                'unit',
                'subUnit.formFields',
                'slaTracking',
                'assignedAdmin',
                'diserahkanOlehAdmin:id,name,username',
                'penerimaAlatUser:id,name,username',
                'diterimaKembaliOlehAdmin:id,name,username',
                'logs' => fn ($q) => $q->whereIn('aksi', ['alat_diserahkan', 'pengajuan_kembali_alat', 'alat_dikembalikan'])->with('attachments'),
                'booking:id,ticket_id,status,tanggal_mulai,tanggal_selesai',
                'csat:id,ticket_id,rating,komentar',
            ]);

            if ($loanStage === 'aktif') {
                $finishedFilter($loanQuery)->whereNull('dikembalikan_at');
            } elseif ($loanStage === 'menunggu_konfirmasi') {
                $finishedFilter($loanQuery)->whereNotNull('pengembalian_diajukan_at')->whereNull('dikembalikan_at');
            } elseif ($loanStage === 'menunggu_diserahkan') {
                $finishedFilter($loanQuery)->whereNull('diserahkan_at')->whereNull('dikembalikan_at');
            } elseif ($loanStage === 'sedang_dipinjam') {
                $finishedFilter($loanQuery)->whereNotNull('diserahkan_at')->whereNull('dikembalikan_at');
            } elseif ($loanStage === 'dikembalikan') {
                $loanQuery->whereNotNull('dikembalikan_at');
            }

            $now = Carbon::now();
            $loanItems = $loanQuery->get()->map(function (Ticket $t) use ($now): Ticket {
                $summary = EquipmentLoanService::extractLoanSummary($t, $now);
                $loanAttachments = EquipmentLoanService::extractLoanAttachments($t);
                $isFinished = EquipmentLoanService::isFinishedStatus($t->status);

                $t->setAttribute('loan_summary', $summary);
                $t->setAttribute('lampiran_serah_count', count($loanAttachments['serah']));
                $t->setAttribute('lampiran_pengajuan_kembali_count', count($loanAttachments['pengajuan_kembali']));
                $t->setAttribute('lampiran_kembali_count', count($loanAttachments['kembali']));
                $t->setAttribute('can_serahkan', $isFinished && $t->diserahkan_at === null && $t->dikembalikan_at === null);
                $t->setAttribute('can_kembalikan', $isFinished && $t->dikembalikan_at === null);
                $t->unsetRelation('logs');

                return $t;
            });

            if (in_array($loanStage, ['aktif', 'menunggu_konfirmasi', 'menunggu_diserahkan', 'sedang_dipinjam'], true)) {
                $sortedLoanItems = $loanItems->sort(function (Ticket $a, Ticket $b): int {
                    $aSummary = $a->getAttribute('loan_summary');
                    $bSummary = $b->getAttribute('loan_summary');
                    $urgencyRank = function (?array $summary): int {
                        return match ($summary['status_peminjaman'] ?? '') {
                            EquipmentLoanService::STATUS_MENUNGGU_KONFIRMASI_KEMBALI => 0,
                            EquipmentLoanService::STATUS_BELUM_DIKEMBALIKAN => 1,
                            default => 2,
                        };
                    };
                    $aUrgency = $urgencyRank($aSummary);
                    $bUrgency = $urgencyRank($bSummary);

                    if ($aUrgency !== $bUrgency) {
                        return $aUrgency <=> $bUrgency;
                    }

                    $aSort = (int) ($aSummary['sort_date'] ?? 0);
                    $bSort = (int) ($bSummary['sort_date'] ?? 0);
                    if ($aSort !== $bSort) {
                        return $aSort <=> $bSort;
                    }

                    return $a->id <=> $b->id;
                })->values();
            } else {
                $sortedLoanItems = $loanItems->sortByDesc(function (Ticket $t): array {
                    return [
                        ($t->dikembalikan_at ?? $t->created_at)?->timestamp ?? 0,
                        $t->created_at?->timestamp ?? 0,
                        $t->id,
                    ];
                })->values();
            }

            $perPage = 10;
            $currentPage = LengthAwarePaginator::resolveCurrentPage();
            $tickets = (new LengthAwarePaginator(
                $sortedLoanItems->slice(($currentPage - 1) * $perPage, $perPage)->values(),
                $sortedLoanItems->count(),
                $perPage,
                $currentPage,
                ['path' => $request->url()]
            ))->withQueryString();
        } else {
            if ($tab === 'saya_ajukan') {
                $query->submittedBy($admin);
            } else {
                // Tiket yang diajukan admin sendiri tetap harus terlihat di sini.
                $query->accessibleBy($admin);
            }

            // Tiket selesai (solve/reject/dibatalkan) tidak tampil di daftar Tiketing utama, melainkan di Laporan Tiket
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

            $assignedToMeCount = (clone $query)
                ->where('tickets.assigned_admin_id', $admin->id)
                ->count();

            if ($assignment === 'me') {
                $query->where('tickets.assigned_admin_id', $admin->id);
            } elseif ($assignment === 'unassigned') {
                $query->whereNull('tickets.assigned_admin_id');
            } elseif (is_scalar($assignment) && ctype_digit((string) $assignment) && (int) $assignment > 0) {
                $query->where('tickets.assigned_admin_id', (int) $assignment);
            }

            $tickets = $query->latest()->paginate(10)->withQueryString();

            if ($tab === 'saya_ajukan') {
                $tickets->getCollection()->each(function (Ticket $t) use ($admin): void {
                    $t->setAttribute('can_cancel', $t->canBeCancelledByAdmin($admin, isCreatorOverride: true));
                });
            }
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

        $isSuperAdmin = $admin->hasRole(['superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
            || str_contains(strtolower($admin->username ?? ''), 'superadmin');

        if ($isSuperAdmin) {
            $operators = Admin::query()->get(['id', 'name', 'username']);
        } else {
            $adminUnitIds = $admin->units()->pluck('units.id')->filter()->all();
            $adminSubUnits = $admin->subUnits()->get(['sub_units.id', 'sub_units.unit_id']);
            $subUnitIds = $adminSubUnits->pluck('id')->filter()->all();
            $unitIds = array_values(array_unique(array_merge(
                $adminUnitIds,
                $adminSubUnits->pluck('unit_id')->filter()->all(),
            )));

            $assignedIds = Ticket::query()
                ->where(function ($q) use ($admin) {
                    $q->accessibleBy($admin)->orWhere(fn ($sub) => $sub->submittedBy($admin));
                })
                ->whereNotNull('assigned_admin_id')
                ->distinct()
                ->pluck('assigned_admin_id')
                ->filter()
                ->all();

            $operators = Admin::query()
                ->where(function ($q) use ($admin, $unitIds, $subUnitIds, $assignedIds) {
                    $q->where('id', $admin->id);
                    if (! empty($unitIds)) {
                        $q->orWhereHas('units', fn ($u) => $u->whereIn('units.id', $unitIds));
                    }
                    if (! empty($subUnitIds)) {
                        $q->orWhereHas('subUnits', fn ($su) => $su->whereIn('sub_units.id', $subUnitIds));
                    }
                    if (! empty($assignedIds)) {
                        $q->orWhereIn('id', $assignedIds);
                    }
                })
                ->get(['id', 'name', 'username']);
        }

        $operators = $operators
            ->sortBy(fn (Admin $op) => strtolower($op->name ?: $op->username))
            ->values();

        $peminjamanAlatCount = $canViewEquipmentLoans
            ? EquipmentLoanService::scopeManageableLoanTickets(Ticket::query(), $admin)
                ->whereIn(DB::raw('LOWER(tickets.status)'), Ticket::WORKLOAD_FINISHED_STATUSES)
                ->whereNull('dikembalikan_at')
                ->count()
            : 0;

        return Inertia::render('Admin/Tiketing/Index', [
            'tickets' => $tickets,
            'filters' => $request->only(['unit_id', 'sub_unit_id', 'status', 'date_from', 'date_to', 'divisi_id', 'org_unit_id', 'search', 'assignment', 'loan_stage']),
            'units' => $units,
            'divisiList' => $divisiList,
            'orgUnitList' => $orgUnitList,
            'operators' => $operators,
            'currentTab' => $tab,
            'canViewEquipmentLoans' => $canViewEquipmentLoans,
            'loanStage' => $loanStage,
            'loanStageCounts' => $loanStageCounts,
            'canBulkCreate' => $admin->hasRole(['Admin', 'superadmin', 'Super Admin']),
            'operatorLoads' => $operatorLoads,
            'assignedToMeCount' => $assignedToMeCount,
            'tabCounts' => [
                'masuk' => $activeCount('accessibleBy'),
                'saya_ajukan' => $activeCount('submittedBy'),
                'peminjaman_alat' => $peminjamanAlatCount,
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
            'attachments.*.*' => 'file|max:3072|extensions:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,csv|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,csv,txt',
            'general_attachments' => 'nullable|array|max:3',
            'general_attachments.*' => 'file|max:3072|extensions:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,csv|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,csv,txt',
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
            'user', 'user.divisi', 'user.orgUnit', 'user.jabatan', 'createdByAdmin', 'assignedAdmin:id,name,username',
            'diserahkanOlehAdmin:id,name,username', 'penerimaAlatUser:id,name,username', 'diterimaKembaliOlehAdmin:id,name,username',
            'unit', 'subUnit.formFields', 'orgDivisi', 'orgUnit', 'jabatan',
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

        $isEquipmentLoan = EquipmentLoanService::isLoanTicket($ticket);

        return Inertia::render('Admin/Tiketing/Detail', [
            'ticket' => $ticket,
            'formFields' => $formFields,
            'operators' => $operators,
            'isSubmittedByMe' => $ticket->isSubmittedBy($admin),
            'isAssignedToMe' => $ticket->assigned_admin_id !== null && (int) $ticket->assigned_admin_id === (int) $admin->id,
            'canManage' => $canManage,
            'canCancelTicket' => $ticket->canBeCancelledByAdmin($admin),
            'canEditForm' => $canManage && in_array(strtolower((string) $ticket->status), TicketFormEditor::EDITABLE_STATUSES, true),
            'canActivateFromQueue' => $canManage && $this->canManageQueueAction($admin, $ticket),
            'work_hours' => TicketWorkQueue::workHoursFor($ticket, $admin),
            'isEquipmentLoan' => $isEquipmentLoan,
            'canManageEquipmentLoan' => $isEquipmentLoan && EquipmentLoanService::canManageLoanTicket($ticket, $admin),
            'canReportEquipmentReturn' => $isEquipmentLoan && EquipmentLoanService::canReportReturn($ticket, $admin),
            'loanSummary' => $isEquipmentLoan ? EquipmentLoanService::extractLoanSummary($ticket) : null,
            'loanAttachments' => $isEquipmentLoan
                ? EquipmentLoanService::extractLoanAttachments($ticket)
                : ['serah' => [], 'pengajuan_kembali' => [], 'kembali' => []],
        ]);
    }

    public function pollLogs(Ticket $ticket): JsonResponse
    {
        $admin = auth('admin')->user();
        $canManage = $admin ? $ticket->isAccessibleBy($admin) : false;

        if (! $admin || (! $canManage && ! $admin->hasRole(['Admin', 'admin']))) {
            abort(403, 'Kamu tidak berhak mengakses tiket ini.');
        }

        return response()->json(TicketChatBridgeService::buildTicketPollPayload($ticket, $canManage));
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

        return DocumentContentService::streamInlineOrDownload(
            (string) $attachment->file_path,
            (string) ($attachment->original_name ?: basename((string) $attachment->file_path)),
            $attachment->mime_type,
        );
    }

    public function previewAttachment(TicketAttachment $attachment): JsonResponse
    {
        $admin = auth('admin')->user();
        $ticket = $attachment->ticket;
        if (! $ticket || (! $admin->hasRole(['Admin', 'admin']) && ! $ticket->isAccessibleBy($admin))) {
            abort(403, 'Kamu tidak berhak mengakses lampiran ini.');
        }

        return DocumentContentService::previewSpreadsheetResponse(
            (string) $attachment->file_path,
            (string) ($attachment->original_name ?: basename((string) $attachment->file_path)),
            $attachment->mime_type,
        );
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

    public function loanRecipientOptions(Request $request): JsonResponse
    {
        $admin = auth('admin')->user();
        $ticketId = $request->filled('ticket_id') ? (int) $request->query('ticket_id') : null;
        $search = $request->query('search');

        return response()->json([
            'data' => EquipmentLoanService::searchRecipientOptions(
                $admin,
                is_string($search) ? $search : null,
                $ticketId
            ),
        ]);
    }

    public function serahkanAlat(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! EquipmentLoanService::canManageLoanTicket($ticket, $admin)) {
            abort(403, 'Kamu tidak berhak mengelola serah-terima alat pada tiket ini.');
        }

        $validated = $request->validate([
            'penerima_alat_user_id' => 'nullable|integer|exists:users,id',
            'kondisi_serah' => 'nullable|string|in:baik,rusak,tidak_lengkap',
            'catatan_serah' => 'nullable|string|max:1000',
            'lampiran' => 'nullable|array|max:3',
            'lampiran.*' => 'file|max:3072|extensions:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,txt',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'file|max:3072|extensions:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,txt',
        ]);

        $files = $request->file('lampiran');
        if (empty($files)) {
            $files = $request->file('attachments', []);
        }
        $files = is_array($files) ? $files : ($files ? [$files] : []);

        try {
            EquipmentLoanService::markAsHandedOver(
                $ticket,
                $admin,
                isset($validated['penerima_alat_user_id']) ? (int) $validated['penerima_alat_user_id'] : null,
                $validated['kondisi_serah'] ?? 'baik',
                $validated['catatan_serah'] ?? null,
                $files
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Alat berhasil ditandai sudah diserahkan.');
    }

    public function laporKembaliAlat(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! $ticket->isSubmittedBy($admin) || EquipmentLoanService::canManageLoanTicket($ticket, $admin)) {
            abort(403, 'Kamu tidak memiliki hak akses untuk melaporkan pengembalian alat pada tiket ini.');
        }

        $validated = $request->validate([
            'catatan_pengajuan_kembali' => 'nullable|string|max:1000',
            'lampiran' => 'nullable|array|max:3',
            'lampiran.*' => 'file|max:3072|extensions:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,txt',
        ]);

        $files = $request->file('lampiran', []);
        $files = is_array($files) ? $files : ($files ? [$files] : []);

        try {
            EquipmentLoanService::reportReturnByRequester(
                $ticket,
                $admin,
                $validated['catatan_pengajuan_kembali'] ?? null,
                $files
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Laporan pengembalian alat berhasil dikirim. Menunggu konfirmasi operator.');
    }

    public function kembalikanAlat(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (! EquipmentLoanService::canManageLoanTicket($ticket, $admin)) {
            abort(403, 'Kamu tidak berhak mengelola pengembalian alat pada tiket ini.');
        }

        $validated = $request->validate([
            'kondisi_kembali' => 'nullable|string|in:baik,rusak,tidak_lengkap',
            'catatan_kembali' => 'nullable|string|max:1000',
            'lampiran' => 'nullable|array|max:3',
            'lampiran.*' => 'file|max:3072|extensions:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,txt',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'file|max:3072|extensions:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv|mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,csv,txt',
        ]);

        $files = $request->file('lampiran');
        if (empty($files)) {
            $files = $request->file('attachments', []);
        }
        $files = is_array($files) ? $files : ($files ? [$files] : []);

        try {
            EquipmentLoanService::markAsReturned(
                $ticket,
                $admin,
                $validated['kondisi_kembali'] ?? 'baik',
                $validated['catatan_kembali'] ?? null,
                $files
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

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
            'general_attachments.*' => 'file|max:3072|extensions:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,csv|mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx,csv,txt',
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

    public function updateReply(Request $request, Ticket $ticket, TicketLog $log, TicketChatBridgeService $bridge)
    {
        if ((int) $log->ticket_id !== (int) $ticket->id) {
            abort(404);
        }

        $admin = auth('admin')->user();
        if (! $ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengedit komentar pada tiket ini.');
        }

        if (strtolower((string) $log->aksi) !== 'balasan') {
            $msg = 'Log sistem tidak dapat diedit.';

            return $request->expectsJson()
                ? response()->json(['message' => $msg], 422)
                : redirect()->back()->with('error', $msg);
        }

        if ($log->admin_id === null || (int) $log->admin_id !== (int) $admin->id) {
            abort(403, 'Kamu hanya dapat mengedit komentarmu sendiri.');
        }

        if (TicketChatBridgeService::isTicketClosed($ticket)) {
            $msg = 'Tiket sudah ditutup, komentar tidak dapat diedit.';

            return $request->expectsJson()
                ? response()->json(['message' => $msg], 422)
                : redirect()->back()->with('error', $msg);
        }

        $validator = Validator::make($request->all(), [
            'catatan' => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return $request->expectsJson()
                ? response()->json([
                    'message' => $validator->errors()->first('catatan'),
                    'errors' => $validator->errors()->toArray(),
                ], 422)
                : redirect()->back()->withErrors($validator);
        }

        $validated = $validator->validated();

        if (trim($validated['catatan']) === '') {
            return $request->expectsJson()
                ? response()->json(['message' => 'Komentar tidak boleh kosong.', 'errors' => ['catatan' => ['Komentar tidak boleh kosong.']]], 422)
                : redirect()->back()->withErrors(['catatan' => 'Komentar tidak boleh kosong.']);
        }

        try {
            $updated = $bridge->editTicketLog($ticket, $log, $validated['catatan']);
        } catch (\InvalidArgumentException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : redirect()->back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Komentar berhasil diperbarui.',
                'log' => $updated,
            ]);
        }

        return redirect()->back()->with('success', 'Komentar berhasil diperbarui.');
    }
}
