<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\SystemConfig;
use App\Models\Ticket;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SparcIntegrationService
{
    /**
     * Determine whether a ticket matches SPARC Humas criteria (Reels or Feed/Meme).
     * Returns 'video_reels' | 'desain_feed' | null.
     */
    public function matchesSparcFilter(Ticket $ticket): ?string
    {
        $ticket->loadMissing(['subUnit.unit', 'subUnit.formFields']);

        $unitName = $ticket->subUnit?->unit?->nama_unit ?? $ticket->unit?->nama_unit;
        if (trim((string) $unitName) !== 'Humas') {
            return null;
        }

        $subUnitName = (string) ($ticket->subUnit?->nama_layanan ?? '');
        $formData = (array) ($ticket->form_data ?? []);
        $formFields = $ticket->subUnit?->formFields ?? collect();

        // 1. Check custom overrides from SystemConfig if configured
        /** @var array<int, array<string, mixed>> $customRules */
        $customRules = SystemConfig::getValue('sparc_filter_rules', []);
        if (is_array($customRules) && ! empty($customRules)) {
            foreach ($customRules as $rule) {
                if (($rule['subUnitName'] ?? '') === $subUnitName) {
                    $targetLabel = (string) ($rule['fieldLabel'] ?? '');
                    $field = $formFields->first(fn ($f) => strcasecmp(trim((string) $f->label), trim($targetLabel)) === 0);
                    if ($field) {
                        $val = $formData[(string) $field->id] ?? null;
                        $allowedValues = (array) ($rule['values'] ?? []);
                        if (is_array($val)) {
                            if (count(array_intersect($val, $allowedValues)) > 0) {
                                return (string) ($rule['sourceType'] ?? 'video_reels');
                            }
                        } elseif ($val !== null && in_array((string) $val, $allowedValues, true)) {
                            return (string) ($rule['sourceType'] ?? 'video_reels');
                        }
                    }
                }
            }
        }

        // 2. Default hardcoded conventions
        if ($subUnitName === 'Editing & Produksi Video') {
            $field = $formFields->first(fn ($f) => strcasecmp(trim((string) $f->label), 'Jenis Video') === 0);
            if ($field) {
                $val = $formData[(string) $field->id] ?? null;
                $isReels = is_array($val) ? in_array('Reels', $val, true) : (string) $val === 'Reels';
                if ($isReels) {
                    return 'video_reels';
                }
            }
        }

        if ($subUnitName === 'Pengajuan Desain Grafis') {
            $field = $formFields->first(fn ($f) => strcasecmp(trim((string) $f->label), 'Jenis Produksi Desain') === 0);
            if ($field) {
                $val = $formData[(string) $field->id] ?? null;
                $allowed = ['Feed Instagram / Meme', 'Meme Al-Kisah'];
                $isMeme = is_array($val)
                    ? count(array_intersect($val, $allowed)) > 0
                    : in_array((string) $val, $allowed, true);
                if ($isMeme) {
                    return 'desain_feed';
                }
            }
        }

        return null;
    }

    /**
     * Serialize a ticket into canonical SPARC wire format.
     *
     * @return array<string, mixed>
     */
    public function serializeTicketForSparc(Ticket $ticket): array
    {
        $ticket->loadMissing([
            'subUnit.unit',
            'subUnit.formFields',
            'user.divisi',
            'user.orgUnit',
            'createdByAdmin',
            'assignedAdmin',
            'attachments',
            'logs.admin',
            'slaTracking',
        ]);

        $sourceType = $this->matchesSparcFilter($ticket) ?? 'video_reels';
        $formData = (array) ($ticket->form_data ?? []);
        $formFields = $ticket->subUnit?->formFields ?? collect();

        // Build snapshot of labeled fields
        $snapshot = [];
        foreach ($formFields as $field) {
            $val = $formData[(string) $field->id] ?? null;
            if ($val !== null && $val !== '') {
                $snapshot[$field->label] = is_array($val) ? implode(', ', $val) : (string) $val;
            }
        }

        // Title resolution
        $title = $snapshot['Judul/Tema Video']
            ?? $snapshot['Jenis Desain']
            ?? $snapshot['Brief Video']
            ?? "Tiket #{$ticket->formatted_id}";

        // Brief resolution
        $brief = $snapshot['Brief Video']
            ?? $snapshot['Brief Materi']
            ?? $snapshot['Keterangan Tambahan']
            ?? (count($snapshot) > 0 ? implode("\n", array_slice($snapshot, 0, 3)) : '');

        // Deadline target date
        $targetDate = $snapshot['Deadline Tayang']
            ?? $snapshot['Deadline']
            ?? null;
        if ($targetDate) {
            try {
                $targetDate = date('Y-m-d', strtotime($targetDate));
            } catch (\Throwable) {
                $targetDate = null;
            }
        }

        // Requester info
        $requesterName = $ticket->user?->name
            ?? $ticket->user?->username
            ?? $ticket->createdByAdmin?->name
            ?? 'Pemohon';

        $requesterUnit = $ticket->user?->orgUnit?->nama_unit_organisasi
            ?? $ticket->user?->divisi?->nama_divisi
            ?? $ticket->unit?->nama_unit
            ?? '';

        $avatarPath = $ticket->user?->avatar_path
            ?? $ticket->createdByAdmin?->avatar_path
            ?? $ticket->user?->avatar
            ?? $ticket->createdByAdmin?->avatar;

        $requesterAvatar = null;
        if ($avatarPath) {
            $requesterAvatar = str_starts_with($avatarPath, 'http')
                ? $avatarPath
                : Storage::disk('public')->url($avatarPath);
        }
        // Attachments
        $attachments = $ticket->attachments->map(fn ($att): array => [
            'id' => (string) $att->id,
            'fileName' => (string) $att->original_name,
            'url' => Storage::disk('public')->url($att->file_path),
            'fileSize' => $this->formatFileSize((int) $att->file_size),
        ])->values()->all();

        // Logs
        $logs = $ticket->logs->map(fn ($l): array => [
            'id' => (string) $l->id,
            'aksi' => (string) $l->aksi,
            'catatan' => (string) ($l->catatan ?? ''),
            'actor' => $l->admin?->name ?? ($l->admin?->username ?? 'Pemohon'),
            'timestamp' => $l->timestamp ? date('d M Y, H:i', strtotime($l->timestamp)) : '',
        ])->values()->all();

        return [
            'haloapuTicketId' => $ticket->formatted_id,
            'rawTicketId' => (string) $ticket->id,
            'sourceType' => $sourceType,
            'subUnitName' => (string) ($ticket->subUnit?->nama_layanan ?? ''),
            'title' => $title,
            'brief' => $brief,
            'requesterName' => $requesterName,
            'requesterUnit' => $requesterUnit,
            'requesterAvatar' => $requesterAvatar,
            'requesterWhatsapp' => (string) ($ticket->user?->no_wa ?? ''),
            'assignedOperatorName' => $ticket->assignedAdmin?->name ?? null,
            'priority' => (string) ($ticket->priority ?? 'Sedang'),
            'targetDate' => $targetDate,
            'haloapuStatus' => (string) $ticket->status,
            'revisionCount' => (int) $ticket->revision_count,
            'isResultAccepted' => (bool) $ticket->is_result_accepted,
            'slaResponseDeadline' => $ticket->slaTracking?->sla_response_deadline?->toIso8601String(),
            'slaResolutionDeadline' => $ticket->slaTracking?->sla_resolution_deadline?->toIso8601String(),
            'isSlaBreached' => (bool) ($ticket->slaTracking?->isBreached() ?? false),
            'formSnapshot' => $snapshot,
            'attachments' => $attachments,
            'logs' => $logs,
            'createdAt' => $ticket->created_at?->format('d M Y, H:i').' WIB' ?? '',
        ];
    }

    /**
     * Dispatch an outbound webhook to SPARC if integration is enabled and ticket qualifies.
     *
     * @param  array<string, mixed>  $extra
     */
    public function notifyIfEligible(Ticket $ticket, string $event, array $extra = []): void
    {
        $enabled = (bool) SystemConfig::getValue('sparc_integration_enabled', false);
        if (! $enabled) {
            return;
        }

        if (! $this->matchesSparcFilter($ticket)) {
            return;
        }

        if ($event === 'ticket.status_updated' && ($extra['skip_sparc_loop'] ?? false)) {
            return;
        }

        // Dispatch afterResponse to ensure caller HTTP request is never blocked
        dispatch(function () use ($ticket, $event, $extra): void {
            $this->dispatchWebhook($event, $ticket->fresh(), $extra);
        })->afterResponse();
    }

    /**
     * Perform the actual HTTP POST to SPARC's webhook endpoint with HMAC signature.
     *
     * @param  array<string, mixed>  $extra
     */
    public function dispatchWebhook(string $event, ?Ticket $ticket, array $extra = []): void
    {
        if (! $ticket) {
            return;
        }

        $baseUrl = rtrim((string) SystemConfig::getValue('sparc_base_url', ''), '/');
        $secret = (string) SystemConfig::getValue('sparc_webhook_secret', '');

        if (empty($baseUrl) || empty($secret)) {
            return;
        }

        $serialized = $this->serializeTicketForSparc($ticket);
        $payload = [
            'event' => $event,
            'ticket' => $serialized,
            'extra' => $extra,
            'sent_at' => now()->toIso8601String(),
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $signature = 'sha256='.hash_hmac('sha256', $jsonPayload, $secret);

        try {
            Http::withHeaders([
                'X-HaloAPU-Event' => $event,
                'X-HaloAPU-Timestamp' => (string) time(),
                'X-HaloAPU-Signature' => $signature,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->timeout(10)
                ->withBody($jsonPayload, 'application/json')
                ->post("{$baseUrl}/api/webhooks/haloapu");
        } catch (\Throwable $e) {
            Log::error("Gagal mengirim webhook SPARC untuk tiket #{$ticket->id} ({$event}): ".$e->getMessage());
        }
    }

    /**
     * Resolve an acting Admin model for actions triggered from SPARC.
     */
    public function resolveActingAdmin(?string $actorEmail = null, ?string $actorName = null): ?Admin
    {
        if ($actorEmail) {
            $admin = Admin::where('email', $actorEmail)->first();
            if ($admin) {
                return $admin;
            }
        }

        if ($actorName) {
            $admin = Admin::where(function ($q) use ($actorName) {
                $q->where('name', 'like', "%{$actorName}%")
                    ->orWhere('username', 'like', "%{$actorName}%");
            })->first();
            if ($admin) {
                return $admin;
            }
        }

        $defaultAdminId = SystemConfig::getValue('sparc_default_admin_id');
        if ($defaultAdminId) {
            $admin = Admin::find($defaultAdminId);
            if ($admin) {
                return $admin;
            }
        }

        return Admin::whereHas('units', fn ($q) => $q->where('nama_unit', 'Humas'))->first()
            ?? Admin::first();
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 1).' '.$units[$i];
    }
}
