<?php

namespace Tests\Unit;

use App\Services\HistoricalStockService;
use App\Services\StockExcelExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class StockExcelExportServiceTest extends TestCase
{
    public function test_it_builds_a_readable_historical_stock_workbook(): void
    {
        $service = new StockExcelExportService(new HistoricalStockService);
        $path = $service->buildFromReport([
            'rows' => [[
                'sequence' => 1,
                'kode_barang' => 'BRG-001',
                'nama_barang' => 'Barang Uji',
                'gudang' => 'Gudang Utama',
                'rak' => 'RK1-01',
                'stok_baik' => 8,
                'stok_rusak' => 1,
                'stok_hilang' => 0,
                'stok' => 9,
                'satuan' => 'pcs',
            ]],
            'context' => [
                'filter_description' => 'Gudang Utama',
                'search' => '',
                'total_items' => 1,
                'total_stock' => 9,
                'as_of_date' => '2026-08-31',
                'as_of_label' => '31 Agustus 2026',
                'generated_at' => '03 September 2026, 10:00',
            ],
        ]);

        try {
            $workbook = IOFactory::load($path);
            $sheet = $workbook->getActiveSheet();

            $this->assertSame('Posisi stok per 31 Agustus 2026 (akhir hari)', $sheet->getCell('A3')->getValue());
            $this->assertSame('Kode Barang', $sheet->getCell('B8')->getValue());
            $this->assertSame('BRG-001', $sheet->getCell('B9')->getValue());
            $this->assertSame(9, $sheet->getCell('I9')->getValue());
            $this->assertSame('A9', $sheet->getFreezePane());

            $workbook->disconnectWorksheets();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_stock_page_exposes_filtered_as_of_date_excel_export_without_writes(): void
    {
        $root = dirname(__DIR__, 2);
        $resource = (string) file_get_contents($root.'/app/Filament/Resources/BarangLokasiResource.php');
        $history = (string) file_get_contents($root.'/app/Services/HistoricalStockService.php');

        $this->assertStringContainsString("Action::make('export-excel')", $resource);
        $this->assertStringContainsString("DatePicker::make('as_of_date')", $resource);
        $this->assertStringContainsString("where('status', 'approved')", $history);
        $this->assertStringContainsString("whereDate('tanggal', '>',", $history);
        $this->assertStringNotContainsString('->update(', $history);
        $this->assertStringNotContainsString('->delete(', $history);
        $this->assertStringNotContainsString('->insert(', $history);
    }

    public function test_it_has_a_lightweight_excel_compatible_fallback(): void
    {
        $service = new StockExcelExportService(new HistoricalStockService);
        $response = $service->downloadCsvFromReport([
            'rows' => [[
                'sequence' => 1,
                'kode_barang' => 'BRG-001',
                'nama_barang' => 'Barang Uji',
                'gudang' => 'Gudang Utama',
                'rak' => 'RK1-01',
                'stok_baik' => 8,
                'stok_rusak' => 1,
                'stok_hilang' => 0,
                'stok' => 9,
                'satuan' => 'pcs',
            ]],
            'context' => [
                'filter_description' => 'Gudang Utama',
                'as_of_date' => '2026-08-31',
                'as_of_label' => '31 Agustus 2026',
            ],
        ]);

        ob_start();
        $response->sendContent();
        $contents = (string) ob_get_clean();

        $this->assertStringContainsString('attachment; filename=rekap_stok_2026-08-31_', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('BRG-001;"Barang Uji";"Gudang Utama"', $contents);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
    }
}
