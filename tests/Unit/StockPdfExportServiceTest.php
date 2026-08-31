<?php

namespace Tests\Unit;

use App\Services\Pdf\StockPdfDocument;
use PHPUnit\Framework\TestCase;

class StockPdfExportServiceTest extends TestCase
{
    public function test_pdf_document_contains_all_rows_across_multiple_pages(): void
    {
        $rows = [];

        for ($index = 1; $index <= 25; $index++) {
            $rows[] = [
                'sequence' => $index,
                'kode_barang' => sprintf('BRG-%03d', $index),
                'nama_barang' => "Barang Uji {$index}",
                'gudang' => $index === 25 ? 'Belum ditempatkan' : 'Gudang Utama',
                'rak' => $index === 25 ? '-' : 'RK1-01',
                'stok_baik' => $index === 25 ? 0 : 8,
                'stok_rusak' => 1,
                'stok_hilang' => 0,
                'stok' => $index === 25 ? 0 : 9,
                'satuan' => 'pcs',
            ];
        }

        $pdf = (new StockPdfDocument)->render($rows, [
            'filter_description' => 'Gudang Utama',
            'search' => '',
            'total_items' => 25,
            'total_stock' => 216,
            'generated_at' => '31 Agustus 2026, 10:00',
        ]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertSame(2, preg_match_all('/\/Type \/Page\b/', $pdf));
        $this->assertStringContainsString('Barang Uji 1', $pdf);
        $this->assertStringContainsString('Barang Uji 25', $pdf);
        $this->assertStringContainsString('Belum ditempatkan', $pdf);
        $this->assertStringContainsString('Halaman 2 dari 2', $pdf);
        $this->assertStringEndsWith('%%EOF', $pdf);
    }

    public function test_stock_page_exposes_read_only_filtered_pdf_export(): void
    {
        $root = dirname(__DIR__, 2);
        $resource = (string) file_get_contents($root.'/app/Filament/Resources/BarangLokasiResource.php');
        $page = (string) file_get_contents(
            $root.'/app/Filament/Resources/BarangLokasiResource/Pages/ListBarangLokasis.php',
        );
        $service = (string) file_get_contents($root.'/app/Services/StockPdfExportService.php');

        $this->assertStringContainsString("Action::make('export-pdf')", $resource);
        $this->assertStringContainsString('stockPdfExportContext()', $resource);
        $this->assertStringContainsString('public function stockPdfExportContext', $page);
        $this->assertStringContainsString("'warehouses' => \$warehouses", $page);
        $this->assertStringContainsString('Barang::query()', $service);
        $this->assertStringContainsString("'stok' => 0", $service);
        $this->assertStringNotContainsString('insert(', $service);
        $this->assertStringNotContainsString('update(', $service);
        $this->assertStringNotContainsString('delete(', $service);
    }
}
