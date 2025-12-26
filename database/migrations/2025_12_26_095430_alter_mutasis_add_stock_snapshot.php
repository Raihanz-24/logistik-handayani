<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mutasis', function (Blueprint $table) {
            if (! Schema::hasColumn('mutasis', 'stok_awal')) {
                $table->integer('stok_awal')->nullable()->after('jumlah');
            }
            if (! Schema::hasColumn('mutasis', 'stok_akhir')) {
                $table->integer('stok_akhir')->nullable()->after('stok_awal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mutasis', function (Blueprint $table) {
            if (Schema::hasColumn('mutasis', 'stok_awal')) {
                $table->dropColumn('stok_awal');
            }
            if (Schema::hasColumn('mutasis', 'stok_akhir')) {
                $table->dropColumn('stok_akhir');
            }
        });
    }
};
