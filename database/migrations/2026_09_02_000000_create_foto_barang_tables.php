<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foto_barang_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('judul');
            $table->string('nama_lokasi');
            $table->text('alamat');
            $table->string('status', 20)->default('aktif')->index();
            $table->timestamp('dimulai_at')->index();
            $table->timestamp('selesai_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('foto_barang_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('foto_barang_session_id')
                ->constrained('foto_barang_sessions')
                ->cascadeOnDelete();
            $table->unsignedInteger('urutan');
            $table->string('path')->unique();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('akurasi_meter')->nullable();
            $table->timestamp('diambil_at')->index();
            $table->unsignedBigInteger('ukuran_asli')->default(0);
            $table->unsignedBigInteger('ukuran_hasil')->default(0);
            $table->unsignedInteger('lebar')->default(0);
            $table->unsignedInteger('tinggi')->default(0);
            $table->timestamps();

            $table->unique(['foto_barang_session_id', 'urutan'], 'foto_barang_session_urutan_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foto_barang_items');
        Schema::dropIfExists('foto_barang_sessions');
    }
};
