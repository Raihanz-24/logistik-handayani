<?php

namespace Tests\Unit;

use App\Services\SawRestockRecommendationService;
use PHPUnit\Framework\TestCase;

class SawRestockRecommendationServiceTest extends TestCase
{
    public function test_it_ranks_products_using_saw_benefit_and_cost_normalization(): void
    {
        $alternatives = collect([
            [
                'produk_id' => 1,
                'kode_produk' => 'A',
                'nama_produk' => 'Produk A',
                'satuan' => 'pcs',
                'frekuensi_pemakaian' => 3,
                'jumlah_pemakaian' => 30,
                'sisa_stok' => 2,
            ],
            [
                'produk_id' => 2,
                'kode_produk' => 'B',
                'nama_produk' => 'Produk B',
                'satuan' => 'pcs',
                'frekuensi_pemakaian' => 2,
                'jumlah_pemakaian' => 40,
                'sisa_stok' => 10,
            ],
            [
                'produk_id' => 3,
                'kode_produk' => 'C',
                'nama_produk' => 'Produk C',
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

        $this->assertSame(['A', 'B', 'C'], $recommendations->pluck('kode_produk')->all());
        $this->assertSame([1, 2, 3], $recommendations->pluck('peringkat')->all());

        $first = $recommendations->first();
        $this->assertSame(3, $first['frekuensi_pemakaian']);
        $this->assertSame(30, $first['jumlah_pemakaian']);
        $this->assertSame(2, $first['sisa_stok']);
        $this->assertEqualsWithDelta(0.694444, $first['nilai_preferensi'], 0.000001);

        $zeroStockProduct = $recommendations->last();
        $this->assertEqualsWithDelta(1.0, $zeroStockProduct['normalisasi_stok'], 0.000001);
    }
}
