<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ringkasan aksi pending kini bisa berisi BANYAK baris (mis. draft batch mutasi
 * berisi 20 item). Kolom `summary` semula varchar(255) sehingga terpotong.
 * Migrasi ini melonggarkannya menjadi TEXT.
 *
 * Additive & aman: hanya memperlebar tipe kolom, tidak menghapus data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_pending_actions')) {
            return;
        }

        Schema::table('ai_pending_actions', function (Blueprint $table): void {
            $table->text('summary')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sengaja tidak dikembalikan ke varchar agar tidak memotong data.
    }
};
