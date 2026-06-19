<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lokasis', function (Blueprint $table) {
            $table->string('jenis_lokasi', 30)
                ->default('gudang')
                ->after('nama_lokasi')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('lokasis', function (Blueprint $table) {
            $table->dropIndex(['jenis_lokasi']);
            $table->dropColumn('jenis_lokasi');
        });
    }
};
