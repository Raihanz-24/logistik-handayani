<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('nama_supplier')->unique();
            $table->string('kontak_person')->nullable();
            $table->string('telepon', 50)->nullable();
            $table->text('alamat')->nullable();
            $table->boolean('aktif')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('mutasis', function (Blueprint $table): void {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('barang_id')
                ->constrained('suppliers')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mutasis', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplier_id');
        });

        Schema::dropIfExists('suppliers');
    }
};
