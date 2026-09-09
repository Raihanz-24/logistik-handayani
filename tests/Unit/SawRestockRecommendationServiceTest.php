<?php

namespace Tests\Unit;

use App\Services\SawRestockRecommendationService;
use PHPUnit\Framework\TestCase;

class SawRestockRecommendationServiceTest extends TestCase
{
    public function test_it_ranks_barangs_using_saw_benefit_and_cost_normalization(): void
    {
        $alternatives = collect([
            [
                'barang_id' => 1,
                'kode_barang' => 'A',
                'nama_barang' => 'Barang A',
                'satuan' => 'pcs',
                'frekuensi_pemakaian' => 3,
                'jumlah_pemakaian' => 30,
                'sisa_stok' => 2,
            ],
            [
                'barang_id' => 2,
                'kode_barang' => 'B',
                'nama_barang' => 'Barang B',
                'satuan' => 'pcs',
                'frekuensi_pemakaian' => 2,
                'jumlah_pemakaian' => 40,
                'sisa_stok' => 10,
            ],
            [
                'barang_id' => 3,
                'kode_barang' => 'C',
                'nama_barang' => 'Barang C',
                'satuan' => 'pcs',
                'frekuensi_pemakaian' => 1,
                'jumlah_pemakaian' => 5,
                'sisa_stok' => 0,
            ],
        ]);

        $recommendations = (new SawRestockRecommendationService)->rank(
            $alternatives,
            limit: 5,
            weights: [
                'frekuensi_pemakaian' => 1 / 3,
                'jumlah_pemakaian' => 1 / 3,
                'sisa_stok' => 1 / 3,
            ],
        );

        $this->assertSame(['A', 'B', 'C'], $recommendations->pluck('kode_barang')->all());
        $this->assertSame([1, 2, 3], $recommendations->pluck('peringkat')->all());

        $first = $recommendations->first();
        $this->assertSame(3, $first['frekuensi_pemakaian']);
        $this->assertSame(30, $first['jumlah_pemakaian']);
        $this->assertSame(2, $first['sisa_stok']);
        $this->assertEqualsWithDelta(0.694444, $first['nilai_preferensi'], 0.000001);

        $zeroStockBarang = $recommendations->last();
        $this->assertEqualsWithDelta(1.0, $zeroStockBarang['normalisasi_stok'], 0.000001);
    }

    public function test_it_can_return_every_ranked_alternative_for_the_report_page(): void
    {
        $alternatives = collect([
            ['barang_id' => 1, 'kode_barang' => 'BRG-001', 'nama_barang' => 'A', 'satuan' => 'pcs', 'frekuensi_pemakaian' => 2, 'jumlah_pemakaian' => 10, 'sisa_stok' => 5],
            ['barang_id' => 2, 'kode_barang' => 'BRG-002', 'nama_barang' => 'B', 'satuan' => 'pcs', 'frekuensi_pemakaian' => 1, 'jumlah_pemakaian' => 2, 'sisa_stok' => 2],
            ['barang_id' => 3, 'kode_barang' => 'BRG-003', 'nama_barang' => 'C', 'satuan' => 'pcs', 'frekuensi_pemakaian' => 0, 'jumlah_pemakaian' => 0, 'sisa_stok' => 9],
        ]);

        $ranked = (new SawRestockRecommendationService)->rank($alternatives, PHP_INT_MAX, [
            'frekuensi_pemakaian' => 1 / 3,
            'jumlah_pemakaian' => 1 / 3,
            'sisa_stok' => 1 / 3,
        ]);

        $this->assertCount(3, $ranked);
        $this->assertSame([1, 2, 3], $ranked->pluck('peringkat')->all());
    }

    public function test_report_page_is_read_only_and_defaults_to_current_month_filter(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2).'/app/Filament/Pages/SawRestockReport.php');
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/filament/pages/saw-restock-report.blade.php');

        $this->assertStringContainsString('startOfMonth()', $page);
        $this->assertStringContainsString('endOfMonth()', $page);
        $this->assertStringContainsString('PHP_INT_MAX', $page);
        $this->assertStringNotContainsString('->update(', $page);
        $this->assertStringNotContainsString('->create(', $page);
        $this->assertStringContainsString('Minggu ini', $view);
        $this->assertStringContainsString('Bulan ini', $view);
    }
}
