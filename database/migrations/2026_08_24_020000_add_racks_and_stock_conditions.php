<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
        });

        $highestBarangNumber = DB::table('barangs')
            ->pluck('kode_barang')
            ->map(function (?string $code): int {
                preg_match('/^BRG-(\d+)$/i', (string) $code, $matches);

                return (int) ($matches[1] ?? 0);
            })
            ->max() ?? 0;

        DB::table('number_sequences')->insert([
            'name' => 'barang',
            'current_value' => $highestBarangNumber,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('lokasis', function (Blueprint $table): void {
            $table->boolean('menggunakan_rak')->default(false)->after('jenis_lokasi');
            $table->json('konfigurasi_rak')->nullable()->after('menggunakan_rak');
        });

        Schema::create('rak_gudangs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lokasi_id')->constrained('lokasis')->cascadeOnDelete();
            $table->unsignedInteger('nomor_rak');
            $table->unsignedInteger('jumlah_tingkat');
            $table->boolean('aktif')->default(true)->index();
            $table->timestamps();
            $table->unique(['lokasi_id', 'nomor_rak']);
        });

        Schema::create('posisi_raks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rak_gudang_id')->constrained('rak_gudangs')->cascadeOnDelete();
            $table->foreignId('lokasi_id')->constrained('lokasis')->cascadeOnDelete();
            $table->unsignedInteger('nomor_tingkat');
            $table->string('kode', 30);
            $table->boolean('aktif')->default(true)->index();
            $table->timestamps();
            $table->unique(['rak_gudang_id', 'nomor_tingkat']);
            $table->unique(['lokasi_id', 'kode']);
        });

        Schema::table('barang_lokasi', function (Blueprint $table): void {
            $table->foreignId('posisi_rak_id')
                ->nullable()
                ->after('lokasi_id')
                ->constrained('posisi_raks')
                ->nullOnDelete();
            $table->unsignedInteger('stok_baik')->default(0)->after('stok');
            $table->unsignedInteger('stok_rusak')->default(0)->after('stok_baik');
            $table->unsignedInteger('stok_hilang')->default(0)->after('stok_rusak');
        });

        DB::table('barang_lokasi')->update([
            'stok_baik' => DB::raw('GREATEST(stok, 0)'),
            'stok_rusak' => 0,
            'stok_hilang' => 0,
        ]);

        DB::statement("ALTER TABLE `mutasis` MODIFY `jenis_mutasi` ENUM('masuk', 'keluar', 'perubahan_kondisi') NOT NULL");

        Schema::table('mutasis', function (Blueprint $table): void {
            $table->string('kondisi_asal', 20)->nullable()->after('jenis_mutasi');
            $table->string('kondisi_tujuan', 20)->default('baik')->after('kondisi_asal');
            $table->foreignId('posisi_rak_asal_id')
                ->nullable()
                ->after('lokasi_tujuan_id')
                ->constrained('posisi_raks')
                ->nullOnDelete();
            $table->foreignId('posisi_rak_tujuan_id')
                ->nullable()
                ->after('posisi_rak_asal_id')
                ->constrained('posisi_raks')
                ->nullOnDelete();
            $table->unsignedInteger('stok_kondisi_asal_awal')->nullable()->after('stok_akhir');
            $table->unsignedInteger('stok_kondisi_asal_akhir')->nullable()->after('stok_kondisi_asal_awal');
            $table->unsignedInteger('stok_kondisi_tujuan_awal')->nullable()->after('stok_kondisi_asal_akhir');
            $table->unsignedInteger('stok_kondisi_tujuan_akhir')->nullable()->after('stok_kondisi_tujuan_awal');
        });

        DB::table('mutasis')->where('jenis_mutasi', 'keluar')->update([
            'kondisi_asal' => 'baik',
            'kondisi_tujuan' => 'baik',
        ]);

        DB::table('mutasis')->where('jenis_mutasi', 'masuk')->update([
            'kondisi_asal' => null,
            'kondisi_tujuan' => 'baik',
        ]);
    }

    public function down(): void
    {
        DB::table('mutasis')->where('jenis_mutasi', 'perubahan_kondisi')->delete();

        Schema::table('mutasis', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('posisi_rak_asal_id');
            $table->dropConstrainedForeignId('posisi_rak_tujuan_id');
            $table->dropColumn([
                'kondisi_asal',
                'kondisi_tujuan',
                'stok_kondisi_asal_awal',
                'stok_kondisi_asal_akhir',
                'stok_kondisi_tujuan_awal',
                'stok_kondisi_tujuan_akhir',
            ]);
        });

        DB::statement("ALTER TABLE `mutasis` MODIFY `jenis_mutasi` ENUM('masuk', 'keluar') NOT NULL");

        Schema::table('barang_lokasi', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('posisi_rak_id');
            $table->dropColumn(['stok_baik', 'stok_rusak', 'stok_hilang']);
        });

        Schema::dropIfExists('posisi_raks');
        Schema::dropIfExists('rak_gudangs');

        Schema::table('lokasis', function (Blueprint $table): void {
            $table->dropColumn(['menggunakan_rak', 'konfigurasi_rak']);
        });

        Schema::dropIfExists('number_sequences');
    }
};
