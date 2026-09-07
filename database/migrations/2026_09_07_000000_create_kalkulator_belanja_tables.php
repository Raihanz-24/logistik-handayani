<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kalkulator_belanjas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('tanggal')->index();
            $table->string('judul');
            $table->unsignedBigInteger('uang_awal');
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tanggal']);
        });

        Schema::create('pengeluaran_belanjas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kalkulator_belanja_id')
                ->constrained('kalkulator_belanjas')
                ->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('nama_supplier_snapshot');
            $table->unsignedBigInteger('nominal');
            $table->string('foto_nota')->nullable();
            $table->string('keterangan')->nullable();
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();

            $table->index(['kalkulator_belanja_id', 'urutan']);
            $table->index(['supplier_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengeluaran_belanjas');
        Schema::dropIfExists('kalkulator_belanjas');
    }
};
