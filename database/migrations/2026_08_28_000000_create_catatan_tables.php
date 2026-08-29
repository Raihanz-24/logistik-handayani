<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catatans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('jenis', 20)->index();
            $table->date('tanggal')->index();
            $table->string('judul');
            $table->text('isi')->nullable();
            $table->string('nama_supplier_snapshot')->nullable();
            $table->boolean('selesai')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('catatan_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('catatan_id')->constrained('catatans')->cascadeOnDelete();
            $table->foreignId('barang_id')->nullable()->constrained('barangs')->nullOnDelete();
            $table->string('nama_barang_snapshot');
            $table->string('satuan_snapshot', 50)->nullable();
            $table->unsignedInteger('jumlah')->default(1);
            $table->string('keterangan')->nullable();
            $table->boolean('sudah_dibeli')->default(false)->index();
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();

            $table->unique(['catatan_id', 'barang_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catatan_items');
        Schema::dropIfExists('catatans');
    }
};
