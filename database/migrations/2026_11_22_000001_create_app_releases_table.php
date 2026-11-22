<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20)->default('android');
            $table->string('channel', 20)->default('production');
            $table->string('version_name', 50);
            $table->unsignedInteger('build_number');
            $table->unsignedInteger('min_supported_build_number')->default(0);
            $table->boolean('force_update')->default(false);
            $table->string('title', 150)->nullable();
            $table->text('release_notes')->nullable();
            $table->string('download_url', 1000);
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->unsignedInteger('last_notified_count')->default(0);
            $table->string('last_notified_target', 20)->nullable();
            $table->foreignId('created_by_admin_id')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['platform', 'channel', 'is_active'], 'app_releases_lookup_idx');
            $table->unique(['platform', 'channel', 'build_number'], 'app_releases_unique_build');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
