<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            
            $table->string('type')->default('user_support')->after('id');
            $table->foreignId('admin_one_id')->nullable()->after('user_id')->constrained('admins')->onDelete('cascade');
            $table->foreignId('admin_two_id')->nullable()->after('admin_one_id')->constrained('admins')->onDelete('cascade');
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['admin_two_id']);
            $table->dropForeign(['admin_one_id']);
            $table->dropColumn(['type', 'admin_one_id', 'admin_two_id']);
        });
    }
};
