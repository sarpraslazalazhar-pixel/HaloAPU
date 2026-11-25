<?php

namespace App\Services\Ai;

use App\Models\Admin;
use App\Services\Ai\Tools\AiToolRegistry;
use App\Services\Ai\Tools\AiToolResult;

class LocalFallbackAnalyzer
{
    public function __construct(
        private readonly AiAccessScope $scope,
        private readonly AiToolRegistry $registry,
    ) {}

    /**
     * Rule-based fallback using the exact same scoped read-only tools as Gemini.
     *
     * @return array{reply: string, charts: array<int, array<string, mixed>>, tools_used: array<int, string>}
     */
    public function analyze(Admin $admin, string $userMessage, ?string $periodFilter = null, ?string $prefix = null): array
    {
        $trimmed = trim($userMessage);
        $lower = mb_strtolower($trimmed);

        if ($this->isOutOfContext($lower)) {
            return [
                'reply' => 'Mohon maaf, sebagai **HaloAPU AI**, saya dirancang khusus hanya untuk membantu menganalisis data operasional HaloAPU (tiket, SLA, CSAT, kinerja petugas, dan jadwal peminjaman aset). Silakan ajukan pertanyaan terkait operasional HaloAPU.',
                'charts' => [],
                'tools_used' => [],
            ];
        }

        $period = $periodFilter ?: '30d';
        $toolsUsed = [];
        $charts = [];
        $chartIndexById = [];

        $runTool = function (string $name, array $args = []) use ($admin, &$toolsUsed, &$charts, &$chartIndexById): AiToolResult {
            $result = $this->registry->execute($name, $admin, $args);
            if (! in_array($name, $toolsUsed, true)) {
                $toolsUsed[] = $name;
            }
            if ($result->chart !== null && isset($result->chart['id'])) {
                $chartId = (string) $result->chart['id'];
                if (array_key_exists($chartId, $chartIndexById)) {
                    $charts[$chartIndexById[$chartId]] = $result->chart;
                } else {
                    $chartIndexById[$chartId] = count($charts);
                    $charts[] = $result->chart;
                }
            }

            return $result;
        };

        $body = '';

        if ($ticketId = $this->extractTicketId($trimmed)) {
            $detail = $runTool('get_ticket_detail', ['ticket_id' => $ticketId])->data;
            $body = $this->formatTicketDetail($detail);
        } elseif ($this->containsAny($lower, ['banding', ' vs ', 'bulan lalu', 'minggu lalu', 'perbandingan', 'komparasi', 'tren'])) {
            $comparison = str_contains($lower, 'minggu') ? 'this_week_vs_last_week' : 'this_month_vs_last_month';
            $compData = $runTool('compare_periods', ['comparison' => $comparison])->data;
            $body = $this->formatComparison($compData);
        } elseif ($this->containsAny($lower, ['berisiko', 'at risk', 'risiko', 'terlambat', 'breach', 'overdue', 'telat', 'deadline', 'macet'])) {
            $onlyBreached = str_contains($lower, 'breach') && ! str_contains($lower, 'risiko') && ! str_contains($lower, 'mendekat');
            $riskData = $runTool('list_at_risk_sla_tickets', ['only_breached' => $onlyBreached, 'limit' => 10])->data;
            $slaData = $runTool('get_sla_compliance', ['period' => $period])->data;
            $body = $this->formatSlaAndRisk($slaData, $riskData);
        } elseif ($this->containsAny($lower, ['sla', 'kepatuhan', 'lambat', 'respon', 'resolusi'])) {
            $slaData = $runTool('get_sla_compliance', ['period' => $period])->data;
            $riskData = $runTool('list_at_risk_sla_tickets', ['only_breached' => false, 'limit' => 10])->data;
            $body = $this->formatSlaAndRisk($slaData, $riskData);
        } elseif ($this->containsAny($lower, ['operator', 'petugas', 'teknisi', 'kinerja', 'beban', 'workload', 'tim'])) {
            $opData = $runTool('get_operator_performance', ['period' => $period, 'limit' => 10])->data;
            $body = $this->formatOperatorPerformance($opData);
        } elseif ($this->containsAny($lower, ['csat', 'puas', 'kepuasan', 'rating', 'bintang', 'ulasan', 'komentar'])) {
            $maxRating = $this->containsAny($lower, ['rendah', 'buruk', 'jelek', 'komplain', 'keluhan']) ? 3 : null;
            $args = ['period' => $period, 'limit' => 8];
            if ($maxRating !== null) {
                $args['max_rating'] = $maxRating;
            }
            $csatData = $runTool('get_csat_analysis', $args)->data;
            $body = $this->formatCsatAnalysis($csatData);
        } elseif ($this->containsAny($lower, ['booking', 'ruang', 'ruangan', 'kendaraan', 'mobil', 'jadwal', 'pinjam', 'peminjaman', 'aset'])) {
            $args = ['limit' => 15];
            if (str_contains($lower, 'ruang')) {
                $args['tipe'] = 'Ruang';
            } elseif (str_contains($lower, 'kendaraan') || str_contains($lower, 'mobil')) {
                $args['tipe'] = 'Kendaraan';
            }
            $bookingData = $runTool('get_booking_schedule', $args)->data;
            $body = $this->formatBookingSchedule($bookingData);
        } elseif ($this->containsAny($lower, ['cari', 'carikan', 'temukan', 'daftar tiket', 'list tiket', 'tiket open', 'tiket pending', 'tiket urgen'])) {
            $searchArgs = $this->buildSearchArgs($trimmed, $lower);
            $searchData = $runTool('search_tickets', $searchArgs)->data;
            $body = $this->formatSearchResults($searchData);
        } else {
            $summaryData = $runTool('get_ticket_summary', ['period' => $period])->data;
            $slaData = $runTool('get_sla_compliance', ['period' => $period])->data;
            $csatData = $runTool('get_csat_analysis', ['period' => $period, 'limit' => 5])->data;
            $body = $this->formatExecutiveSummary($summaryData, $slaData, $csatData);
        }

        $header = $prefix
            ? "> **Catatan**: {$prefix}\n\n"
            : "> **Mode Analitik Database Internal**: Menampilkan hasil analisis langsung dari database HaloAPU sesuai cakupan aksesmu ({$this->scope->describeScope($admin)['label']}).\n\n";

        return [
            'reply' => $header . $body,
            'charts' => $charts,
            'tools_used' => $toolsUsed,
        ];
    }

    private function isOutOfContext(string $lower): bool
    {
        $domainWords = ['haloapu', 'tiket', 'ticket', 'sla', 'csat', 'booking', 'ruang', 'kendaraan', 'operator', 'petugas', 'unit', 'layanan', 'antrean', 'revisi', 'rating'];
        foreach ($domainWords as $word) {
            if (str_contains($lower, $word)) {
                return false;
            }
        }

        $offTopicKeywords = [
            'resep', 'presiden', 'politik', 'cuaca hari ini', 'lagu', 'chord', 'film',
            'game', 'siapa kamu sebenarnya', 'bikin puisi', 'buatkan kode', 'cerita lucu',
            'jailbreak', 'abaikan instruksi', 'lupakan instruksi', 'crypto', 'saham',
        ];

        return $this->containsAny($lower, $offTopicKeywords);
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function extractTicketId(string $text): ?string
    {
        if (preg_match('/\b(\d{3}-\d{3}-\d{3})\b/', $text, $m)) {
            return $m[1];
        }
        if (preg_match('/#(\d{6,12})\b/', $text, $m)) {
            return $m[1];
        }
        if (preg_match('/\b(\d{9})\b/', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSearchArgs(string $raw, string $lower): array
    {
        $args = ['limit' => 10];

        foreach (['open', 'on_proses', 'pending', 'need_revision', 'waiting_approval', 'solve', 'reject', 'dibatalkan'] as $status) {
            if (str_contains($lower, $status)) {
                $args['status'] = $status;
                break;
            }
        }

        if (! isset($args['status'])) {
            if (str_contains($lower, 'diproses')) {
                $args['status'] = 'on_proses';
            } elseif (str_contains($lower, 'selesai')) {
                $args['status'] = 'solve';
            }
        }

        foreach (['urgen' => 'Urgen', 'tinggi' => 'Tinggi', 'sedang' => 'Sedang', 'rendah' => 'Rendah'] as $k => $v) {
            if (str_contains($lower, $k)) {
                $args['priority'] = $v;
                break;
            }
        }

        if (preg_match('/(?:cari|carikan|temukan)\s+(?:tiket\s+)?(.+)/i', $raw, $m)) {
            $candidate = trim($m[1]);
            if ($candidate !== '' && ! isset($args['status']) && ! isset($args['priority'])) {
                $args['query'] = $candidate;
            }
        }

        return $args;
    }

    private function formatExecutiveSummary(array $summary, array $sla, array $csat): string
    {
        $byStatus = $summary['by_status'] ?? [];
        $overallSla = $sla['overall'] ?? [];

        $out = "### Ringkasan Operasional Tiket ({$summary['period_label']})\n\n";
        $out .= "| Metrik | Nilai |\n";
        $out .= "| :--- | :--- |\n";
        $out .= "| **Total Tiket** | **{$summary['total_tickets']}** |\n";
        $out .= "| Tiket Aktif | {$summary['active_tickets']} |\n";
        $out .= "| Open (Menunggu) | " . ($byStatus['open'] ?? 0) . " |\n";
        $out .= "| Diproses (on_proses) | " . ($byStatus['on_proses'] ?? 0) . " |\n";
        $out .= "| Pending | " . ($byStatus['pending'] ?? 0) . " |\n";
        $out .= "| Perlu Revisi | " . ($byStatus['need_revision'] ?? 0) . " |\n";
        $out .= "| Selesai (Solve) | " . ($byStatus['solve'] ?? 0) . " |\n";
        $out .= "| Ditolak / Dibatalkan | " . (($byStatus['reject'] ?? 0) + ($byStatus['dibatalkan'] ?? 0)) . " |\n";
        $out .= "| Kepatuhan SLA Respon | " . ($overallSla['response_compliance_pct'] ?? 100) . "% |\n";
        $out .= "| Kepatuhan SLA Resolusi | " . ($overallSla['resolution_compliance_pct'] ?? 100) . "% |\n";
        $out .= "| Rata-rata Rating CSAT | " . ($csat['avg_rating'] ?? 0) . " / 5.0 ({$csat['total_reviews']} ulasan) |\n\n";

        if (! empty($summary['top_units'])) {
            $out .= "#### Unit Layanan Teratas\n";
            foreach (array_slice($summary['top_units'], 0, 5) as $unit) {
                $out .= "- **{$unit['nama_unit']}**: {$unit['total']} tiket\n";
            }
        }

        return $out;
    }

    private function formatSlaAndRisk(array $sla, array $risk): string
    {
        $overall = $sla['overall'] ?? [];
        $out = "### Analisis Kepatuhan SLA ({$sla['period_label']})\n\n";
        $out .= "- **Total Tiket Terpantau SLA**: " . ($overall['total_sla'] ?? 0) . "\n";
        $out .= "- **Kepatuhan Respon**: **" . ($overall['response_compliance_pct'] ?? 100) . "%** ({$overall['response_breached']} breach dari {$overall['response_evaluated']} dievaluasi)\n";
        $out .= "- **Kepatuhan Resolusi**: **" . ($overall['resolution_compliance_pct'] ?? 100) . "%** ({$overall['resolution_breached']} breach dari {$overall['resolution_evaluated']} dievaluasi)\n\n";

        if (! empty($sla['by_unit'])) {
            $out .= "| Unit | Total SLA | Respon % | Resolusi % |\n";
            $out .= "| :--- | :--- | :--- | :--- |\n";
            foreach ($sla['by_unit'] as $row) {
                $out .= "| {$row['nama_unit']} | {$row['total_sla']} | {$row['response_compliance_pct']}% | {$row['resolution_compliance_pct']}% |\n";
            }
            $out .= "\n";
        }

        $tickets = $risk['tickets'] ?? [];
        $out .= "#### Tiket Aktif Berisiko / Breach SLA ({$risk['total_at_risk']} tiket)\n";
        if (empty($tickets)) {
            $out .= "- Tidak ada tiket aktif yang breach atau mendekati batas waktu dalam {$risk['within_hours']} jam ke depan.\n";
        } else {
            foreach ($tickets as $t) {
                $flags = [];
                if (! empty($t['is_response_breached'])) {
                    $flags[] = 'Breach Respon';
                }
                if (! empty($t['is_resolution_breached'])) {
                    $flags[] = 'Breach Resolusi';
                }
                if (($t['current_tier'] ?? 0) >= 1) {
                    $flags[] = 'Tier ' . $t['current_tier'];
                }
                $flagText = ! empty($flags) ? implode(', ', $flags) : 'Mendekati Deadline';
                $out .= "- [#{$t['formatted_id']}]({$t['url']}) ({$t['layanan']} - {$t['unit']}) | Status: `{$t['status']}` | Petugas: {$t['petugas']} | **{$flagText}**\n";
            }
        }

        return $out;
    }

    private function formatOperatorPerformance(array $data): string
    {
        $summary = $data['summary'] ?? [];
        $out = "### Laporan Kinerja & Beban Petugas ({$data['period_label']})\n\n";

        if (! empty($data['scope_note'])) {
            $out .= "> {$data['scope_note']}\n\n";
        }

        $out .= "- **Total Petugas**: " . ($summary['total_operators'] ?? 0) . " | **Tiket Aktif**: " . ($summary['total_active'] ?? 0) . " | **Tiket Selesai**: " . ($summary['total_solved'] ?? 0) . "\n\n";

        $operators = $data['operators'] ?? [];
        if (empty($operators)) {
            return $out . "Belum ada data petugas dalam cakupan dan periode ini.\n";
        }

        $out .= "| Petugas | Aktif | Selesai | Total | SLA Resolusi | CSAT |\n";
        $out .= "| :--- | :--- | :--- | :--- | :--- | :--- |\n";
        foreach ($operators as $op) {
            $sla = $op['resolution_compliance'] !== null ? $op['resolution_compliance'] . '%' : '-';
            $csat = $op['avg_rating'] !== null ? $op['avg_rating'] . '/5' : '-';
            $out .= "| {$op['name']} | {$op['active_tickets']} | {$op['solved_tickets']} | {$op['total_tickets']} | {$sla} | {$csat} |\n";
        }

        return $out;
    }

    private function formatCsatAnalysis(array $data): string
    {
        $out = "### Analisis Kepuasan Pengguna / CSAT ({$data['period_label']})\n\n";
        $out .= "- **Rata-rata Rating**: **{$data['avg_rating']} / 5.0**\n";
        $out .= "- **Total Ulasan**: {$data['total_reviews']}\n\n";

        if (! empty($data['by_unit'])) {
            $out .= "| Unit Layanan | Rata-rata | Jumlah Ulasan |\n";
            $out .= "| :--- | :--- | :--- |\n";
            foreach ($data['by_unit'] as $u) {
                $out .= "| {$u['nama_unit']} | {$u['avg_rating']} | {$u['total_reviews']} |\n";
            }
            $out .= "\n";
        }

        $reviews = $data['recent_reviews'] ?? [];
        $out .= "#### Ulasan Terbaru\n";
        if (empty($reviews)) {
            $out .= "- Belum ada ulasan pada filter ini.\n";
        } else {
            foreach ($reviews as $rev) {
                $out .= "- [#{$rev['formatted_id']}]({$rev['url']}) **{$rev['rating']}/5** ({$rev['layanan']}) oleh {$rev['pemohon']}: *\"{$rev['komentar']}\"*\n";
            }
        }

        return $out;
    }

    private function formatBookingSchedule(array $data): string
    {
        $out = "### Jadwal Peminjaman Ruang & Kendaraan ({$data['date_from']} s.d. {$data['date_to']})\n\n";
        $bookings = $data['bookings'] ?? [];

        if (empty($bookings)) {
            return $out . "Tidak ada jadwal peminjaman pada rentang tanggal tersebut.\n";
        }

        $out .= "| Tiket | Tipe | Aset | Waktu | Status | Pemohon |\n";
        $out .= "| :--- | :--- | :--- | :--- | :--- | :--- |\n";
        foreach ($bookings as $b) {
            $out .= "| [#{$b['formatted_id']}]({$b['url']}) | {$b['tipe']} | {$b['nama_aset']} | {$b['tanggal_mulai']} - {$b['tanggal_selesai']} | {$b['status']} | {$b['pemohon']} |\n";
        }

        return $out;
    }

    private function formatSearchResults(array $data): string
    {
        $out = "### Hasil Pencarian Tiket ({$data['total_matched']} ditemukan)\n\n";
        $tickets = $data['tickets'] ?? [];

        if (empty($tickets)) {
            return $out . "Tidak ada tiket yang cocok dalam cakupan akses kamu.\n";
        }

        $out .= "| No Tiket | Layanan | Unit | Status | Prioritas | Pemohon | Petugas |\n";
        $out .= "| :--- | :--- | :--- | :--- | :--- | :--- | :--- |\n";
        foreach ($tickets as $t) {
            $out .= "| [#{$t['formatted_id']}]({$t['url']}) | {$t['layanan']} | {$t['unit']} | `{$t['status']}` | {$t['priority']} | {$t['pemohon']} | {$t['petugas']} |\n";
        }

        return $out;
    }

    private function formatTicketDetail(array $detail): string
    {
        if (! empty($detail['error'])) {
            return "### Detail Tiket\n\n{$detail['error']}\n";
        }

        $out = "### Detail Tiket [#{$detail['formatted_id']}]({$detail['url']})\n\n";
        $out .= "- **Layanan**: {$detail['layanan']} ({$detail['unit']})\n";
        $out .= "- **Status**: `{$detail['status']}` | **Prioritas**: {$detail['priority']}\n";
        $out .= "- **Pemohon**: {$detail['pemohon']} ({$detail['divisi']})\n";
        $out .= "- **Petugas**: {$detail['petugas']}\n";
        $out .= "- **Dibuat**: {$detail['created_at']} WIB\n\n";

        if (! empty($detail['sla'])) {
            $sla = $detail['sla'];
            $out .= "#### Status SLA\n";
            $out .= "- Deadline Respon: " . ($sla['sla_response_deadline'] ?? '-') . " (Breach: " . ($sla['is_response_breached'] ? 'Ya' : 'Tidak') . ")\n";
            $out .= "- Deadline Resolusi: " . ($sla['sla_resolution_deadline'] ?? '-') . " (Breach: " . ($sla['is_resolution_breached'] ? 'Ya' : 'Tidak') . ")\n\n";
        }

        if (! empty($detail['form_fields'])) {
            $out .= "#### Isian Formulir\n";
            foreach ($detail['form_fields'] as $field) {
                $out .= "- **{$field['label']}**: {$field['value']}\n";
            }
            $out .= "\n";
        }

        if (! empty($detail['recent_logs'])) {
            $out .= "#### Riwayat Terbaru\n";
            foreach (array_slice($detail['recent_logs'], 0, 5) as $log) {
                $out .= "- [{$log['timestamp']}] **{$log['aksi']}** ({$log['actor']}): " . ($log['catatan'] ?: '-') . "\n";
            }
        }

        return $out;
    }

    private function formatComparison(array $data): string
    {
        $curr = $data['current_period'] ?? [];
        $prev = $data['previous_period'] ?? [];
        $deltas = $data['deltas'] ?? [];

        $out = "### Perbandingan Periode: {$curr['label']} vs {$prev['label']}\n\n";
        $out .= "| Metrik | {$prev['label']} | {$curr['label']} | Perubahan |\n";
        $out .= "| :--- | :--- | :--- | :--- |\n";
        $out .= "| Total Tiket | {$prev['total_tickets']} | {$curr['total_tickets']} | {$deltas['total_tickets']['delta_pct']}% |\n";
        $out .= "| Tiket Selesai | {$prev['solved_tickets']} | {$curr['solved_tickets']} | {$deltas['solved_tickets']['delta_pct']}% |\n";
        $out .= "| Pelanggaran SLA | {$prev['sla_breaches']} | {$curr['sla_breaches']} | {$deltas['sla_breaches']['delta_pct']}% |\n";
        $out .= "| Rata-rata CSAT | {$prev['csat_avg']} | {$curr['csat_avg']} | {$deltas['csat_avg']['delta_pct']}% |\n";

        return $out;
    }
}
