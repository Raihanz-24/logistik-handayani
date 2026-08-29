<?php

namespace Tests\Unit;

use App\Models\Catatan;
use PHPUnit\Framework\TestCase;

class CatatanFeatureTest extends TestCase
{
    public function test_catatan_menyediakan_jenis_daftar_belanja_dan_catatan_biasa(): void
    {
        $this->assertSame([
            Catatan::JENIS_BELANJA => 'Daftar Belanja',
            Catatan::JENIS_BIASA => 'Catatan Biasa',
        ], Catatan::jenisOptions());
    }

    public function test_migrasi_catatan_hanya_membuat_tabel_khusus_catatan(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_28_000000_create_catatan_tables.php',
        );

        $this->assertStringContainsString("Schema::create('catatans'", $migration);
        $this->assertStringContainsString("Schema::create('catatan_items'", $migration);
        $this->assertStringNotContainsString('Schema::table(', $migration);
        $this->assertStringNotContainsString("DB::table('mutasis'", $migration);
        $this->assertStringNotContainsString("DB::table('barang_lokasi'", $migration);
    }
}
