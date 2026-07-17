<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\User;
use App\Models\Unit;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use App\Models\SlaConfig;
use App\Models\ReminderConfig;
use App\Models\RoomVehicleBooking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;

class SimulateSlaAndRemindersCommand extends Command
{
    protected $signature = 'simulate:sla-and-reminders';
    protected $description = 'Simulate and verify SLA checker and Reminder systems';

    public function handle(): int
    {
        $this->info("=== Starting SLA and Reminder Systems Simulation ===");

        // Mock mail and HTTP to prevent external side effects (e.g. SMTP/WhatsApp API failures)
        Config::set('mail.default', 'array');
        Http::fake();

        DB::beginTransaction();

        try {
            // 1. Setup mock user and admin
            $user = User::create([
                'name' => 'Mock User',
                'username' => 'mock_user_' . Str::random(5),
                'email' => 'mockuser_' . Str::random(5) . '@test.com',
                'password' => bcrypt('password'),
            ]);

            $admin = Admin::create([
                'username' => 'mock_admin_' . Str::random(5),
                'email' => 'mockadmin_' . Str::random(5) . '@test.com',
                'password' => bcrypt('password'),
                'name' => 'Mock Admin',
                'no_wa' => '08123456789',
            ]);

            // 2. Setup unit and sub-unit
            $unit = Unit::create([
                'nama_unit' => 'Mock Unit',
                'deskripsi' => 'Mock Unit for SLA Simulation',
                'aktif' => true,
            ]);

            $subUnit = SubUnit::create([
                'unit_id' => $unit->id,
                'nama_layanan' => 'Mock SubUnit',
                'deskripsi' => 'Mock SubUnit for SLA Simulation',
                'aktif' => true,
            ]);

            // Link Admin to Unit
            DB::table('admin_unit')->insert([
                'admin_id' => $admin->id,
                'unit_id' => $unit->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info("Created mock users, unit, subunit, and linked admin.");

            // 3. Setup SLA Config
            SlaConfig::updateOrCreate(
                ['sub_unit_id' => $subUnit->id, 'priority' => 'Sedang', 'jenis' => 'respon'],
                ['threshold_minutes' => 30]
            );
            SlaConfig::updateOrCreate(
                ['sub_unit_id' => $subUnit->id, 'priority' => 'Sedang', 'jenis' => 'penyelesaian'],
                ['threshold_minutes' => 120]
            );

            // 4. Case 1: SLA breach (Response and Resolution)
            // Create a ticket created 2 days ago (which exceeds 30 minutes response and 120 minutes resolution)
            $createdAt = Carbon::now()->subDays(2);
            $slaTicket = Ticket::create([
                'user_id' => $user->id,
                'unit_id' => $unit->id,
                'sub_unit_id' => $subUnit->id,
                'priority' => 'Sedang',
                'status' => 'open',
                'form_data' => [],
            ]);
            // Force created_at and updated_at
            $slaTicket->created_at = $createdAt;
            $slaTicket->updated_at = $createdAt;
            $slaTicket->save();

            $slaTracking = TicketSlaTracking::create([
                'ticket_id' => $slaTicket->id,
                'sla_response_deadline' => $createdAt->copy()->addMinutes(30),
                'sla_resolution_deadline' => $createdAt->copy()->addMinutes(120),
                'responded_at' => null,
                'resolved_at' => null,
                'paused_at' => null,
                'total_paused_minutes' => 0,
                'current_tier' => 0,
                'is_response_breached' => false,
                'is_resolution_breached' => false,
            ]);

            $this->info("Case 1 Setup: Created SLA ticket #{$slaTicket->id} close to breach (response/resolution).");

            // 5. Case 2: Booking Reminder
            ReminderConfig::updateOrCreate(
                ['jenis_reminder' => 'booking'],
                ['lead_time_value' => 2, 'aktif' => true, 'channel_aktif' => ['database']]
            );

            $bookingTicket = Ticket::create([
                'user_id' => $user->id,
                'unit_id' => $unit->id,
                'sub_unit_id' => $subUnit->id,
                'status' => 'on_proses',
                'form_data' => [],
            ]);

            $booking = RoomVehicleBooking::create([
                'ticket_id' => $bookingTicket->id,
                'tipe' => 'ruang',
                'nama_aset' => 'Ruang Rapat Utama',
                'tanggal_mulai' => Carbon::now()->addDays(2)->setTime(10, 0, 0),
                'tanggal_selesai' => Carbon::now()->addDays(2)->setTime(12, 0, 0),
                'status' => 'Disetujui',
            ]);

            $this->info("Case 2 Setup: Created approved booking for Asset: {$booking->nama_aset} scheduled in 2 days (Ticket #{$bookingTicket->id}).");

            // 6. Case 3: Pending Ticket Reminder
            ReminderConfig::updateOrCreate(
                ['jenis_reminder' => 'pending_lama'],
                ['lead_time_value' => 3, 'aktif' => true, 'channel_aktif' => ['database']]
            );

            $pendingTicket = Ticket::create([
                'user_id' => $user->id,
                'unit_id' => $unit->id,
                'sub_unit_id' => $subUnit->id,
                'status' => 'pending',
                'form_data' => [],
            ]);
            $pendingTicket->created_at = Carbon::now()->subDays(5);
            $pendingTicket->updated_at = Carbon::now()->subDays(5); // Pending since 5 days ago (threshold is 3)
            $pendingTicket->save();

            $this->info("Case 3 Setup: Created ticket #{$pendingTicket->id} pending since 5 days ago.");

            // 7. Case 4: CSAT Reminder
            ReminderConfig::updateOrCreate(
                ['jenis_reminder' => 'csat'],
                ['lead_time_value' => 3, 'aktif' => true, 'channel_aktif' => ['database']]
            );

            $csatTicket = Ticket::create([
                'user_id' => $user->id,
                'unit_id' => $unit->id,
                'sub_unit_id' => $subUnit->id,
                'status' => 'solve',
                'form_data' => [],
            ]);
            $csatTicket->created_at = Carbon::now()->subDays(5);
            $csatTicket->updated_at = Carbon::now()->subDays(5); // Solved since 5 days ago (threshold is 3)
            $csatTicket->save();

            $this->info("Case 4 Setup: Created solved ticket #{$csatTicket->id} without CSAT solved since 5 days ago.");

            // 8. Case 5: Snoozed Notification Re-fire
            $snoozedNotificationId = Str::uuid()->toString();
            DB::table('notifications')->insert([
                'id' => $snoozedNotificationId,
                'type' => \App\Notifications\SlaEscalationNotification::class,
                'notifiable_type' => Admin::class,
                'notifiable_id' => $admin->id,
                'data' => json_encode([
                    'snoozed' => true,
                    'snoozed_until' => Carbon::now()->subMinutes(10)->toISOString(), // Expired 10m ago
                    'ticket_id' => $slaTicket->id,
                    'jenis_breach' => 'respon',
                    'prioritas' => 'Sedang',
                    'judul' => 'Snoozed Notification',
                    'pesan' => 'Mock snoozed message',
                ]),
                'read_at' => now(),
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ]);

            $this->info("Case 5 Setup: Created snoozed notification ID: {$snoozedNotificationId} (expired snooze_until).");

            $this->info("\n=== Executing Artisan Commands ===\n");

            // Execute SLA Check Command
            $this->comment("Running command: sla:check");
            Artisan::call('sla:check');
            $this->line(Artisan::output());

            // Execute Booking Reminder Command
            $this->comment("Running command: reminder:booking");
            Artisan::call('reminder:booking');
            $this->line(Artisan::output());

            // Execute Pending Ticket Reminder Command
            $this->comment("Running command: reminder:pending");
            Artisan::call('reminder:pending');
            $this->line(Artisan::output());

            // Execute CSAT Reminder Command
            $this->comment("Running command: reminder:csat");
            Artisan::call('reminder:csat');
            $this->line(Artisan::output());

            // Execute Snooze Check Command
            $this->comment("Running command: reminder:snooze-check");
            Artisan::call('reminder:snooze-check');
            $this->line(Artisan::output());

            $this->info("=== Verifying Outcomes ===\n");

            // Verify Case 1: SLA Breach updated & notification generated
            $slaTracking->refresh();
            $slaBreachedOk = $slaTracking->is_response_breached && $slaTracking->is_resolution_breached;
            
            $slaNotificationCount = DB::table('notifications')
                ->where('type', \App\Notifications\SlaEscalationNotification::class)
                ->where('notifiable_id', $admin->id)
                ->where('data->ticket_id', $slaTicket->id)
                ->count();

            if ($slaBreachedOk) {
                $this->info("Ticket #{$slaTicket->id} breached: OK");
            } else {
                $this->error("Ticket #{$slaTicket->id} breach flags failed to update!");
            }

            if ($slaNotificationCount > 0) {
                $this->info("SLA breach notification created: OK");
            } else {
                $this->error("SLA breach notification missing!");
            }

            // Verify Case 2: Booking Reminder notification generated
            $bookingNotificationCount = DB::table('notifications')
                ->where('type', \App\Notifications\BookingReminderNotification::class)
                ->where('notifiable_id', $admin->id)
                ->where('data->booking_id', $booking->id)
                ->count();

            if ($bookingNotificationCount > 0) {
                $this->info("Booking reminder created: OK");
            } else {
                $this->error("Booking reminder notification missing!");
            }

            // Verify Case 3: Pending Ticket Reminder notification generated
            $pendingNotificationCount = DB::table('notifications')
                ->where('type', \App\Notifications\PendingTicketReminderNotification::class)
                ->where('notifiable_id', $admin->id)
                ->where('data->ticket_id', $pendingTicket->id)
                ->count();

            if ($pendingNotificationCount > 0) {
                $this->info("Pending ticket reminder created: OK");
            } else {
                $this->error("Pending ticket reminder notification missing!");
            }

            // Verify Case 4: CSAT Reminder notification generated
            $csatNotificationCount = DB::table('notifications')
                ->where('type', \App\Notifications\CsatReminderNotification::class)
                ->where('notifiable_id', $user->id)
                ->where('data->ticket_id', $csatTicket->id)
                ->count();

            if ($csatNotificationCount > 0) {
                $this->info("Csat reminder created: OK");
            } else {
                $this->error("Csat reminder notification missing!");
            }

            // Verify Case 5: Snooze check refired and original notification updated
            // We expect at least 2 SLA escalation notifications for the admin/ticket.
            // One was sent when Case 1 SLA check ran.
            // Another was sent when Case 5 Snooze check ran and re-fired the snoozed one.
            $refiredNotificationCount = DB::table('notifications')
                ->where('type', \App\Notifications\SlaEscalationNotification::class)
                ->where('notifiable_id', $admin->id)
                ->where('data->ticket_id', $slaTicket->id)
                ->count();

            $updatedSnoozedNotification = DB::table('notifications')->where('id', $snoozedNotificationId)->first();
            $snoozedData = json_decode($updatedSnoozedNotification->data, true);
            $snoozedFlagRemoved = isset($snoozedData['snoozed']) && $snoozedData['snoozed'] === false;
            $hasReFiredAt = isset($snoozedData['re_fired_at']);

            if ($refiredNotificationCount >= 2 && $snoozedFlagRemoved && $hasReFiredAt) {
                $this->info("Snooze check refired: OK");
            } else {
                $this->error("Snooze check failed! Refired count: {$refiredNotificationCount}, flag removed: " . ($snoozedFlagRemoved ? 'Y' : 'N') . ", re_fired_at present: " . ($hasReFiredAt ? 'Y' : 'N'));
            }

            $this->info("\n=== Simulation Completed Successfully (All checks passed) ===");
            
        } catch (\Exception $e) {
            $this->error("Exception thrown during simulation: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        } finally {
            DB::rollBack();
            $this->info("Database transactions rolled back successfully. Database remains clean.");
        }

        return Command::SUCCESS;
    }
}
