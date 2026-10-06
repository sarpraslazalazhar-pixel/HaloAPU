<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
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
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['kondisi_kembali', 'catatan_kembali']);
        });
    }
};
