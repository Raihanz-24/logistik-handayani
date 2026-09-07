<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengeluaran_belanja_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pengeluaran_belanja_id')
                ->constrained('pengeluaran_belanjas')
                ->cascadeOnDelete();
            $table->foreignId('barang_id')->nullable()->constrained('barangs')->nullOnDelete();
            $table->string('kode_barang_snapshot', 50);
            $table->string('nama_barang_snapshot');
            $table->string('satuan_snapshot', 50)->nullable();
            $table->decimal('jumlah', 15, 3);
            $table->unsignedBigInteger('harga_satuan');
            $table->unsignedBigInteger('subtotal');
            $table->string('keterangan', 500)->nullable();
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();

            $table->unique(['pengeluaran_belanja_id', 'barang_id'], 'belanja_item_barang_unique');
            $table->index(['barang_id', 'created_at'], 'belanja_item_barang_date_index');
        });

        Schema::create('pengeluaran_belanja_notas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pengeluaran_belanja_id')
                ->constrained('pengeluaran_belanjas')
                ->cascadeOnDelete();
            $table->string('path')->unique();
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();

            $table->index(['pengeluaran_belanja_id', 'urutan'], 'belanja_nota_order_index');
        });

        Schema::create('harga_barang_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barang_id')->constrained('barangs')->cascadeOnDelete();
            $table->unsignedBigInteger('harga_terakhir');
            $table->date('tanggal_harga_terakhir');
            $table->timestamps();

            $table->unique(['supplier_id', 'barang_id'], 'harga_supplier_barang_unique');
        });

        Schema::create('foto_barang_session_pengeluaran_belanja', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('foto_barang_session_id');
            $table->unsignedBigInteger('pengeluaran_belanja_id');
            $table->timestamps();

            $table->foreign('foto_barang_session_id', 'fbspb_session_fk')
                ->references('id')
                ->on('foto_barang_sessions')
                ->cascadeOnDelete();
            $table->foreign('pengeluaran_belanja_id', 'fbspb_expense_fk')
                ->references('id')
                ->on('pengeluaran_belanjas')
                ->cascadeOnDelete();
            $table->unique(
                ['foto_barang_session_id', 'pengeluaran_belanja_id'],
                'foto_session_pengeluaran_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foto_barang_session_pengeluaran_belanja');
        Schema::dropIfExists('harga_barang_suppliers');
        Schema::dropIfExists('pengeluaran_belanja_notas');
        Schema::dropIfExists('pengeluaran_belanja_items');
    }
};
