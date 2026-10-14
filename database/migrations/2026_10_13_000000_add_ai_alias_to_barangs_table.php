<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `ai_alias` untuk nama panggilan/sinonim barang yang dipakai Asisten AI.
 *
 * Additive & aman: menambah SATU kolom nullable pada tabel `barangs`, tidak
 * mengubah/menghapus kolom existing. Kosong = perilaku lama (cari lewat nama/kode).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('barangs') || Schema::hasColumn('barangs', 'ai_alias')) {
            return;
        }

        Schema::table('barangs', function (Blueprint $table): void {
            // Contoh isi: "aqua, air galon, galon isi" (dipisah koma).
            $table->string('ai_alias', 255)->nullable()->after('satuan');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('barangs') || ! Schema::hasColumn('barangs', 'ai_alias')) {
            return;
        }

        Schema::table('barangs', function (Blueprint $table): void {
            $table->dropColumn('ai_alias');
        });
    }
};
