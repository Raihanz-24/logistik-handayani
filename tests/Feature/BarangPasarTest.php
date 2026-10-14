<?php

namespace Tests\Feature;

use App\Models\Barang;
use App\Models\BarangLokasi;
use App\Models\KategoriBarang;
use App\Models\Lokasi;
use App\Models\Supplier;
use App\Filament\Resources\BarangLokasiResource;
use App\Filament\Widgets\BarangAlert;
use App\Services\BelanjaTransactionService;
use App\Services\HistoricalStockService;
use App\Services\MutasiDataService;
use App\Services\SawRestockRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Aturan "barang pasar": ditandai toggle di master barang (default OFF).
 * Barang pasar hanya dicatat di transaksi belanja — tidak masuk daftar stok,
 * tidak dihitung SAW, dan tidak muncul sebagai stok hampir habis.
 */
class BarangPasarTest extends TestCase
{
    use RefreshDatabase;

    private function gudang(): Lokasi
    {
        return Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);
    }

    public function test_kolom_toggle_ada_dan_default_off(): void
    {
        $this->assertTrue(Schema::hasColumn('barangs', 'is_barang_pasar'));

        $barang = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);

        $this->assertFalse((bool) $barang->fresh()->is_barang_pasar);
        $this->assertFalse($barang->fresh()->isBarangPasar());
    }

    public function test_toggle_on_menandai_barang_pasar(): void
    {
        $barang = Barang::query()->create([
            'nama_barang' => 'Beras',
            'satuan' => 'karung',
            'is_barang_pasar' => true,
        ]);

        $this->assertTrue($barang->fresh()->isBarangPasar());
        $this->assertSame(1, Barang::query()->barangPasar()->count());
        $this->assertSame(0, Barang::query()->bukanBarangPasar()->count());
    }

    public function test_kategori_pasar_lama_tetap_dikenali(): void
    {
        $kategori = KategoriBarang::query()->create(['nama' => 'Pasar', 'slug' => 'pasar']);
        $barang = Barang::query()->create(['nama_barang' => 'Ikan', 'satuan' => 'kg']);
        $barang->kategoriBarangs()->attach($kategori->getKey());

        $this->assertTrue($barang->fresh()->isBarangPasar());
        $this->assertSame(0, Barang::query()->bukanBarangPasar()->count());
    }

    public function test_barang_pasar_tidak_masuk_laporan_stok(): void
    {
        $this->gudang();
        $barangGudang = Barang::query()->create(['nama_barang' => 'Galon', 'satuan' => 'buah']);
        $barangPasar = Barang::query()->create([
            'nama_barang' => 'Ikan Segar',
            'satuan' => 'kg',
            'is_barang_pasar' => true,
        ]);

        $rows = app(HistoricalStockService::class)->reportData()['rows'];
        $nama = array_column($rows, 'nama_barang');

        $this->assertContains('Galon', $nama);
        $this->assertNotContains('Ikan Segar', $nama);
    }

    public function test_barang_pasar_tidak_masuk_rekomendasi_saw(): void
    {
        Barang::query()->create(['nama_barang' => 'Galon', 'satuan' => 'buah']);
        Barang::query()->create(['nama_barang' => 'Ikan Segar', 'satuan' => 'kg', 'is_barang_pasar' => true]);

        $recommendations = app(SawRestockRecommendationService::class)->calculate()['recommendations'];
        $nama = $recommendations->pluck('nama_barang')->all();

        $this->assertContains('Galon', $nama);
        $this->assertNotContains('Ikan Segar', $nama);
    }

    public function test_barang_pasar_tidak_bisa_diinput_ke_gudang(): void
    {
        $gudang = $this->gudang();
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Pasar', 'aktif' => true]);
        $barang = Barang::query()->create([
            'nama_barang' => 'Ikan Segar',
            'satuan' => 'kg',
            'is_barang_pasar' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Barang pasar tidak dapat dimutasi ke gudang.');

        app(MutasiDataService::class)->prepare([
            'jenis_mutasi' => 'masuk',
            'barang_id' => $barang->getKey(),
            'jumlah' => 5,
            'lokasi_id' => $gudang->getKey(),
            'supplier_id' => $supplier->getKey(),
        ]);
    }

    public function test_barang_pasar_masih_dianggap_pasar_di_transaksi_belanja(): void
    {
        $barang = Barang::query()->create([
            'nama_barang' => 'Ikan Segar',
            'satuan' => 'kg',
            'is_barang_pasar' => true,
        ]);

        $context = app(BelanjaTransactionService::class)->purchaseItemContext($barang->getKey());

        $this->assertTrue($context['market']);
        $this->assertContains('kg', $context['units']);
    }

    public function test_barang_pasar_tidak_muncul_di_daftar_stok_barang(): void
    {
        $gudang = $this->gudang();
        $barangGudang = Barang::query()->create(['nama_barang' => 'Galon', 'satuan' => 'buah']);
        $barangPasar = Barang::query()->create(['nama_barang' => 'Ikan Segar', 'satuan' => 'kg', 'is_barang_pasar' => true]);

        BarangLokasi::query()->create(['barang_id' => $barangGudang->getKey(), 'lokasi_id' => $gudang->getKey(), 'stok' => 5, 'stok_baik' => 5]);
        BarangLokasi::query()->create(['barang_id' => $barangPasar->getKey(), 'lokasi_id' => $gudang->getKey(), 'stok' => 5, 'stok_baik' => 5]);

        $ids = BarangLokasiResource::getEloquentQuery()->pluck('barang_lokasi.barang_id')->all();

        $this->assertContains($barangGudang->getKey(), $ids);
        $this->assertNotContains($barangPasar->getKey(), $ids);
    }

    public function test_barang_pasar_tidak_muncul_di_widget_stok_hampir_habis(): void
    {
        $gudang = $this->gudang();
        $barangGudang = Barang::query()->create(['nama_barang' => 'Galon', 'satuan' => 'buah']);
        $barangPasar = Barang::query()->create(['nama_barang' => 'Ikan Segar', 'satuan' => 'kg', 'is_barang_pasar' => true]);

        // Stok di bawah ambang batas (<10) supaya kandidat muncul di widget.
        BarangLokasi::query()->create(['barang_id' => $barangGudang->getKey(), 'lokasi_id' => $gudang->getKey(), 'stok' => 2, 'stok_baik' => 2]);
        BarangLokasi::query()->create(['barang_id' => $barangPasar->getKey(), 'lokasi_id' => $gudang->getKey(), 'stok' => 1, 'stok_baik' => 1]);

        $widget = new BarangAlert;
        $method = new \ReflectionMethod($widget, 'getViewData');
        $method->setAccessible(true);

        $names = collect($method->invoke($widget)['rows'])->pluck('name')->all();

        $this->assertContains('Galon', $names);
        $this->assertNotContains('Ikan Segar', $names);
    }

    public function test_barang_biasa_tidak_terpengaruh(): void
    {
        $this->gudang();
        $barang = Barang::query()->create(['nama_barang' => 'Galon', 'satuan' => 'buah']);

        BarangLokasi::query()->create([
            'barang_id' => $barang->getKey(),
            'lokasi_id' => Lokasi::query()->value('id'),
            'stok' => 3,
            'stok_baik' => 3,
        ]);

        $rows = app(HistoricalStockService::class)->reportData()['rows'];
        $nama = array_column($rows, 'nama_barang');
        $this->assertContains('Galon', $nama);

        $recommendations = app(SawRestockRecommendationService::class)->calculate()['recommendations'];
        $this->assertContains('Galon', $recommendations->pluck('nama_barang')->all());
    }
}
