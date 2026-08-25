<?php

namespace Tests\Unit;

use App\Models\Lokasi;
use App\Models\Mutasi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MutasiLocationLabelTest extends TestCase
{
    #[DataProvider('locationLabelProvider')]
    public function test_source_and_destination_labels_follow_mutation_flow(
        string $type,
        string $expectedSource,
        string $expectedDestination,
    ): void {
        $mutation = new Mutasi(['jenis_mutasi' => $type]);
        $mutation->setRelation('lokasi', new Lokasi(['nama_lokasi' => 'Gudang Utama']));
        $mutation->setRelation('lokasiTujuan', new Lokasi(['nama_lokasi' => 'Lokasi Tujuan']));

        $this->assertSame($expectedSource, $mutation->sourceLabel());
        $this->assertSame($expectedDestination, $mutation->destinationLabel());
    }

    public static function locationLabelProvider(): array
    {
        return [
            'barang masuk' => ['masuk', 'Pengadaan Barang', 'Gudang Utama'],
            'antar gudang atau lokasi pemakaian' => ['keluar', 'Gudang Utama', 'Lokasi Tujuan'],
            'perubahan kondisi' => ['perubahan_kondisi', 'Gudang Utama', 'Gudang Utama'],
        ];
    }
}
