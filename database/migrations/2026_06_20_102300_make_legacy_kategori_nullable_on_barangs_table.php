<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('barangs') || ! Schema::hasColumn('barangs', 'kategori')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `barangs` MODIFY `kategori` VARCHAR(255) NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('barangs') || ! Schema::hasColumn('barangs', 'kategori')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE `barangs` SET `kategori` = '' WHERE `kategori` IS NULL");
            DB::statement('ALTER TABLE `barangs` MODIFY `kategori` VARCHAR(255) NOT NULL');
        }
    }
};
