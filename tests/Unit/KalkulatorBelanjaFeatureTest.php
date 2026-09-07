<?php

namespace Tests\Unit;

use App\Filament\Resources\KalkulatorBelanjaResource;
use PHPUnit\Framework\TestCase;

class KalkulatorBelanjaFeatureTest extends TestCase
{
    public function test_perhitungan_form_menjumlahkan_semua_toko_dengan_tepat(): void
    {
        $total = KalkulatorBelanjaResource::formTotal([
            ['nominal' => 2_000_000],
            ['nominal' => '1000000'],
            ['nominal' => null],
        ]);

        $this->assertSame(3_000_000, $total);
        $this->assertSame('Rp3.000.000', KalkulatorBelanjaResource::rupiah($total));
        $this->assertSame('-Rp500.000', KalkulatorBelanjaResource::rupiah(-500_000));
    }

    public function test_migrasi_hanya_menambah_tabel_kalkulator_yang_terisolasi(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_09_07_000000_create_kalkulator_belanja_tables.php',
        );

        $this->assertStringContainsString("Schema::create('kalkulator_belanjas'", $migration);
        $this->assertStringContainsString("Schema::create('pengeluaran_belanjas'", $migration);
        $this->assertStringNotContainsString('Schema::table(', $migration);
        $this->assertStringNotContainsString("DB::table('mutasis'", $migration);
        $this->assertStringNotContainsString("DB::table('barang_lokasi'", $migration);
    }

    public function test_resource_terhubung_supplier_dan_mendukung_foto_nota(): void
    {
        $resource = (string) file_get_contents(
            dirname(__DIR__, 2).'/app/Filament/Resources/KalkulatorBelanjaResource.php',
        );

        $this->assertStringContainsString("Repeater::make('pengeluaran')", $resource);
        $this->assertStringContainsString("Select::make('supplier_id')", $resource);
        $this->assertStringContainsString("FileUpload::make('foto_nota')", $resource);
        $this->assertStringContainsString('NotaBelanjaImageService::class', $resource);
        $this->assertStringContainsString("'view' => Pages\\ViewKalkulatorBelanja::route('/{record}')", $resource);
        $this->assertStringContainsString("Filter::make('periode')", $resource);
    }
}
