<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mutasi_raks', function (Blueprint $table): void {
            $table->id();
            $table->date('tanggal');
            $table->foreignId('barang_id')->constrained('barangs')->restrictOnDelete();
            $table->foreignId('lokasi_id')->constrained('lokasis')->restrictOnDelete();
            $table->foreignId('posisi_rak_asal_id')->constrained('posisi_raks')->restrictOnDelete();
            $table->foreignId('posisi_rak_tujuan_id')->constrained('posisi_raks')->restrictOnDelete();
            $table->unsignedInteger('stok');
            $table->unsignedInteger('stok_baik')->default(0);
            $table->unsignedInteger('stok_rusak')->default(0);
            $table->unsignedInteger('stok_hilang')->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('no_ref', 100)->nullable();
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['barang_id', 'lokasi_id', 'status']);
            $table->index(['tanggal', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mutasi_raks');
    }
};
