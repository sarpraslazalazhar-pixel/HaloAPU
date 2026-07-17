<?php
$sla = App\Models\TicketSlaTracking::where('ticket_id', 60)->first();
if ($sla) {
    echo "responded_at: " . $sla->responded_at . PHP_EOL;
    echo "deadline: " . $sla->sla_response_deadline . PHP_EOL;
    echo "is_response_breached: " . ($sla->is_response_breached ? 'true' : 'false') . PHP_EOL;

    if ($sla->responded_at && $sla->sla_response_deadline) {
        $responded = new DateTime($sla->responded_at);
        $deadline = new DateTime($sla->sla_response_deadline);
        if ($responded > $deadline) {
            $sla->is_response_breached = true;
            $sla->save();
            echo "FIXED: marked response as breached" . PHP_EOL;
        }
    }
} else {
    echo "No SLA tracking for ticket 60" . PHP_EOL;
}
