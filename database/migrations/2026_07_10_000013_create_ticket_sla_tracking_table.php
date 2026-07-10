<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sla_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sla_response_deadline')->nullable();
            $table->timestamp('sla_resolution_deadline')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('total_paused_minutes')->default(0);
            $table->unsignedTinyInteger('current_tier')->default(0);
            $table->boolean('is_response_breached')->default(false);
            $table->boolean('is_resolution_breached')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_tracking');
    }
};
