<?php

namespace App\Services\Ai;

use App\Models\Admin;
use App\Models\Csat;
use App\Models\RoomVehicleBooking;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for per-admin data visibility across HaloAPU AI tools and summaries.
 */
class AiAccessScope
{
    public function isSuperAdmin(Admin $admin): bool
    {
        return $admin->hasRole(['superadmin', 'Super Admin', 'super_admin', 'Superadmin'])
            || str_contains(strtolower($admin->username ?? ''), 'superadmin');
    }

    /**
     * Allowed unit IDs for this admin, or null when unrestricted (Superadmin).
     *
     * @return array<int, int>|null
     */
    public function allowedUnitIds(Admin $admin): ?array
    {
        if ($this->isSuperAdmin($admin)) {
            return null;
        }

        $unitIds = $admin->units()->pluck('units.id')->all();
        $subUnitUnitIds = $admin->subUnits()->pluck('sub_units.unit_id')->all();

        $merged = array_values(array_unique(array_map(
            static fn ($id) => (int) $id,
            array_filter(array_merge($unitIds, $subUnitUnitIds), static fn ($id) => $id !== null && (int) $id > 0)
        )));

        sort($merged, SORT_NUMERIC);

        return $merged;
    }

    public function tickets(Admin $admin): Builder
    {
        return Ticket::query()->accessibleBy($admin);
    }

    public function slaTrackings(Admin $admin): Builder
    {
        return TicketSlaTracking::query()->whereHas('ticket', fn (Builder $q) => $q->accessibleBy($admin));
    }

    public function csats(Admin $admin): Builder
    {
        return Csat::query()->whereHas('ticket', fn (Builder $q) => $q->accessibleBy($admin));
    }

    public function bookings(Admin $admin): Builder
    {
        return RoomVehicleBooking::query()->whereHas('ticket', fn (Builder $q) => $q->accessibleBy($admin));
    }

    /**
     * Validate a requested unit filter against the admin's allowed unit scope.
     *
     * @return array{unit_id: ?int, denied: bool, note: ?string}
     */
    public function filterUnitId(Admin $admin, ?int $requestedUnitId): array
    {
        if ($requestedUnitId === null || $requestedUnitId <= 0) {
            return ['unit_id' => null, 'denied' => false, 'note' => null];
        }

        if ($this->isSuperAdmin($admin)) {
            return ['unit_id' => $requestedUnitId, 'denied' => false, 'note' => null];
        }

        $allowed = $this->allowedUnitIds($admin) ?? [];
        if (in_array($requestedUnitId, $allowed, true)) {
            return ['unit_id' => $requestedUnitId, 'denied' => false, 'note' => null];
        }

        return [
            'unit_id' => null,
            'denied' => true,
            'note' => 'Unit layanan yang diminta berada di luar cakupan akses unit kamu. Data hanya menampilkan tiket dalam cakupan aksesmu.',
        ];
    }

    /**
     * Apply a verified unit filter to a ticket query (matching either direct unit_id or sub_unit.unit_id).
     */
    public function applyUnitFilterToTickets(Builder $ticketQuery, ?int $unitId): Builder
    {
        if ($unitId === null || $unitId <= 0) {
            return $ticketQuery;
        }

        return $ticketQuery->where(function (Builder $q) use ($unitId): void {
            $q->where('tickets.unit_id', $unitId)
                ->orWhereHas('subUnit', fn (Builder $sq) => $sq->where('sub_units.unit_id', $unitId));
        });
    }

    /**
     * Describe the admin's visibility scope for UI badges, system prompts, and PDF headers.
     *
     * @return array{level: string, label: string, unit_names: array<int, string>, can_view_team_operators: bool}
     */
    public function describeScope(Admin $admin): array
    {
        $isSuperAdmin = $this->isSuperAdmin($admin);
        $canViewTeam = $isSuperAdmin || $admin->isCoordinator();

        if ($isSuperAdmin) {
            return [
                'level' => 'global',
                'label' => 'Semua Unit (Global)',
                'unit_names' => [],
                'can_view_team_operators' => true,
            ];
        }

        $allowedIds = $this->allowedUnitIds($admin) ?? [];
        if (! empty($allowedIds)) {
            $unitNames = Unit::whereIn('id', $allowedIds)
                ->orderBy('nama_unit')
                ->pluck('nama_unit')
                ->values()
                ->all();

            $label = ! empty($unitNames)
                ? 'Unit: ' . implode(', ', $unitNames)
                : 'Unit Terbatas';

            return [
                'level' => 'unit',
                'label' => $label,
                'unit_names' => $unitNames,
                'can_view_team_operators' => $canViewTeam,
            ];
        }

        return [
            'level' => 'personal',
            'label' => 'Tiket Pribadi & Ditugaskan',
            'unit_names' => [],
            'can_view_team_operators' => $canViewTeam,
        ];
    }

    public function scopeSignature(Admin $admin): string
    {
        if ($this->isSuperAdmin($admin)) {
            return 'global';
        }

        $allowedIds = $this->allowedUnitIds($admin) ?? [];
        if (! empty($allowedIds)) {
            return 'units:' . implode(',', $allowedIds);
        }

        return 'admin:' . $admin->id;
    }
}
