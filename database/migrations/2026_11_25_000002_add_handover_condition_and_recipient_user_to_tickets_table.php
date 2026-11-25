<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'penerima_alat_user_id')) {
                $table->foreignId('penerima_alat_user_id')
                    ->nullable()
                    ->after('diserahkan_oleh_admin_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('tickets', 'kondisi_serah')) {
                $table->string('kondisi_serah', 30)
                    ->nullable()
                    ->after('penerima_alat');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'penerima_alat_user_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('penerima_alat_user_id');
            });
        }

        if (Schema::hasColumn('tickets', 'kondisi_serah')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropColumn('kondisi_serah');
            });
        }
    }
};
