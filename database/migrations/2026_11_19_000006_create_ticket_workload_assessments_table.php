<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_workload_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('assessed_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->unsignedTinyInteger('score');
            $table->unsignedSmallInteger('request_count');
            $table->decimal('effort_hours', 6, 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_workload_assessments');
    }
};
