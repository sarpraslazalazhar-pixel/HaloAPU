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

        $bookings = \App\Models\RoomVehicleBooking::where('status', 'on_proses')
            ->whereDate('tanggal_mulai', $targetDate)
            ->with(['ticket.subUnit.unit.admins', 'ticket.user'])
            ->get();

        $bookingIds = $bookings->pluck('id')->toArray();

        $sentBookingIds = \Illuminate\Notifications\DatabaseNotification::where('type', BookingReminderNotification::class)
            ->whereDate('created_at', today())
            ->get()
            ->map(fn ($n) => isset($n->data['booking_id']) ? (int)$n->data['booking_id'] : null)
            ->filter()
            ->toArray();

        $sent = 0;

        foreach ($bookings as $booking) {
            // Cek apakah reminder sudah pernah dikirim hari ini
            if (in_array($booking->id, $sentBookingIds)) {
                continue;
            }

            // Kirim ke admin unit terkait
            $unit = $booking->ticket?->subUnit?->unit;
            if ($unit) {
                $admins = $unit->admins;
                foreach ($admins as $admin) {
                    $admin->notify(new BookingReminderNotification($booking));
                    $admin->notify(new \App\Notifications\BrowserNotification(
                        "Pengingat Booking",
                        "Booking tiket #{$booking->ticket->ticket_number} hampir tiba tanggal mulainya.",
                        "/admin/tiket/{$booking->ticket->id}"
                    ));
                    $sent++;
                }
            }

            // Kirim ke User pemesan
            $user = $booking->ticket?->user;
            if ($user) {
                $user->notify(new BookingReminderNotification($booking));
                $user->notify(new \App\Notifications\BrowserNotification(
                    "Pengingat Booking",
                    "Booking tiket Anda #{$booking->ticket->ticket_number} hampir tiba tanggal mulainya.",
                    "/user/tiket/{$booking->ticket->id}"
                ));
                $sent++;
            }
        }

        $this->info("Selesai. {$bookings->count()} booking dicek, {$sent} reminder terkirim.");
        return Command::SUCCESS;
    }
}
