<?php

namespace App\Console\Commands;

use App\Models\RoomVehicleBooking;
use App\Notifications\BookingReminderNotification;
use App\Notifications\BrowserNotification;
use App\Services\ChatReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class BookingScheduleCheckCommand extends Command
{
    protected $signature = 'booking:schedule-check';
    protected $description = 'Otomasi status selesai dan kirim reminder booking (H-10m, mulai, H-15m denda)';

    public function handle(): int
    {
        $now = Carbon::now();

        // 1. Auto-Complete booking yang sudah lewat dari 1 jam selesai
        RoomVehicleBooking::whereNotIn('status', ['reject', 'dibatalkan', 'selesai'])
            ->where('tanggal_selesai', '<=', $now->copy()->subHour())
            ->update(['status' => 'selesai']);

        // 2. Ambil booking aktif yang relevan untuk notifikasi (mulai atau selesai dalam range waktu)
        $activeBookings = RoomVehicleBooking::whereNotIn('status', ['reject', 'dibatalkan', 'selesai'])
            ->with(['ticket.user', 'ticket.subUnit.unit.admins'])
            ->where(function ($q) use ($now) {
                // Booking yang akan mulai dalam 20 menit ke depan, atau baru mulai dalam 5 menit terakhir
                $q->whereBetween('tanggal_mulai', [$now->copy()->subMinutes(5), $now->copy()->addMinutes(20)])
                  // Atau booking yang akan selesai dalam 25 menit ke depan
                  ->orWhereBetween('tanggal_selesai', [$now->copy()->subMinutes(2), $now->copy()->addMinutes(25)]);
            })
            ->get();

        $sentCount = 0;

        foreach ($activeBookings as $booking) {
            $mulai = Carbon::parse($booking->tanggal_mulai);
            $selesai = Carbon::parse($booking->tanggal_selesai);

            // A. Notifikasi 10 menit sebelum mulai (rentang 9 s/d 11 menit sebelum mulai)
            $diffStartMin = $now->diffInMinutes($mulai, false);
            if ($diffStartMin >= 8 && $diffStartMin <= 11) {
                $cacheKey = "booking_notif_h10_{$booking->id}";
                if (!Cache::has($cacheKey)) {
                    $this->sendNotifications($booking, 'h_min_10', "Pengingat Booking (10 Menit Lagi)", "Booking {$booking->nama_aset} akan dimulai dalam 10 menit.");
                    Cache::put($cacheKey, true, now()->addHours(24));
                    $sentCount++;
                }
            }

            // B. Notifikasi saat jadwal mulai (rentang -2 s/d +1 menit dari waktu mulai)
            if ($diffStartMin >= -2 && $diffStartMin <= 1) {
                $cacheKey = "booking_notif_started_{$booking->id}";
                if (!Cache::has($cacheKey)) {
                    $this->sendNotifications($booking, 'started', "Jadwal Booking Dimulai", "Jadwal penggunaan {$booking->nama_aset} telah dimulai.");
                    Cache::put($cacheKey, true, now()->addHours(24));
                    $sentCount++;
                }
            }

            // C. Notifikasi 15 menit sebelum waktu habis (rentang 13 s/d 16 menit sebelum selesai)
            $diffEndMin = $now->diffInMinutes($selesai, false);
            if ($diffEndMin >= 13 && $diffEndMin <= 16) {
                $cacheKey = "booking_notif_h15_ending_{$booking->id}";
                if (!Cache::has($cacheKey)) {
                    $this->sendNotifications(
                        $booking,
                        'h_min_15_ending',
                        "PERINGATAN WAKTU HABIS (15 Menit)",
                        "Waktu pemakaian {$booking->nama_aset} tersisa 15 menit. Keterlambatan pengosongan dapat dikenakan denda."
                    );
                    Cache::put($cacheKey, true, now()->addHours(24));
                    $sentCount++;
                }
            }
        }

        $this->info("Pengecekan booking selesai. {$sentCount} notifikasi terkirim.");
        return Command::SUCCESS;
    }

    /**
     * Kirim notifikasi ke User, Admin Unit, Browser Notification, dan Chat.
     */
    protected function sendNotifications(RoomVehicleBooking $booking, string $stage, string $browserTitle, string $browserBody): void
    {
        $ticket = $booking->ticket;
        $user = $ticket?->user;
        $unitAdmins = $ticket?->subUnit?->unit?->admins ?? collect();

        // 1. Kirim ke User pemesan
        if ($user) {
            $user->notify(new BookingReminderNotification($booking, $stage));
            $user->notify(new BrowserNotification(
                $browserTitle,
                $browserBody,
                $ticket ? "/tiket/{$ticket->id}" : "/monitor"
            ));
        }

        // 2. Kirim ke Admin unit terkait
        foreach ($unitAdmins as $admin) {
            $admin->notify(new BookingReminderNotification($booking, $stage));
            $admin->notify(new BrowserNotification(
                $browserTitle,
                "User " . ($user->name ?? $user->username ?? 'pemesan') . ": " . $browserBody,
                $ticket ? "/admin/tiketing/{$ticket->id}" : "/admin/monitor"
            ));
        }

        // 3. Kirim ke Percakapan Chat Tiket jika ada service-nya
        if ($ticket && class_exists(ChatReminderService::class)) {
            $tipeLabel = $booking->tipe === 'ruang' ? 'Ruangan' : 'Kendaraan';
            $tglMulai = Carbon::parse($booking->tanggal_mulai)->format('d M Y H:i');
            $tglSelesai = Carbon::parse($booking->tanggal_selesai)->format('d M Y H:i');

            if ($stage === 'h_min_10') {
                $chatBody = "🔔 [PENGINGAT JADWAL 10 MENIT LAGI]\nPenggunaan {$tipeLabel} \"{$booking->nama_aset}\" akan dimulai dalam 10 menit ({$tglMulai}).\nMohon segera bersiap.";
            } elseif ($stage === 'started') {
                $chatBody = "▶️ [JADWAL PEMAKAIAN TELAH DIMULAI]\nPenggunaan {$tipeLabel} \"{$booking->nama_aset}\" resmi dimulai sekarang ({$tglMulai} s/d {$tglSelesai}).\nHarap menjaga kebersihan dan fasilitas aset.";
            } elseif ($stage === 'h_min_15_ending') {
                $chatBody = "⚠️ [PERINGATAN WAKTU HABIS - 15 MENIT LAGI]\nWaktu pemakaian {$tipeLabel} \"{$booking->nama_aset}\" tersisa 15 menit (berakhir {$tglSelesai}).\nHarap segera bersiap mengosongkan aset. Keterlambatan dapat dikenakan denda sesuai peraturan.";
            } else {
                $chatBody = "🔔 [PENGINGAT BOOKING]\nJadwal pemakaian {$tipeLabel} \"{$booking->nama_aset}\" pada {$tglMulai} s/d {$tglSelesai}.";
            }

            ChatReminderService::sendTicketReminder($ticket, $chatBody);
        }
    }
}
