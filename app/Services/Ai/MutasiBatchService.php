<?php

namespace App\Services\Ai;

use App\Models\BarangLokasi;
use App\Models\Mutasi;
use App\Services\MutasiDataService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Validasi + penyiapan BANYAK mutasi sekaligus (batch) dalam satu konfirmasi.
 *
 * Kenapa perlu kelas tersendiri: MutasiDataService::prepare() memvalidasi satu
 * mutasi terhadap stok yang ADA SAAT INI. Bila dua item dalam batch sama-sama
 * mengurangi stok barang+kondisi yang sama, masing-masing bisa lolos walau
 * TOTALNYA melebihi stok. Di sini permintaan dijumlahkan dulu (kumulatif)
 * sebelum dinyatakan sah.
 */
class MutasiBatchService
{
    /**
     * Validasi seluruh item dan kembalikan payload hasil prepare().
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     *
     * @throws RuntimeException bila ada item yang tidak valid / stok kumulatif kurang.
     */
    public function validate(array $items, int $userId): array
    {
        if ($items === []) {
            throw new RuntimeException('Minimal satu mutasi harus diisi.');
        }

        $prepared = [];

        foreach ($items as $index => $item) {
            try {
                $prepared[$index] = app(MutasiDataService::class)->prepare([
                    ...$item,
                    'user_id' => $userId,
                ]);
            } catch (\Throwable $exception) {
                throw new RuntimeException(
                    'Item ke-'.($index + 1).' tidak valid: '.$exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }

        $this->assertCumulativeStock($prepared);

        return $prepared;
    }

    /**
     * Pastikan total permintaan yang MENGURANGI stok tidak melebihi stok
     * tersedia (fisik dikurangi mutasi pending lain) untuk barang+gudang+kondisi
     * yang sama di dalam batch.
     *
     * @param  array<int, array<string, mixed>>  $prepared
     */
    private function assertCumulativeStock(array $prepared): void
    {
        $demand = [];

        foreach ($prepared as $item) {
            if (! in_array($item['jenis_mutasi'], ['keluar', 'perubahan_kondisi'], true)) {
                continue;
            }

            $key = implode('|', [
                (int) $item['barang_id'],
                (int) $item['lokasi_id'],
                (string) $item['kondisi_asal'],
            ]);

            $demand[$key] = ($demand[$key] ?? 0) + (int) $item['jumlah'];
        }

        foreach ($demand as $key => $total) {
            [$barangId, $lokasiId, $kondisi] = explode('|', (string) $key);

            $column = BarangLokasi::conditionColumn($kondisi);

            $physical = (int) DB::table('barang_lokasi')
                ->where('barang_id', (int) $barangId)
                ->where('lokasi_id', (int) $lokasiId)
                ->value($column);

            $reserved = (int) Mutasi::query()
                ->where('status', 'pending')
                ->whereIn('jenis_mutasi', ['keluar', 'perubahan_kondisi'])
                ->where('barang_id', (int) $barangId)
                ->where('lokasi_id', (int) $lokasiId)
                ->where('kondisi_asal', $kondisi)
                ->sum('jumlah');

            $available = max(0, $physical - $reserved);

            if ($total > $available) {
                $nama = \App\Models\Barang::query()->whereKey((int) $barangId)->value('nama_barang') ?? ('#'.$barangId);

                throw new RuntimeException(
                    "Stok '{$nama}' (kondisi {$kondisi}) tidak mencukupi untuk seluruh item batch: "
                    ."tersedia {$available}, diminta {$total}."
                );
            }
        }
    }
}
