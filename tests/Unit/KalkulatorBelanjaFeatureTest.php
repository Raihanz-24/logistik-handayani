<?php

namespace Tests\Unit;

use App\Filament\Resources\KalkulatorBelanjaResource;
use App\Services\BelanjaTransactionService;
use App\Services\Pdf\BelanjaPdfDocument;
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

    public function test_detail_transaksi_mendukung_barang_harga_supplier_dan_banyak_nota(): void
    {
        $root = dirname(__DIR__, 2);
        $resource = (string) file_get_contents($root.'/app/Filament/Resources/KalkulatorBelanjaResource.php');
        $relationManager = (string) file_get_contents(
            $root.'/app/Filament/Resources/KalkulatorBelanjaResource/RelationManagers/PengeluaranRelationManager.php',
        );
        $transactionCard = (string) file_get_contents(
            $root.'/resources/views/filament/tables/columns/pengeluaran-belanja-card.blade.php',
        );

        $this->assertStringContainsString('PengeluaranRelationManager::class', $resource);
        $this->assertStringContainsString("Select::make('supplier_id')", $relationManager);
        $this->assertStringContainsString("Repeater::make('items')", $relationManager);
        $this->assertStringContainsString("Select::make('barang_id')", $relationManager);
        $this->assertStringContainsString("->stripCharacters(['.', ',', ' ', 'Rp', 'rp'])", $relationManager);
        $this->assertStringContainsString('->live(onBlur: true)', $relationManager);
        $this->assertStringContainsString("FileUpload::make('nota_paths')", $relationManager);
        $this->assertStringContainsString('->multiple()', $relationManager);
        $this->assertStringContainsString('->maxFiles(20)', $relationManager);
        $this->assertStringContainsString('NotaBelanjaImageService::class', $relationManager);
        $this->assertStringContainsString('latestPrice(', $relationManager);
        $this->assertStringContainsString("Action::make('atur-foto-maps')", $relationManager);
        $this->assertStringContainsString("Action::make('buka-foto-maps')", $relationManager);
        $this->assertStringContainsString('$transaction = $getRecord();', $transactionCard);
        $this->assertStringNotContainsString('$record->', $transactionCard);
        $this->assertStringContainsString("mountTableAction('atur-foto-maps'", $transactionCard);
        $this->assertStringContainsString('Hubungkan Folder', $transactionCard);
        $this->assertStringContainsString('Buat Sesi Foto Baru', $transactionCard);
        $this->assertStringNotContainsString("make('diskon')", $relationManager);
        $this->assertStringNotContainsString("make('biaya_tambahan')", $relationManager);
        $this->assertStringContainsString("'view' => Pages\\ViewKalkulatorBelanja::route('/{record}')", $resource);
    }

    public function test_tampilan_mobile_memakai_kartu_transaksi_dan_form_saldo(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $resource = (string) file_get_contents($projectRoot.'/app/Filament/Resources/KalkulatorBelanjaResource.php');
        $listPage = (string) file_get_contents(
            $projectRoot.'/app/Filament/Resources/KalkulatorBelanjaResource/Pages/ListKalkulatorBelanjas.php',
        );
        $listView = (string) file_get_contents(
            $projectRoot.'/resources/views/filament/resources/kalkulator-belanja-resource/pages/list-kalkulator-belanjas.blade.php',
        );
        $mobileCard = (string) file_get_contents(
            $projectRoot.'/resources/views/filament/tables/columns/kalkulator-belanja-mobile.blade.php',
        );
        $styles = (string) file_get_contents($projectRoot.'/resources/css/filament-dashboard.css');

        $this->assertStringContainsString("ViewColumn::make('mobile_transaction')", $resource);
        $this->assertStringContainsString("->hiddenFrom('md')", $resource);
        $this->assertStringContainsString("->visibleFrom('md')", $resource);
        $this->assertStringContainsString('wm-kb-summary-section', $resource);
        $this->assertStringContainsString('Sisa saldo', $mobileCard);
        $this->assertStringContainsString('Total keluar', $mobileCard);
        $this->assertStringContainsString('.wm-kb-transaction-card', $styles);
        $this->assertStringContainsString("->columns(['default' => 1, 'md' => 2])", $resource);
        $this->assertStringContainsString('.wm-kb-store-card', $styles);
        $this->assertStringContainsString('extends Page', $listPage);
        $this->assertStringContainsString("'total_out'", $listPage);
        $this->assertStringContainsString("'transactions'", $listPage);
        $this->assertStringContainsString('wm-kb-filter-card', $listView);
        $this->assertStringContainsString('wm-kb-summary-grid', $listView);
        $this->assertStringNotContainsString('$this->table', $listView);
    }

    public function test_migrasi_detail_belanja_hanya_menambah_tabel_baru(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_09_07_010000_add_purchase_details_and_photo_links.php',
        );

        $this->assertStringContainsString("Schema::create('pengeluaran_belanja_items'", $migration);
        $this->assertStringContainsString("Schema::create('pengeluaran_belanja_notas'", $migration);
        $this->assertStringContainsString("Schema::create('harga_barang_suppliers'", $migration);
        $this->assertStringContainsString("Schema::create('foto_barang_session_pengeluaran_belanja'", $migration);
        $this->assertStringNotContainsString('Schema::table(', $migration);
        $this->assertStringNotContainsString("DB::table('mutasis'", $migration);
        $this->assertStringNotContainsString("DB::table('barang_lokasi'", $migration);
    }

    public function test_subtotal_mendukung_jumlah_pecahan_dan_pembulatan_rupiah(): void
    {
        $service = new BelanjaTransactionService;

        $this->assertSame(37_500, $service->subtotal('1.500', 25_000));
        $this->assertSame(8_333, $service->subtotal('0.333', 25_024));
        $this->assertSame(0, $service->subtotal(0, 25_000));
    }

    public function test_pdf_belanja_memuat_detail_dan_tidak_menyisipkan_foto(): void
    {
        $pdf = (new BelanjaPdfDocument)->render([[
            'sequence' => 1,
            'date' => '07/09/2026',
            'session' => 'Belanja Bulanan',
            'supplier' => 'Toko A',
            'item' => 'BRG-001 Beras',
            'quantity' => '2,5 kg',
            'price' => 'Rp15.000',
            'subtotal' => 'Rp37.500',
            'note' => 'Keperluan dapur',
            'evidence' => '2 nota · 1 folder',
        ]], [
            'period' => 'September 2026',
            'session_count' => 1,
            'transaction_count' => 1,
            'total_out' => 37_500,
            'generated_at' => '07 September 2026, 12:00',
        ]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('BRG-001 Beras', $pdf);
        $this->assertStringContainsString('Foto nota tidak disisipkan', $pdf);
        $this->assertStringEndsWith('%%EOF', $pdf);
    }

    public function test_foto_maps_tetap_opsional_dan_dapat_dihubungkan_otomatis_dari_transaksi(): void
    {
        $root = dirname(__DIR__, 2);
        $page = (string) file_get_contents($root.'/app/Filament/Pages/FotoBarangMaps.php');
        $folder = (string) file_get_contents($root.'/app/Filament/Pages/FotoBarangFolder.php');
        $view = (string) file_get_contents($root.'/resources/views/filament/pages/foto-barang-maps.blade.php');

        $this->assertStringContainsString('public ?int $pendingPengeluaranId = null', $page);
        $this->assertStringContainsString('syncWithoutDetaching', $page);
        $this->assertStringContainsString('visibleExpensesQuery()', $page);
        $this->assertStringContainsString('fm-purchase-context', $view);
        $this->assertStringContainsString("with(['supplier', 'kalkulatorBelanja', 'items'])", $folder);
    }

    public function test_foto_maps_dapat_diberi_label_barang_hanya_dari_transaksi_terhubung(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string) file_get_contents(
            $root.'/database/migrations/2026_09_07_020000_create_foto_barang_item_belanja_links.php',
        );
        $service = (string) file_get_contents($root.'/app/Services/FotoBarangPurchaseItemLinkService.php');
        $page = (string) file_get_contents($root.'/app/Filament/Pages/FotoBarangFolder.php');
        $view = (string) file_get_contents($root.'/resources/views/filament/pages/foto-barang-folder.blade.php');
        $script = (string) file_get_contents($root.'/resources/js/foto-barang-folder.js');

        $this->assertStringContainsString("Schema::create('foto_barang_item_belanja_links'", $migration);
        $this->assertStringContainsString("->unique('foto_barang_item_id'", $migration);
        $this->assertStringNotContainsString('Schema::table(', $migration);
        $this->assertStringNotContainsString("DB::table('mutasis'", $migration);
        $this->assertStringNotContainsString("DB::table('barang_lokasi'", $migration);
        $this->assertStringContainsString("whereHas('pengeluaranBelanja.fotoBarangSessions'", $service);
        $this->assertStringContainsString("whereHas('pengeluaranBelanja.kalkulatorBelanja'", $service);
        $this->assertStringContainsString('public function savePurchaseItemLabels', $page);
        $this->assertStringContainsString('Supplier:', $view);
        $this->assertStringContainsString('Tentukan Barang', $view);
        $this->assertStringContainsString('Tetapkan Barang', $view);
        $this->assertStringContainsString('Lepas Label', $view);
        $this->assertStringContainsString('this.$wire.savePurchaseItemLabels', $script);
    }
}
