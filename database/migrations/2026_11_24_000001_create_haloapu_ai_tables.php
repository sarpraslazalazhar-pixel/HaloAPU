<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('title', 160);
            $table->json('scope_filters')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'last_message_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('ai_conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->longText('content');
            $table->boolean('is_fallback')->default(false);
            $table->string('model_name', 80)->nullable();
            $table->json('tools_used')->nullable();
            $table->json('charts')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['ai_conversation_id', 'created_at']);
        });

        Schema::create('ai_briefings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('period_type', 20);
            $table->date('briefing_date');
            $table->string('scope_label', 160);
            $table->string('title', 200);
            $table->longText('summary_markdown');
            $table->json('metrics_snapshot');
            $table->boolean('is_fallback')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['admin_id', 'period_type', 'briefing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_briefings');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
