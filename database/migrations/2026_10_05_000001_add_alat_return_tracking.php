<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_units', function (Blueprint $table) {
            if (!Schema::hasColumn('sub_units', 'wajib_kembali')) {
                $table->boolean('wajib_kembali')->default(false)->after('is_revision_enabled');
            }
        });

        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'dikembalikan_at')) {
                $table->timestamp('dikembalikan_at')->nullable()->after('is_result_accepted');
            }
            if (!Schema::hasColumn('tickets', 'kondisi_kembali')) {
                $table->string('kondisi_kembali', 50)->nullable()->after('dikembalikan_at');
            }
            if (!Schema::hasColumn('tickets', 'catatan_kembali')) {
                $table->text('catatan_kembali')->nullable()->after('kondisi_kembali');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sub_units', function (Blueprint $table) {
            $table->dropColumn('wajib_kembali');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['dikembalikan_at', 'kondisi_kembali', 'catatan_kembali']);
        });
    }
};
