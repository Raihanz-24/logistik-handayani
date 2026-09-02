<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StockPageUsabilityTest extends TestCase
{
    public function test_stock_page_has_combinable_warehouse_shortcuts_and_summary(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $page = (string) file_get_contents(
            $projectRoot.'/app/Filament/Resources/BarangLokasiResource/Pages/ListBarangLokasis.php',
        );
        $view = (string) file_get_contents(
            $projectRoot.'/resources/views/filament/resources/barang-lokasi-resource/pages/list-barang-lokasis.blade.php',
        );

        $this->assertStringContainsString("'dapur' => ['label' => 'Gudang Dapur'", $page);
        $this->assertStringContainsString("'utama' => ['label' => 'Gudang Utama'", $page);
        $this->assertStringContainsString('public function toggleGudang', $page);
        $this->assertStringContainsString('public function ringkasanGudang', $page);
        $this->assertStringContainsString('Ringkasan Stok Gudang', $view);
        $this->assertStringContainsString('wire:click="toggleGudang', $view);
    }

    public function test_filament_tables_default_to_ten_rows_with_readable_pagination(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $provider = (string) file_get_contents($projectRoot.'/app/Providers/AppServiceProvider.php');
        $styles = (string) file_get_contents($projectRoot.'/resources/css/filament-dashboard.css');

        $this->assertStringContainsString('->defaultPaginationPageOption(10)', $provider);
        $this->assertStringContainsString('->extremePaginationLinks()', $provider);
        $this->assertStringContainsString('$paginator->onEachSide(2)', $provider);
        $this->assertStringContainsString('.fi-ta-pagination .fi-pagination-overview', $styles);
        $this->assertStringContainsString('.fi-ta-pagination .fi-pagination-items', $styles);
    }

    public function test_stock_table_has_a_unit_and_configurable_visible_columns(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $resource = (string) file_get_contents(
            $projectRoot.'/app/Filament/Resources/BarangLokasiResource.php',
        );

        $this->assertStringContainsString("TextColumn::make('barang.satuan')", $resource);
        $this->assertStringContainsString("->label('Total Stok')", $resource);
        $this->assertSame(6, substr_count($resource, '->toggleable()'));
        $this->assertSame(5, substr_count($resource, '->toggleable(isToggledHiddenByDefault: true)'));
        $this->assertStringContainsString("TextColumn::make('stok_baik_tampil')", $resource);
        $this->assertStringContainsString("TextColumn::make('stok_rusak_tampil')", $resource);
        $this->assertStringContainsString("TextColumn::make('stok_hilang_tampil')", $resource);
    }

    public function test_stock_table_can_preview_the_historical_stock_date_used_by_export(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $resource = (string) file_get_contents(
            $projectRoot.'/app/Filament/Resources/BarangLokasiResource.php',
        );
        $page = (string) file_get_contents(
            $projectRoot.'/app/Filament/Resources/BarangLokasiResource/Pages/ListBarangLokasis.php',
        );
        $service = (string) file_get_contents(
            $projectRoot.'/app/Services/HistoricalStockService.php',
        );

        $this->assertStringContainsString("Filter::make('tanggal_stok')", $resource);
        $this->assertStringContainsString("DatePicker::make('tanggal')", $resource);
        $this->assertStringContainsString('applyTableSnapshotToQuery', $resource);
        $this->assertStringContainsString('public function stockAsOfDate', $page);
        $this->assertStringContainsString("'as_of_date' => \$this->stockAsOfDate()", $page);
        $this->assertStringContainsString("->where('status', 'approved')", $service);
        $this->assertStringContainsString("->whereDate('tanggal', '>', \$date)", $service);
        $this->assertStringContainsString("->fromSub(\$events, 'history_stock_events')", $service);
    }
}
