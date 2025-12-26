<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mutasis', function (Blueprint $table) {

            // 1) Tujuan mutasi (khusus mutasi keluar)
            if (! Schema::hasColumn('mutasis', 'lokasi_tujuan_id')) {
                $table->foreignId('lokasi_tujuan_id')
                    ->nullable()
                    ->after('lokasi_id')
                    ->constrained('lokasis')
                    ->nullOnDelete();
            }

            // 2) Approval info
            if (! Schema::hasColumn('mutasis', 'approved_by')) {
                $table->foreignId('approved_by')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('mutasis', 'approved_at')) {
                $table->timestamp('approved_at')
                    ->nullable()
                    ->after('approved_by');
            }

            // 3) Cancel info
            if (! Schema::hasColumn('mutasis', 'cancelled_by')) {
                $table->foreignId('cancelled_by')
                    ->nullable()
                    ->after('approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('mutasis', 'cancelled_at')) {
                $table->timestamp('cancelled_at')
                    ->nullable()
                    ->after('cancelled_by');
            }

            if (! Schema::hasColumn('mutasis', 'cancel_reason')) {
                $table->string('cancel_reason', 255)
                    ->nullable()
                    ->after('cancelled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mutasis', function (Blueprint $table) {

            // drop FK dulu baru drop kolom (urutannya penting)
            if (Schema::hasColumn('mutasis', 'lokasi_tujuan_id')) {
                $table->dropConstrainedForeignId('lokasi_tujuan_id');
            }

            if (Schema::hasColumn('mutasis', 'approved_by')) {
                $table->dropConstrainedForeignId('approved_by');
            }
            if (Schema::hasColumn('mutasis', 'approved_at')) {
                $table->dropColumn('approved_at');
            }

            if (Schema::hasColumn('mutasis', 'cancelled_by')) {
                $table->dropConstrainedForeignId('cancelled_by');
            }
            if (Schema::hasColumn('mutasis', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
            if (Schema::hasColumn('mutasis', 'cancel_reason')) {
                $table->dropColumn('cancel_reason');
            }
        });
    }
};
