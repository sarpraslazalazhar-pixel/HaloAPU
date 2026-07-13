<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\ReminderConfig;
// use App\Models\RoomVehicleBooking; // Adjust to actual model name if different
use App\Notifications\BookingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BookingReminderCommand extends Command
{
    protected $signature = 'reminder:booking';
    protected $description = 'Kirim reminder untuk booking yang mendekati tanggal mulai';

    public function handle(): int
    {
        $config = ReminderConfig::getConfig('booking');
        if (!$config) {
            $this->info('Reminder booking tidak aktif.');
            return Command::SUCCESS;
        }

        $leadDays = $config->lead_time_value; // hari
        $targetDate = now()->addDays($leadDays)->toDateString();

        // Check if model RoomVehicleBooking exists
        if (!class_exists('App\Models\RoomVehicleBooking')) {
            $this->error('Model RoomVehicleBooking tidak ditemukan.');
            return Command::FAILURE;
        }

        $bookings = \App\Models\RoomVehicleBooking::where('status', 'Disetujui')
            ->whereDate('tanggal_mulai', $targetDate)
            ->with(['ticket.subUnit.unit', 'ticket.user'])
            ->get();

        $sent = 0;

        foreach ($bookings as $booking) {
            // Cek apakah reminder sudah pernah dikirim hari ini
            $alreadySent = \Illuminate\Notifications\DatabaseNotification::where('type', BookingReminderNotification::class)
                ->where('data->booking_id', $booking->id)
                ->whereDate('created_at', today())
                ->exists();

            if ($alreadySent) continue;

            // Kirim ke admin unit terkait
            $unitId = $booking->ticket?->subUnit?->unit_id;
            if ($unitId) {
                $admins = Admin::whereHas('units', fn ($q) => $q->where('unit_id', $unitId))->get();
                foreach ($admins as $admin) {
                    $admin->notify(new BookingReminderNotification($booking));
                    $sent++;
                }
            }
        }

        $this->info("Selesai. {$bookings->count()} booking dicek, {$sent} reminder terkirim.");
        return Command::SUCCESS;
    }
}
