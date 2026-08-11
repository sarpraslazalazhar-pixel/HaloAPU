<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_browser')->default(true)->after('jabatan_id');
            $table->boolean('notify_inapp')->default(true)->after('notify_browser');
            $table->boolean('notify_sound')->default(true)->after('notify_inapp');
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->boolean('notify_browser')->default(true)->after('avatar_path');
            $table->boolean('notify_inapp')->default(true)->after('notify_browser');
            $table->boolean('notify_sound')->default(true)->after('notify_inapp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['notify_browser', 'notify_inapp', 'notify_sound']);
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['notify_browser', 'notify_inapp', 'notify_sound']);
        });
    }
};
