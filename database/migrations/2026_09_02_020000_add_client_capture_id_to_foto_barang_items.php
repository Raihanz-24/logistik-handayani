<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foto_barang_items', function (Blueprint $table): void {
            $table->string('client_capture_id', 100)
                ->nullable()
                ->after('foto_barang_session_id');
            $table->unique(
                ['foto_barang_session_id', 'client_capture_id'],
                'foto_barang_session_client_capture_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('foto_barang_items', function (Blueprint $table): void {
            $table->dropUnique('foto_barang_session_client_capture_unique');
            $table->dropColumn('client_capture_id');
        });
    }
};
