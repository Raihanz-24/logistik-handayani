<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('produks', function (Blueprint $table) {
            $table->text('deskripsi')->nullable()->after('satuan');
            $table->integer('harga_beli')->default(0)->after('deskripsi');
            $table->integer('harga_jual')->default(0)->after('harga_beli');
            $table->string('barcode')->nullable()->after('harga_jual');
            $table->string('gambar')->nullable()->after('barcode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('produks', function (Blueprint $table) {
            $table->dropColumn(['deskripsi', 'harga_beli', 'harga_jual', 'barcode', 'gambar']);
        });
    }
};
