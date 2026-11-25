<?php

namespace App\Services\Ai;

use App\Models\Admin;
use App\Models\AiBriefing;
use App\Models\ReminderConfig;
use App\Models\Ticket;
use App\Notifications\AiOperationalBriefingNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiBriefingService
{
    private const ACTIVE_STATUSES = ['open', 'on_proses', 'on_progress', 'pending', 'need_revision'];

    public function __construct(
        private readonly AiAccessScope $scope,
        private readonly GeminiClient $gemini,
    ) {}

    /**
     * Resolve scheduled briefing recipients (Superadmin, Koordinator, or Admin).
     *
     * @return Collection<int, Admin>
     */
    public function resolveRecipients(?int $onlyAdminId = null): Collection
    {
        if ($onlyAdminId !== null) {
            $admin = Admin::find($onlyAdminId);

            return $admin ? collect([$admin]) : collect();
        }

        return Admin::with('roles')
            ->orderBy('id')
            ->get()
            ->filter(function (Admin $admin): bool {
                return $this->scope->isSuperAdmin($admin)
                    || $admin->isCoordinator()
                    || $admin->hasRole(['Admin', 'admin']);
            })
            ->values();
    }

    /**
     * Build scoped operational metrics snapshot for an admin.
     *
     * @return array{
     *     period_label: string,
     *     new_tickets: int,
     *     solved_tickets: int,
     *     active_open: int,
     *     active_on_progress: int,
     *     active_pending: int,
     *     stale_pending_gt_2_days: int,
     *     sla_at_risk_count: int,
     *     sla_breached_count: int,
     *     top_at_risk_tickets: array<int, array{ticket_id: int, formatted_id: string, layanan: string, status: string, url: string}>,
     *     csat_avg: float,
     *     csat_count: int,
     *     top_services: array<int, array{nama_layanan: string, count: int}>
     * }
     */
    public function buildSnapshotForAdmin(Admin $admin, string $periodType, Carbon $date): array
    {
        $period = strtolower(trim($periodType)) === 'weekly' ? 'weekly' : 'daily';
        $wibDate = $date->copy()->timezone('Asia/Jakarta');
        $appTz = (string) config('app.timezone', 'Asia/Jakarta');

        if ($period === 'weekly') {
            $windowStart = $wibDate->copy()->subDays(7)->startOfDay();
            $windowEnd = $wibDate->copy()->subDay()->endOfDay();
            $periodLabel = $windowStart->format('d M Y') . ' - ' . $windowEnd->format('d M Y');
        } else {
            $windowStart = $wibDate->copy()->subDay()->startOfDay();
            $windowEnd = $wibDate->copy()->subDay()->endOfDay();
            $periodLabel = $windowStart->copy()->locale('id')->translatedFormat('l, d F Y');
        }

        $queryStart = $windowStart->copy()->timezone($appTz);
        $queryEnd = $windowEnd->copy()->timezone($appTz);

        $periodTicketsQuery = $this->scope->tickets($admin)
            ->whereBetween('tickets.created_at', [$queryStart, $queryEnd]);

        $newTickets = (clone $periodTicketsQuery)->count();

        $solvedTickets = $this->scope->tickets($admin)
            ->whereIn(DB::raw('LOWER(tickets.status)'), ['solve', 'selesai'])
            ->whereBetween('tickets.updated_at', [$queryStart, $queryEnd])
            ->count();

        $activeOpen = $this->scope->tickets($admin)
            ->where(DB::raw('LOWER(tickets.status)'), 'open')
            ->count();

        $activeOnProgress = $this->scope->tickets($admin)
            ->whereIn(DB::raw('LOWER(tickets.status)'), ['on_proses', 'on_progress'])
            ->count();

        $activePending = $this->scope->tickets($admin)
            ->where(DB::raw('LOWER(tickets.status)'), 'pending')
            ->count();

        $staleCutoff = $wibDate->copy()->subDays(2)->timezone($appTz);
        $stalePendingGt2Days = $this->scope->tickets($admin)
            ->where(DB::raw('LOWER(tickets.status)'), 'pending')
            ->where('tickets.updated_at', '<', $staleCutoff)
            ->count();

        $slaBreachedCount = $this->scope->slaTrackings($admin)
            ->whereHas('ticket', fn (Builder $q) => $q->whereIn(DB::raw('LOWER(tickets.status)'), self::ACTIVE_STATUSES))
            ->where(function (Builder $q): void {
                $q->where('is_response_breached', true)
                    ->orWhere('is_resolution_breached', true);
            })
            ->count();

        $appNow = Carbon::now('Asia/Jakarta')->timezone($appTz);
        $riskCutoff = $appNow->copy()->addHours(4);

        $atRiskCandidates = $this->scope->tickets($admin)
            ->whereIn(DB::raw('LOWER(tickets.status)'), self::ACTIVE_STATUSES)
            ->whereHas('slaTracking', function (Builder $slaQuery) use ($riskCutoff): void {
                $slaQuery->where(function (Builder $q) use ($riskCutoff): void {
                    $q->where('is_response_breached', true)
                        ->orWhere('is_resolution_breached', true)
                        ->orWhere('current_tier', '>=', 1)
                        ->orWhere(function (Builder $unpaused) use ($riskCutoff): void {
                            $unpaused->whereNull('paused_at')
                                ->where(function (Builder $deadlines) use ($riskCutoff): void {
                                    $deadlines->where(function (Builder $resp) use ($riskCutoff): void {
                                        $resp->whereNull('responded_at')
                                            ->whereNotNull('sla_response_deadline')
                                            ->where('sla_response_deadline', '<=', $riskCutoff);
                                    })->orWhere(function (Builder $res) use ($riskCutoff): void {
                                        $res->whereNull('resolved_at')
                                            ->whereNotNull('sla_resolution_deadline')
                                            ->where('sla_resolution_deadline', '<=', $riskCutoff);
                                    });
                                });
                        });
                });
            })
            ->with(['slaTracking', 'subUnit:id,nama_layanan'])
            ->get();

        $atRiskMatched = $atRiskCandidates->filter(function (Ticket $ticket) use ($riskCutoff): bool {
            $sla = $ticket->slaTracking;
            if (! $sla) {
                return false;
            }

            if ($sla->is_response_breached || $sla->is_resolution_breached || (int) $sla->current_tier >= 1) {
                return true;
            }

            $isPaused = $sla->paused_at !== null || strtolower((string) $ticket->status) === 'pending';
            if ($isPaused) {
                return false;
            }

            $respDue = $sla->responded_at === null
                && $sla->sla_response_deadline !== null
                && $sla->sla_response_deadline->lte($riskCutoff);
            $resDue = $sla->resolved_at === null
                && $sla->sla_resolution_deadline !== null
                && $sla->sla_resolution_deadline->lte($riskCutoff);

            return $respDue || $resDue;
        })->sortBy(function (Ticket $ticket): array {
            $sla = $ticket->slaTracking;
            $breachedScore = ($sla?->is_resolution_breached || $sla?->is_response_breached) ? 0 : 1;
            $tierScore = -((int) ($sla?->current_tier ?? 0));

            $nextDeadline = null;
            if ($sla?->responded_at === null && $sla?->sla_response_deadline !== null) {
                $nextDeadline = $sla->sla_response_deadline->timestamp;
            }
            if ($sla?->resolved_at === null && $sla?->sla_resolution_deadline !== null) {
                $resTs = $sla->sla_resolution_deadline->timestamp;
                $nextDeadline = $nextDeadline === null ? $resTs : min($nextDeadline, $resTs);
            }

            return [$breachedScore, $tierScore, $nextDeadline ?? PHP_INT_MAX, (int) $ticket->id];
        })->values();

        $slaAtRiskCount = $atRiskMatched->count();
        $topAtRiskTickets = $atRiskMatched->take(5)->map(static fn (Ticket $ticket): array => [
            'ticket_id' => (int) $ticket->id,
            'formatted_id' => (string) $ticket->formatted_id,
            'layanan' => (string) ($ticket->subUnit?->nama_layanan ?? '-'),
            'status' => (string) $ticket->status,
            'url' => '/admin/tiket/' . $ticket->id,
        ])->values()->all();

        $csatStats = $this->scope->csats($admin)
            ->whereBetween('csats.created_at', [$queryStart, $queryEnd])
            ->selectRaw('COUNT(*) as total, COALESCE(AVG(rating), 0) as avg_rating')
            ->first();

        $csatCount = (int) ($csatStats->total ?? 0);
        $csatAvg = $csatCount > 0 ? round((float) $csatStats->avg_rating, 2) : 0.0;

        $topServices = (clone $periodTicketsQuery)
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->select('sub_units.nama_layanan', DB::raw('COUNT(tickets.id) as total'))
            ->groupBy('sub_units.id', 'sub_units.nama_layanan')
            ->orderByDesc('total')
            ->limit(3)
            ->get()
            ->map(static fn ($row): array => [
                'nama_layanan' => (string) $row->nama_layanan,
                'count' => (int) $row->total,
            ])
            ->values()
            ->all();

        return [
            'period_label' => $periodLabel,
            'new_tickets' => $newTickets,
            'solved_tickets' => $solvedTickets,
            'active_open' => $activeOpen,
            'active_on_progress' => $activeOnProgress,
            'active_pending' => $activePending,
            'stale_pending_gt_2_days' => $stalePendingGt2Days,
            'sla_at_risk_count' => $slaAtRiskCount,
            'sla_breached_count' => $slaBreachedCount,
            'top_at_risk_tickets' => $topAtRiskTickets,
            'csat_avg' => $csatAvg,
            'csat_count' => $csatCount,
            'top_services' => $topServices,
        ];
    }

    /**
     * Generate (or skip if already generated today) an AI operational briefing for one admin.
     *
     * @param  array<string, array<string, mixed>>  $narrativeCache
     * @return array{status: 'created'|'skipped', briefing: AiBriefing}
     */
    public function generateForAdmin(
        Admin $admin,
        string $periodType = 'daily',
        bool $force = false,
        ?Carbon $date = null,
        array &$narrativeCache = []
    ): array {
        $period = strtolower(trim($periodType)) === 'weekly' ? 'weekly' : 'daily';
        $targetDate = ($date ?? Carbon::now('Asia/Jakarta'))->copy()->timezone('Asia/Jakarta');
        $dateStr = $targetDate->format('Y-m-d');

        $existing = AiBriefing::where('admin_id', $admin->id)
            ->where('period_type', $period)
            ->whereDate('briefing_date', $dateStr)
            ->first();

        if (! $force && $existing !== null) {
            return [
                'status' => 'skipped',
                'briefing' => $existing,
            ];
        }

        $cacheKey = $this->scope->scopeSignature($admin) . '|' . $period . '|' . $dateStr;

        if (! isset($narrativeCache[$cacheKey])) {
            $scopeInfo = $this->scope->describeScope($admin);
            $scopeLabel = Str::limit((string) $scopeInfo['label'], 160, '');
            $snapshot = $this->buildSnapshotForAdmin($admin, $period, $targetDate);

            $titlePrefix = $period === 'weekly'
                ? 'Briefing Operasional Mingguan'
                : 'Briefing Operasional Harian';
            $title = Str::limit("{$titlePrefix} — {$snapshot['period_label']}", 200, '');

            $narrative = $this->composeNarrative($snapshot, $period, $scopeLabel);

            $narrativeCache[$cacheKey] = [
                'scope_label' => $scopeLabel,
                'title' => $title,
                'snapshot' => $snapshot,
                'summary_markdown' => $narrative['markdown'],
                'is_fallback' => $narrative['is_fallback'],
            ];
        }

        $cached = $narrativeCache[$cacheKey];

        $payload = [
            'scope_label' => $cached['scope_label'],
            'title' => $cached['title'],
            'summary_markdown' => $cached['summary_markdown'],
            'metrics_snapshot' => $cached['snapshot'],
            'is_fallback' => $cached['is_fallback'],
            'read_at' => null,
        ];

        if ($existing !== null) {
            $existing->update($payload);
            $briefing = $existing->fresh();
        } else {
            $briefing = AiBriefing::updateOrCreate(
                [
                    'admin_id' => $admin->id,
                    'period_type' => $period,
                    'briefing_date' => $dateStr,
                ],
                $payload
            );
        }

        return [
            'status' => 'created',
            'briefing' => $briefing,
        ];
    }

    /**
     * Generate and dispatch scheduled briefings to eligible recipients.
     *
     * @return array{sent: int, skipped: int, failed: int, config_active: bool}
     */
    public function dispatchScheduledBriefings(
        string $periodType = 'daily',
        bool $force = false,
        ?int $onlyAdminId = null
    ): array {
        $period = strtolower(trim($periodType)) === 'weekly' ? 'weekly' : 'daily';
        $configKey = $period === 'weekly' ? 'ai_briefing_weekly' : 'ai_briefing_daily';
        $config = ReminderConfig::getConfig($configKey);
        $configActive = $config !== null;

        if (! $configActive && ! $force) {
            return [
                'sent' => 0,
                'skipped' => 0,
                'failed' => 0,
                'config_active' => false,
            ];
        }

        $recipients = $this->resolveRecipients($onlyAdminId);
        $narrativeCache = [];
        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($recipients as $recipient) {
            try {
                $result = $this->generateForAdmin($recipient, $period, $force, null, $narrativeCache);
                if ($result['status'] === 'skipped') {
                    $skipped++;
                    continue;
                }

                $recipient->notify(new AiOperationalBriefingNotification($result['briefing']));
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Gagal mengirim AiOperationalBriefingNotification', [
                    'admin_id' => $recipient->id,
                    'period_type' => $period,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'config_active' => $configActive,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{markdown: string, is_fallback: bool}
     */
    private function composeNarrative(array $snapshot, string $period, string $scopeLabel): array
    {
        if ($this->gemini->isConfigured()) {
            $periodWord = $period === 'weekly' ? 'mingguan' : 'harian';
            $systemInstruction = <<<'EOT'
Kamu adalah HaloAPU AI, analis operasional sistem Helpdesk & Sarana Prasarana HaloAPU.
Susun ringkasan eksekutif operasional dalam Bahasa Indonesia yang ringkas, jelas, dan actionable (maksimal sekitar 250 kata) dengan format Markdown rapi.
ATURAN:
1. Gunakan HANYA angka, nama layanan, dan nomor tiket yang ada pada data JSON pengguna. Jangan mengarang angka atau tiket.
2. Cantumkan ringkasan kondisi utama dan tepat 3 prioritas tindakan konkret berbasis angka di data.
3. Jika menyebut tiket berisiko SLA dari daftar `top_at_risk_tickets`, format sebagai `[#formatted_id](url)`.
EOT;

            $jsonSnapshot = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $userPrompt = "Buat briefing {$periodWord} untuk cakupan akses '{$scopeLabel}'.\nData metrik operasional:\n{$jsonSnapshot}";

            $aiResult = $this->gemini->generateText($systemInstruction, $userPrompt, 25);
            if ($aiResult['ok'] && trim($aiResult['reply']) !== '') {
                return [
                    'markdown' => trim($aiResult['reply']),
                    'is_fallback' => false,
                ];
            }
        }

        return [
            'markdown' => $this->buildDeterministicFallbackMarkdown($snapshot, $period, $scopeLabel),
            'is_fallback' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function buildDeterministicFallbackMarkdown(array $snapshot, string $period, string $scopeLabel): string
    {
        $periodLabel = (string) ($snapshot['period_label'] ?? '-');
        $newTickets = (int) ($snapshot['new_tickets'] ?? 0);
        $solvedTickets = (int) ($snapshot['solved_tickets'] ?? 0);
        $activeOpen = (int) ($snapshot['active_open'] ?? 0);
        $activeOnProgress = (int) ($snapshot['active_on_progress'] ?? 0);
        $activePending = (int) ($snapshot['active_pending'] ?? 0);
        $stalePending = (int) ($snapshot['stale_pending_gt_2_days'] ?? 0);
        $slaAtRisk = (int) ($snapshot['sla_at_risk_count'] ?? 0);
        $slaBreached = (int) ($snapshot['sla_breached_count'] ?? 0);
        $csatCount = (int) ($snapshot['csat_count'] ?? 0);
        $csatAvg = (float) ($snapshot['csat_avg'] ?? 0.0);
        $topAtRisk = is_array($snapshot['top_at_risk_tickets'] ?? null) ? $snapshot['top_at_risk_tickets'] : [];
        $topServices = is_array($snapshot['top_services'] ?? null) ? $snapshot['top_services'] : [];

        $heading = $period === 'weekly' ? 'Ringkasan Eksekutif Mingguan' : 'Ringkasan Eksekutif Harian';
        $csatText = $csatCount > 0 ? "{$csatAvg}/5 ({$csatCount} ulasan)" : 'Belum ada ulasan pada periode ini';

        $lines = [
            "### {$heading} ({$periodLabel})",
            "**Cakupan:** {$scopeLabel}",
            '',
            "- **Tiket Masuk:** {$newTickets} | **Selesai:** {$solvedTickets}",
            "- **Beban Aktif Saat Ini:** {$activeOpen} Open, {$activeOnProgress} Diproses, {$activePending} Pending ({$stalePending} pending > 2 hari)",
            "- **Status SLA Aktif:** {$slaAtRisk} tiket berisiko/mendekati batas ({$slaBreached} sudah breach)",
            "- **Kepuasan Pemohon (CSAT):** {$csatText}",
        ];

        if (! empty($topServices)) {
            $serviceParts = array_map(
                static fn (array $svc): string => ($svc['nama_layanan'] ?? '-') . ' (' . ($svc['count'] ?? 0) . ')',
                $topServices
            );
            $lines[] = '- **Layanan Terbanyak:** ' . implode(', ', $serviceParts);
        }

        if (! empty($topAtRisk)) {
            $lines[] = '';
            $lines[] = '**Tiket Prioritas SLA:**';
            foreach ($topAtRisk as $ticket) {
                $fmtId = (string) ($ticket['formatted_id'] ?? '');
                $url = (string) ($ticket['url'] ?? '/admin/tiket/' . ($ticket['ticket_id'] ?? ''));
                $layanan = (string) ($ticket['layanan'] ?? '-');
                $status = (string) ($ticket['status'] ?? '-');
                $lines[] = "- [#{$fmtId}]({$url}) — {$layanan} (status: {$status})";
            }
        }

        $priorities = [];
        if ($slaAtRisk > 0 || $slaBreached > 0) {
            $priorities[] = "1. Tangani segera **{$slaAtRisk} tiket berisiko SLA** (termasuk {$slaBreached} tiket breach) agar eskalasi tidak berlanjut.";
        } else {
            $priorities[] = '1. Pertahankan kepatuhan SLA aktif dengan memantau antrean tiket masuk secara berkala.';
        }

        if ($stalePending > 0) {
            $priorities[] = "2. Tindak lanjuti **{$stalePending} tiket pending > 2 hari** dengan menghubungi pemohon atau memverifikasi kelengkapan.";
        } elseif ($activeOpen > 0) {
            $priorities[] = "2. Distribusikan **{$activeOpen} tiket berstatus Open** ke petugas unit terkait agar segera diproses.";
        } else {
            $priorities[] = '2. Pastikan tiket aktif yang sedang diproses diperbarui progresnya secara berkala kepada pemohon.';
        }

        if (! empty($topServices)) {
            $topSvcName = (string) ($topServices[0]['nama_layanan'] ?? 'layanan utama');
            $topSvcCount = (int) ($topServices[0]['count'] ?? 0);
            $priorities[] = "3. Evaluasi kapasitas dan kesiapan petugas pada layanan **{$topSvcName}** ({$topSvcCount} tiket pada periode ini).";
        } else {
            $priorities[] = '3. Tinjau ulang beban kerja unit dan ulasan CSAT untuk menjaga kualitas layanan.';
        }

        $lines[] = '';
        $lines[] = '**3 Prioritas Tindakan:**';
        foreach ($priorities as $priority) {
            $lines[] = $priority;
        }

        return implode("\n", $lines);
    }
}
