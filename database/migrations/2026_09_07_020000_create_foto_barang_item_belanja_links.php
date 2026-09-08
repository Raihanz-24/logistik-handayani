<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foto_barang_item_belanja_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('foto_barang_item_id');
            $table->unsignedBigInteger('pengeluaran_belanja_item_id');
            $table->timestamps();

            $table->foreign('foto_barang_item_id', 'fbibl_photo_fk')
                ->references('id')
                ->on('foto_barang_items')
                ->cascadeOnDelete();
            $table->foreign('pengeluaran_belanja_item_id', 'fbibl_purchase_item_fk')
                ->references('id')
                ->on('pengeluaran_belanja_items')
                ->cascadeOnDelete();
            $table->unique('foto_barang_item_id', 'photo_belanja_link_unique');
            $table->index('pengeluaran_belanja_item_id', 'belanja_link_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foto_barang_item_belanja_links');
    }
};
