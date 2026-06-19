<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('produks', 'gambar')) {
            DB::table('produks')
                ->whereNotNull('gambar')
                ->where('gambar', '!=', '')
                ->pluck('gambar')
                ->each(fn (string $path) => Storage::disk('public')->delete($path));
        }

        $columns = collect(['harga_beli', 'harga_jual', 'barcode', 'gambar'])
            ->filter(fn (string $column): bool => Schema::hasColumn('produks', $column))
            ->all();

        if ($columns === []) {
            return;
        }

        Schema::table('produks', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        Schema::table('produks', function (Blueprint $table): void {
            if (! Schema::hasColumn('produks', 'harga_beli')) {
                $table->integer('harga_beli')->default(0)->after('deskripsi');
            }

            if (! Schema::hasColumn('produks', 'harga_jual')) {
                $table->integer('harga_jual')->default(0)->after('harga_beli');
            }

            if (! Schema::hasColumn('produks', 'barcode')) {
                $table->string('barcode')->nullable()->after('harga_jual');
            }

            if (! Schema::hasColumn('produks', 'gambar')) {
                $table->string('gambar')->nullable()->after('barcode');
            }
        });
    }
};
