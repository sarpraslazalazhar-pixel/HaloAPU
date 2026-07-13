<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$bookings = App\Models\RoomVehicleBooking::with('ticket')->get();
echo "Total bookings: " . $bookings->count() . PHP_EOL;
foreach ($bookings as $b) {
    $ts = $b->ticket ? $b->ticket->status : '-';
    echo "id={$b->id} aset={$b->nama_aset} tipe={$b->tipe} mulai={$b->tanggal_mulai} selesai={$b->tanggal_selesai} booking_status={$b->status} ticket_status={$ts}" . PHP_EOL;
}

// Also check tickets to see all
echo PHP_EOL . "--- TICKETS ---" . PHP_EOL;
$tickets = App\Models\Ticket::with('booking')->get();
foreach ($tickets as $t) {
    $b = $t->booking;
    $bInfo = $b ? "booking_id={$b->id} aset={$b->nama_asset}" : "no-booking";
    echo "ticket_id={$t->id} status={$t->status} {$bInfo}" . PHP_EOL;
}
