<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Ticket extends Model
{
    public const WORKLOAD_FINISHED_STATUSES = ['solve', 'selesai'];

    public $incrementing = false;
    protected $keyType = 'integer';

    /**
     * Scope filter laporan tiket — dipakai bersama oleh LaporanTiketController@index dan export.
     */
    public function scopeLaporanFilter($query, array $filters): void
    {
        $year     = $filters['year'] ?? null;
        $month    = $filters['month'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo   = $filters['date_to'] ?? null;
        $unitId   = $filters['unit_id'] ?? null;
        $subUnitId = $filters['sub_unit_id'] ?? null;
        $status   = $filters['status'] ?? null;
        $divisiId = $filters['divisi_id'] ?? null;
        $search   = $filters['search'] ?? null;

        if ($dateFrom || $dateTo) {
            if ($dateFrom) $query->where('tickets.created_at', '>=', $dateFrom . ' 00:00:00');
            if ($dateTo)   $query->where('tickets.created_at', '<=', $dateTo . ' 23:59:59');
        } else {
            if ($year)  $query->whereYear('tickets.created_at', $year);
            if ($month) $query->whereMonth('tickets.created_at', $month);
        }

        if ($unitId) {
            $query->where('tickets.unit_id', $unitId);
        }
        if ($subUnitId) {
            $query->where('tickets.sub_unit_id', $subUnitId);
        }
        if ($status) {
            if (is_array($status)) {
                $query->whereIn('tickets.status', $status);
            } elseif ($status === 'solve') {
                $query->whereIn(DB::raw('LOWER(tickets.status)'), self::WORKLOAD_FINISHED_STATUSES);
            } else {
                $query->where('tickets.status', $status);
            }
        }
        if ($divisiId) {
            $query->where('tickets.divisi_id', $divisiId);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('tickets.id', 'like', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                  ->orWhereHas('createdByAdmin', fn ($a) => $a->where('username', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                  ->orWhereHas('subUnit', fn ($s) => $s->where('nama_layanan', 'like', "%{$search}%"));
            });
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                do {
                    $id = random_int(100000000, 999999999);
                } while (self::where($model->getKeyName(), $id)->exists());
                $model->{$model->getKeyName()} = $id;
            }
        });

    }
    protected $fillable = [
        'user_id', 'created_by_admin_id', 'divisi_id', 'org_unit_id', 'jabatan_id',
        'unit_id', 'sub_unit_id', 'form_data', 'status', 'priority', 'assigned_admin_id',
        'assignment_queued_at', 'assignment_resume_status',
        'work_hours_resume_status', 'work_hours_sla_paused_at',
        'source',
        'revision_count', 'waiting_approval_at', 'is_result_accepted',
        'diserahkan_at', 'diserahkan_oleh_admin_id', 'penerima_alat_user_id', 'penerima_alat', 'kondisi_serah', 'catatan_serah',
        'dikembalikan_at', 'diterima_kembali_oleh_admin_id', 'kondisi_kembali', 'catatan_kembali'
    ];

    public function getFormattedIdAttribute()
    {
        $id = (string) $this->id;
        if (strlen($id) === 9) {
            return substr($id, 0, 3) . '-' . substr($id, 3, 3) . '-' . substr($id, 6, 3);
        }
        return $id;
    }

    public function getJudulAttribute()
    {
        if (!empty($this->form_data) && is_array($this->form_data)) {
            foreach ($this->form_data as $key => $value) {
                if (is_string($value) && !empty($value) && strlen($value) < 100) {
                    return $value;
                }
            }
        }
        return $this->subUnit ? $this->subUnit->nama_layanan : 'Tiket #' . $this->formatted_id;
    }

    /**
     * Chat room label: the service the requester picked, never the ticket id.
     */
    public function chatLabel(): string
    {
        $label = trim((string) ($this->subUnit?->nama_layanan ?? ''));

        return $label !== '' ? $label : 'Diskusi Tiket';
    }

    protected $casts = [
        'form_data' => 'array',
        'user_id' => 'integer',
        'assigned_admin_id' => 'integer',
        'divisi_id' => 'integer',
        'org_unit_id' => 'integer',
        'jabatan_id' => 'integer',
        'unit_id' => 'integer',
        'sub_unit_id' => 'integer',
        'is_result_accepted' => 'boolean',
        'diserahkan_at' => 'datetime',
        'diserahkan_oleh_admin_id' => 'integer',
        'penerima_alat_user_id' => 'integer',
        'dikembalikan_at' => 'datetime',
        'diterima_kembali_oleh_admin_id' => 'integer',
        'assignment_queued_at' => 'datetime',
        'work_hours_sla_paused_at' => 'datetime',
    ];

    protected $hidden = [
        'work_hours_sla_paused_at',
    ];

    /** Tiket masih standing dan belum pernah dijeda jam kerja. */
    public function isOpenForCancellation(): bool
    {
        return $this->status === 'open'
            || ($this->status === 'pending' && (
                $this->work_hours_resume_status === 'open'
                || ($this->assignment_queued_at === null && $this->assignment_resume_status === 'open')
            ));
    }

    /**
     * Cek apakah tiket dapat dibatalkan (creator) atau ditolak/dibatalkan (superadmin) via portal Admin.
     */
    public function canBeCancelledByAdmin(Admin $admin, ?Carbon $at = null, ?bool $isCreatorOverride = null): bool
    {
        $at ??= Carbon::now();
        $isCreator = $isCreatorOverride ?? $this->isSubmittedBy($admin);
        $isSuperAdmin = $admin->hasRole(['superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
            || str_contains(strtolower($admin->username ?? ''), 'superadmin');

        if (! $isCreator && ! $isSuperAdmin) {
            return false;
        }

        if (in_array(strtolower((string) $this->status), ['selesai', 'solve', 'dibatalkan', 'reject'], true)) {
            return false;
        }

        if ($this->booking?->tanggal_selesai && $at->gte($this->booking->tanggal_selesai)) {
            return false;
        }

        if ($isCreator) {
            return true;
        }

        return $this->isOpenForCancellation();
    }

    public function createdByAdmin()
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function subUnit()
    {
        return $this->belongsTo(SubUnit::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function logs()
    {
        return $this->hasMany(TicketLog::class);
    }

    public function editHistories()
    {
        return $this->hasMany(TicketEditHistory::class)->latest('created_at');
    }

    public function orgDivisi()
    {
        return $this->belongsTo(OrgDivisi::class, 'divisi_id');
    }

    public function orgUnit()
    {
        return $this->belongsTo(OrgUnit::class, 'org_unit_id');
    }

    public function jabatan()
    {
        return $this->belongsTo(OrgJabatan::class, 'jabatan_id');
    }

    public function slaTracking()
    {
        return $this->hasOne(TicketSlaTracking::class);
    }

    public function csat()
    {
        return $this->hasOne(Csat::class);
    }

    public function workloadAssessment()
    {
        return $this->hasOne(TicketWorkloadAssessment::class);
    }

    public function scopeForCoordinatedServices(Builder $query, Admin $admin): void
    {
        $units = $admin->units()->select('units.id')->getQuery();
        $services = $admin->subUnits()->select('sub_units.id')->getQuery();

        $query->where(fn ($tickets) => $tickets
            ->whereIn('tickets.unit_id', $units)
            ->orWhereHas('subUnit', fn ($subUnit) => $subUnit->whereIn('sub_units.unit_id', $units))
            ->orWhereIn('tickets.sub_unit_id', $services)
        );
    }

    public function scopeAwaitingWorkloadAssessment(Builder $query): void
    {
        $query->whereIn(DB::raw('LOWER(tickets.status)'), self::WORKLOAD_FINISHED_STATUSES)
            ->whereDoesntHave('workloadAssessment');
    }

    public function booking()
    {
        return $this->hasOne(RoomVehicleBooking::class);
    }

    public function assignedAdmin()
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    public function diserahkanOlehAdmin()
    {
        return $this->belongsTo(Admin::class, 'diserahkan_oleh_admin_id');
    }

    public function penerimaAlatUser()
    {
        return $this->belongsTo(User::class, 'penerima_alat_user_id');
    }

    public function diterimaKembaliOlehAdmin()
    {
        return $this->belongsTo(Admin::class, 'diterima_kembali_oleh_admin_id');
    }

    /**
     * ID User yang berkorespondensi dengan akun Admin (berdasarkan username/email non-kosong).
     *
     * @return array<int, int>
     */
    public static function matchedUserIdsForAdmin(Admin $admin): array
    {
        if (empty($admin->username) && empty($admin->email)) {
            return [];
        }

        return \App\Models\User::where(function ($q) use ($admin) {
            $hasCondition = false;
            if (! empty($admin->username)) {
                $q->where('username', $admin->username);
                $hasCondition = true;
            }
            if (! empty($admin->email)) {
                $hasCondition
                    ? $q->orWhere('email', $admin->email)
                    : $q->where('email', $admin->email);
            }
        })
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * ID Admin yang berkorespondensi dengan akun User (berdasarkan username/email non-kosong).
     *
     * @return array<int, int>
     */
    public static function matchedAdminIdsForUser(User $user): array
    {
        if (empty($user->username) && empty($user->email)) {
            return [];
        }

        return \App\Models\Admin::where(function ($q) use ($user) {
            $hasCondition = false;
            if (! empty($user->username)) {
                $q->where('username', $user->username);
                $hasCondition = true;
            }
            if (! empty($user->email)) {
                $hasCondition
                    ? $q->orWhere('email', $user->email)
                    : $q->where('email', $user->email);
            }
        })
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Tiket yang diajukan oleh user ini.
     */
    public function scopeOwnedByUser($query, ?User $user = null): void
    {
        $user ??= auth('web')->user();

        if (! $user) {
            $query->whereRaw('1 = 0');
            return;
        }

        $matchedAdminIds = self::matchedAdminIdsForUser($user);

        $query->where(function ($q) use ($user, $matchedAdminIds) {
            $q->where('tickets.user_id', $user->id);
            if (! empty($matchedAdminIds)) {
                $q->orWhereIn('tickets.created_by_admin_id', $matchedAdminIds);
            }
        });
    }

    public function isOwnedByUser(User $user): bool
    {
        if ((int) $this->user_id === (int) $user->id) {
            return true;
        }

        if (! $this->created_by_admin_id) {
            return false;
        }

        return in_array((int) $this->created_by_admin_id, self::matchedAdminIdsForUser($user), true);
    }

    /**
     * Tiket masuk: ditugaskan ke admin, atau masuk ke unit/sub unit admin. Tidak termasuk tiket yang diajukan sendiri.
     */
    public function scopeIncomingFor($query, ?Admin $admin = null): void
    {
        $admin ??= auth('admin')->user();

        if (!$admin) {
            $query->whereRaw('1 = 0');
            return;
        }

        $matchedUserIds = self::matchedUserIdsForAdmin($admin);

        // Exclude tiket yang diajukan sendiri
        $query->where(function ($notOwn) use ($admin) {
            $notOwn->whereNull('tickets.created_by_admin_id')
                   ->orWhere('tickets.created_by_admin_id', '!=', $admin->id);
        });

        if (!empty($matchedUserIds)) {
            $query->where(function ($notUser) use ($matchedUserIds) {
                $notUser->whereNull('tickets.user_id')
                        ->orWhereNotIn('tickets.user_id', $matchedUserIds);
            });
        }

        $isSuperAdmin = $admin->hasRole(['superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
            || str_contains(strtolower($admin->username ?? ''), 'superadmin');

        if ($isSuperAdmin) {
            return;
        }

        $query->where(function ($q) use ($admin) {
            $q->where('tickets.assigned_admin_id', $admin->id);

            $unitIds = $admin->units()->pluck('units.id')->filter()->all();
            if (!empty($unitIds)) {
                $q->orWhereIn('tickets.unit_id', $unitIds)
                  ->orWhereHas('subUnit', fn ($sq) => $sq->whereIn('unit_id', $unitIds));
            }

            $subUnitIds = $admin->subUnits()->pluck('sub_units.id')->filter()->all();
            if (!empty($subUnitIds)) {
                $q->orWhereIn('tickets.sub_unit_id', $subUnitIds);
            }
        });
    }

    /**
     * Tiket yang diajukan oleh admin ini (baik sebagai Admin maupun User terkait).
     */
    public function scopeSubmittedBy($query, ?Admin $admin = null): void
    {
        $admin ??= auth('admin')->user();

        if (!$admin) {
            $query->whereRaw('1 = 0');
            return;
        }

        $matchedUserIds = self::matchedUserIdsForAdmin($admin);

        $query->where(function ($q) use ($admin, $matchedUserIds) {
            $q->where('tickets.created_by_admin_id', $admin->id);

            if (!empty($matchedUserIds)) {
                $q->orWhereIn('tickets.user_id', $matchedUserIds);
            }
        });
    }

    /**
     * Cek apakah tiket ini diajukan oleh admin terkait.
     */
    public function isSubmittedBy(Admin $admin): bool
    {
        if ((int) $this->created_by_admin_id === (int) $admin->id) {
            return true;
        }

        if (! $this->user_id) {
            return false;
        }

        return in_array((int) $this->user_id, self::matchedUserIdsForAdmin($admin), true);
    }

    /**
     * Tiket yang boleh dilihat: masuk tim ATAU diajukan oleh admin ini.
     */
    public function scopeAccessibleBy($query, ?Admin $admin = null): void
    {
        $admin ??= auth('admin')->user();

        if (!$admin) {
            $query->whereRaw('1 = 0');
            return;
        }

        $isSuperAdmin = $admin->hasRole(['superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
            || str_contains(strtolower($admin->username ?? ''), 'superadmin');

        if ($isSuperAdmin) {
            return;
        }

        $matchedUserIds = self::matchedUserIdsForAdmin($admin);

        $query->where(function ($q) use ($admin, $matchedUserIds) {
            $q->where('tickets.created_by_admin_id', $admin->id);
            $q->orWhere('tickets.assigned_admin_id', $admin->id);

            if (!empty($matchedUserIds)) {
                $q->orWhereIn('tickets.user_id', $matchedUserIds);
            }

            $unitIds = $admin->units()->pluck('units.id')->filter()->all();
            if (!empty($unitIds)) {
                $q->orWhereIn('tickets.unit_id', $unitIds)
                  ->orWhereHas('subUnit', fn ($sq) => $sq->whereIn('unit_id', $unitIds));
            }

            $subUnitIds = $admin->subUnits()->pluck('sub_units.id')->filter()->all();
            if (!empty($subUnitIds)) {
                $q->orWhereIn('tickets.sub_unit_id', $subUnitIds);
            }
        });
    }

    public function isAccessibleBy(Admin $admin): bool
    {
        $isSuperAdmin = $admin->hasRole(['superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
            || str_contains(strtolower($admin->username ?? ''), 'superadmin');

        if ($isSuperAdmin) {
            return true;
        }

        if ((int) $this->assigned_admin_id === (int) $admin->id
            || $this->isSubmittedBy($admin)) {
            return true;
        }

        $unitIds = $admin->units()->pluck('units.id')->filter()->all();
        if (!empty($unitIds)) {
            if (in_array((int) $this->unit_id, $unitIds)) {
                return true;
            }
            if ($this->subUnit && in_array((int) $this->subUnit->unit_id, $unitIds)) {
                return true;
            }
        }

        $subUnitIds = $admin->subUnits()->pluck('sub_units.id')->filter()->all();
        if (!empty($subUnitIds) && in_array((int) $this->sub_unit_id, $subUnitIds)) {
            return true;
        }

        return false;
    }
}

