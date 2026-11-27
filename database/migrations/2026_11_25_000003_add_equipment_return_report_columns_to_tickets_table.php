<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'pengembalian_diajukan_at')) {
                $table->timestamp('pengembalian_diajukan_at')
                    ->nullable()
                    ->after('catatan_serah');
            }

            if (! Schema::hasColumn('tickets', 'catatan_pengajuan_kembali')) {
                $table->text('catatan_pengajuan_kembali')
                    ->nullable()
                    ->after('pengembalian_diajukan_at');
            }
        });
    }

    public function down(): void
    {
        $dropColumns = array_values(array_filter(
            ['pengembalian_diajukan_at', 'catatan_pengajuan_kembali'],
            fn (string $column): bool => Schema::hasColumn('tickets', $column)
        ));

        if ($dropColumns !== []) {
            Schema::table('tickets', function (Blueprint $table) use ($dropColumns) {
                $table->dropColumn($dropColumns);
            });
        }
    }
};
