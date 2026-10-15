<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memori pribadi Asisten AI per pengguna: nama panggilan/sinonim barang yang
 * "dipelajari" dari pola chat pengguna (mis. user menyebut "wortel" lalu memilih
 * barang tertentu). Dipakai agar Asisten makin mengerti ucapan pengguna.
 *
 * Additive & aman: hanya MENAMBAH tabel baru (prefix ai_), tidak mengubah tabel
 * existing. Bila tabel kosong, perilaku lama tetap berjalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_user_aliases')) {
            return;
        }

        Schema::create('ai_user_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('barang_id')->nullable()->constrained('barangs')->nullOnDelete();
            // Frasa yang diucapkan pengguna (dinormalkan huruf kecil, tanpa tanda baca).
            $table->string('keyword', 120);
            $table->string('barang_nama', 255)->nullable();
            $table->unsignedInteger('hits')->default(1);
            $table->timestamps();

            $table->unique(['user_id', 'keyword']);
            $table->index(['user_id', 'hits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_user_aliases');
    }
};
