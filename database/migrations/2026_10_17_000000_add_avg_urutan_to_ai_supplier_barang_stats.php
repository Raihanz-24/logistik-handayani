<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambah kolom rata-rata posisi urutan item ke agregat afinitas supplier.
 *
 * Tujuan: mempelajari POLA URUTAN per supplier (mis. ayam utuh selalu jadi
 * item pertama saat order ke supplier tertentu). Nilai murni turunan dari
 * pengeluaran_belanja_items.urutan, jadi aman dihapus/dibangun ulang.
 *
 * Additive & aman: bila kolom sudah ada, tidak ada perubahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_supplier_barang_stats') || Schema::hasColumn('ai_supplier_barang_stats', 'avg_urutan')) {
            return;
        }

        Schema::table('ai_supplier_barang_stats', function (Blueprint $table): void {
            // Rata-rata posisi (0 = paling awal) item ini pada transaksi supplier.
            $table->decimal('avg_urutan', 6, 2)->nullable()->after('total_jumlah');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ai_supplier_barang_stats') || ! Schema::hasColumn('ai_supplier_barang_stats', 'avg_urutan')) {
            return;
        }

        Schema::table('ai_supplier_barang_stats', function (Blueprint $table): void {
            $table->dropColumn('avg_urutan');
        });
    }
};
