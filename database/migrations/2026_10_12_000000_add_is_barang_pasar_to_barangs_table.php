<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menandai barang "pasar" (hanya dicatat di transaksi belanja, bukan stok gudang/SAW).
 *
 * Operasi ini ADDITIVE & NON-DESTRUKTIF:
 * - Hanya menambah satu kolom boolean baru.
 * - Baris yang sudah ada otomatis bernilai default (false = bukan barang pasar),
 *   sehingga perilaku data lama tidak berubah sampai ditoggle manual.
 * - down() hanya membuang kolom ini, tidak menyentuh kolom/tabel lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('barangs') || Schema::hasColumn('barangs', 'is_barang_pasar')) {
            return;
        }

        Schema::table('barangs', function (Blueprint $table): void {
            $table->boolean('is_barang_pasar')->default(false)->index()->after('satuan');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('barangs') || ! Schema::hasColumn('barangs', 'is_barang_pasar')) {
            return;
        }

        Schema::table('barangs', function (Blueprint $table): void {
            $table->dropIndex(['is_barang_pasar']);
            $table->dropColumn('is_barang_pasar');
        });
    }
};
