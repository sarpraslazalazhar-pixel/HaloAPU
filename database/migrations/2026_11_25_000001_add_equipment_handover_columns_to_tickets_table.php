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
            if (! Schema::hasColumn('tickets', 'diserahkan_at')) {
                $table->timestamp('diserahkan_at')->nullable()->after('is_result_accepted');
            }
            if (! Schema::hasColumn('tickets', 'diserahkan_oleh_admin_id')) {
                $table->foreignId('diserahkan_oleh_admin_id')
                    ->nullable()
                    ->after('diserahkan_at')
                    ->constrained('admins')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('tickets', 'penerima_alat')) {
                $table->string('penerima_alat', 150)->nullable()->after('diserahkan_oleh_admin_id');
            }
            if (! Schema::hasColumn('tickets', 'catatan_serah')) {
                $table->text('catatan_serah')->nullable()->after('penerima_alat');
            }
            if (! Schema::hasColumn('tickets', 'diterima_kembali_oleh_admin_id')) {
                $table->foreignId('diterima_kembali_oleh_admin_id')
                    ->nullable()
                    ->after('dikembalikan_at')
                    ->constrained('admins')
                    ->nullOnDelete();
            }
        });

        if (Schema::hasTable('sub_units') && Schema::hasColumn('sub_units', 'wajib_kembali')) {
            DB::table('sub_units')
                ->whereRaw('LOWER(nama_layanan) LIKE ?', ['%peminjaman alat%'])
                ->update(['wajib_kembali' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'diserahkan_oleh_admin_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('diserahkan_oleh_admin_id');
            });
        }

        if (Schema::hasColumn('tickets', 'diterima_kembali_oleh_admin_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('diterima_kembali_oleh_admin_id');
            });
        }

        $dropColumns = array_values(array_filter(
            ['diserahkan_at', 'penerima_alat', 'catatan_serah'],
            fn (string $column): bool => Schema::hasColumn('tickets', $column)
        ));

        if ($dropColumns !== []) {
            Schema::table('tickets', function (Blueprint $table) use ($dropColumns) {
                $table->dropColumn($dropColumns);
            });
        }
    }
};
