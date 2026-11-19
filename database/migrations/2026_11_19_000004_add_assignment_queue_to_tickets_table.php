<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dateTime('assignment_queued_at')->nullable();
            $table->string('assignment_resume_status', 20)->nullable();
            $table->index(['assigned_admin_id', 'status', 'assignment_queued_at'], 'tickets_assignment_queue_idx');
        });

        $at = now()->toDateTimeString();
        $active = ['open', 'on_proses', 'need_revision'];
        foreach (['open' => ['diajukan', 'baru'], 'on_proses' => ['in_progress', 'diproses']] as $stage => $aliases) {
            DB::table('tickets')->whereNotNull('assigned_admin_id')->whereIn('status', $aliases)->update(['status' => $stage]);
        }

        DB::table('tickets')->select('assigned_admin_id')->distinct()
            ->whereNotNull('assigned_admin_id')->whereIn('status', $active)
            ->chunkById(100, function ($operators) use ($at, $active) {
                foreach ($operators as $operator) {
                    DB::transaction(function () use ($operator, $at, $active) {
                        $tickets = DB::table('tickets')->where('assigned_admin_id', $operator->assigned_admin_id)->whereIn('status', $active);
                        $keep = (clone $tickets)->orderByRaw("CASE WHEN status IN ('on_proses', 'need_revision') THEN 0 ELSE 1 END")
                            ->orderBy('created_at')->orderBy('id')->value('id');

                        $tickets->where('id', '<>', $keep)->select('id')->chunkById(100, function ($rows) use ($at) {
                            $ids = $rows->pluck('id');
                            DB::table('tickets')->whereIn('id', $ids)->update([
                                'assignment_resume_status' => DB::raw('status'),
                                'status' => 'pending',
                                'assignment_queued_at' => $at,
                            ]);
                            DB::table('ticket_sla_tracking')->whereIn('ticket_id', $ids)->whereNull('paused_at')
                                ->where(fn ($q) => $q->whereNull('responded_at')->orWhereNull('resolved_at'))
                                ->update(['paused_at' => $at]);
                        });
                    });
                }
            }, 'assigned_admin_id');
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_assignment_queue_idx');
            $table->dropColumn(['assignment_queued_at', 'assignment_resume_status']);
        });
    }
};
