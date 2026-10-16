<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agregat afinitas supplier <-> barang, dibangun dari histori transaksi belanja.
 *
 * Tujuan: Asisten AI bisa "tahu" barang apa yang biasanya diorder dari tiap
 * supplier (mis. Bu Elly / Bu Hen) tanpa menghitung ulang seluruh riwayat tiap
 * kali chat. Agregat ini murni TURUNAN (derived) dari data transaksi yang sudah
 * ada, jadi aman dihapus/dibangun ulang kapan saja.
 *
 * Additive & aman: hanya MENAMBAH tabel baru (prefix ai_), tidak mengubah tabel
 * existing. Bila tabel kosong, perilaku lama tetap berjalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_supplier_barang_stats')) {
            return;
        }

        Schema::create('ai_supplier_barang_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('barang_id')->constrained('barangs')->cascadeOnDelete();

            $table->unsignedInteger('frekuensi')->default(0);
            $table->decimal('total_jumlah', 14, 3)->default(0);
            $table->string('satuan_terakhir', 50)->nullable();
            $table->integer('harga_terakhir')->nullable();
            $table->date('tanggal_terakhir')->nullable();

            $table->timestamps();

            $table->unique(['supplier_id', 'barang_id'], 'ai_sbs_supplier_barang_unique');
            $table->index(['supplier_id', 'frekuensi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_supplier_barang_stats');
    }
};
