<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log pencarian barang oleh Asisten AI.
 *
 * Dipakai halaman "Kecerdasan AI" untuk MENILAI seberapa pintar AI mengenali
 * barang: berapa banyak pencarian yang langsung dapat pilihan terbaik vs
 * ambigu. Additive & aman; bila tabel sudah ada tidak ada perubahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_search_logs')) {
            return;
        }

        Schema::create('ai_search_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('kata_kunci')->nullable();
            $table->unsignedInteger('jumlah_kandidat')->default(0);
            $table->unsignedBigInteger('pilihan_terbaik_barang_id')->nullable();
            $table->unsignedInteger('skor_teratas')->default(0);
            $table->boolean('ambigu')->default(false);
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['pilihan_terbaik_barang_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_search_logs');
    }
};
