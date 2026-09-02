<?php

namespace Tests\Unit;

use App\Services\HistoricalStockService;
use PHPUnit\Framework\TestCase;

class HistoricalStockServiceTest extends TestCase
{
    public function test_it_rewinds_approved_stock_movements_after_the_cutoff(): void
    {
        $current = [
            '10:1' => [
                'barang_id' => 10, 'lokasi_id' => 1, 'posisi_rak_id' => 11,
                'stok' => 13, 'stok_baik' => 12, 'stok_rusak' => 1, 'stok_hilang' => 0,
            ],
            '10:2' => [
                'barang_id' => 10, 'lokasi_id' => 2, 'posisi_rak_id' => 21,
                'stok' => 4, 'stok_baik' => 0, 'stok_rusak' => 4, 'stok_hilang' => 0,
            ],
        ];
        $futureMutations = [
            [
                'jenis_mutasi' => 'keluar', 'barang_id' => 10, 'lokasi_id' => 1,
                'lokasi_tujuan_id' => 2, 'jumlah' => 4, 'kondisi_asal' => 'rusak',
                'posisi_rak_asal_id' => 11, 'posisi_rak_tujuan_id' => 21,
            ],
            [
                'jenis_mutasi' => 'perubahan_kondisi', 'barang_id' => 10, 'lokasi_id' => 1,
                'jumlah' => 3, 'kondisi_asal' => 'baik', 'kondisi_tujuan' => 'rusak',
                'posisi_rak_asal_id' => 12, 'posisi_rak_tujuan_id' => 12,
            ],
            [
                'jenis_mutasi' => 'masuk', 'barang_id' => 10, 'lokasi_id' => 1,
                'jumlah' => 5, 'kondisi_tujuan' => 'baik', 'posisi_rak_tujuan_id' => 12,
            ],
        ];

        $result = (new HistoricalStockService)->rewindState(
            $current,
            $futureMutations,
            [['barang_id' => 10, 'lokasi_id' => 1, 'posisi_rak_asal_id' => 11]],
            [1, 2],
        );

        $this->assertSame(12, $result['10:1']['stok']);
        $this->assertSame(10, $result['10:1']['stok_baik']);
        $this->assertSame(2, $result['10:1']['stok_rusak']);
        $this->assertSame(11, $result['10:1']['posisi_rak_id']);
        $this->assertSame(0, $result['10:2']['stok']);
        $this->assertSame(0, $result['10:2']['stok_rusak']);
    }

    public function test_it_ignores_usage_destinations_when_rewinding_an_outgoing_mutation(): void
    {
        $current = [
            '10:1' => [
                'barang_id' => 10, 'lokasi_id' => 1, 'posisi_rak_id' => null,
                'stok' => 6, 'stok_baik' => 6, 'stok_rusak' => 0, 'stok_hilang' => 0,
            ],
        ];

        $result = (new HistoricalStockService)->rewindState($current, [[
            'jenis_mutasi' => 'keluar', 'barang_id' => 10, 'lokasi_id' => 1,
            'lokasi_tujuan_id' => 99, 'jumlah' => 4, 'kondisi_asal' => 'baik',
        ]], [], [1]);

        $this->assertSame(10, $result['10:1']['stok']);
        $this->assertArrayNotHasKey('10:99', $result);
    }
}
