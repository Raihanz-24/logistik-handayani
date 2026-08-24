<?php

namespace App\Services;

use App\Models\BarangLokasi;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\MutasiRak;
use App\Models\PosisiRak;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MutasiDataService
{
    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function prepare(array $data): array
    {
        $jenis = (string) ($data['jenis_mutasi'] ?? '');
        if (! array_key_exists($jenis, Mutasi::jenisOptions())) {
            throw new RuntimeException('Jenis mutasi tidak valid.');
        }

        $warehouse = Lokasi::query()->gudang()->find((int) ($data['lokasi_id'] ?? 0));
        if (! $warehouse) {
            throw new RuntimeException('Gudang asal/tujuan tidak valid.');
        }

        $barangId = (int) ($data['barang_id'] ?? 0);
        $quantity = (int) ($data['jumlah'] ?? 0);
        if (! $barangId || $quantity <= 0) {
            throw new RuntimeException('Barang dan jumlah mutasi wajib diisi dengan benar.');
        }

        $this->assertNoRackMutationPending($barangId, (int) $warehouse->id);

        $conditions = array_keys(Mutasi::kondisiOptions());
        $sourceCondition = $data['kondisi_asal'] ?? null;
        $targetCondition = $data['kondisi_tujuan'] ?? Mutasi::KONDISI_BAIK;
        if (! in_array($targetCondition, $conditions, true)) {
            throw new RuntimeException('Kondisi setelah mutasi tidak valid.');
        }
        if ($jenis !== 'masuk' && ! in_array($sourceCondition, $conditions, true)) {
            throw new RuntimeException('Kondisi asal wajib dipilih.');
        }
        if ($jenis === 'perubahan_kondisi' && $sourceCondition === $targetCondition) {
            throw new RuntimeException('Kondisi baru harus berbeda dari kondisi asal.');
        }

        $sourcePivot = null;
        $sourcePosition = null;
        if ($jenis !== 'masuk') {
            $sourcePivot = DB::table('barang_lokasi')->where('barang_id', $barangId)
                ->where('lokasi_id', $warehouse->id)->first();
            if (! $sourcePivot) {
                throw new RuntimeException('Barang tidak tersedia di gudang asal.');
            }
            $sourcePosition = $sourcePivot->posisi_rak_id ? (int) $sourcePivot->posisi_rak_id : null;
            if ($warehouse->menggunakan_rak && ! $sourcePosition) {
                throw new RuntimeException('Barang belum memiliki posisi rak di gudang asal.');
            }

            $column = BarangLokasi::conditionColumn($sourceCondition);
            $physical = (int) ($sourcePivot->{$column} ?? 0);
            $reserved = (int) Mutasi::query()->where('status', 'pending')
                ->whereIn('jenis_mutasi', ['keluar', 'perubahan_kondisi'])
                ->where('barang_id', $barangId)->where('lokasi_id', $warehouse->id)
                ->where('kondisi_asal', $sourceCondition)->sum('jumlah');
            if ($quantity > max(0, $physical - $reserved)) {
                throw new RuntimeException('Stok kondisi yang dipilih tidak mencukupi setelah dikurangi mutasi pending.');
            }
        }

        $destination = null;
        if ($jenis === 'keluar') {
            $destination = Lokasi::query()->find((int) ($data['lokasi_tujuan_id'] ?? 0));
            if (! $destination || (int) $destination->id === (int) $warehouse->id) {
                throw new RuntimeException('Lokasi tujuan harus dipilih dan berbeda dari gudang asal.');
            }
            if ($destination->isGudang()) {
                $this->assertNoRackMutationPending($barangId, (int) $destination->id);
            }
        }

        $targetWarehouse = $jenis === 'masuk' ? $warehouse : ($destination?->isGudang() ? $destination : null);
        $targetPosition = null;
        if ($targetWarehouse) {
            $targetPivot = DB::table('barang_lokasi')->where('barang_id', $barangId)
                ->where('lokasi_id', $targetWarehouse->id)->first();
            $fixedPosition = $targetPivot?->posisi_rak_id ? (int) $targetPivot->posisi_rak_id : null;
            $requestedPosition = (int) ($data['posisi_rak_tujuan_id'] ?? 0) ?: null;

            if ($fixedPosition && $requestedPosition && $fixedPosition !== $requestedPosition) {
                throw new RuntimeException('Barang sudah mempunyai rak tetap yang berbeda di gudang tujuan.');
            }
            $targetPosition = $fixedPosition ?: $requestedPosition;

            if ($targetWarehouse->menggunakan_rak) {
                if (! $targetPosition || ! PosisiRak::query()->aktif()->whereKey($targetPosition)
                    ->where('lokasi_id', $targetWarehouse->id)->exists()) {
                    throw new RuntimeException('Posisi rak tujuan wajib dipilih dan harus aktif.');
                }
            } else {
                $targetPosition = null;
            }
        }

        return [
            ...$data,
            'jenis_mutasi' => $jenis,
            'kondisi_asal' => $jenis === 'masuk' ? null : $sourceCondition,
            'kondisi_tujuan' => $targetCondition,
            'lokasi_tujuan_id' => $jenis === 'keluar' ? $destination?->id : null,
            'posisi_rak_asal_id' => $sourcePosition,
            'posisi_rak_tujuan_id' => $jenis === 'perubahan_kondisi' ? $sourcePosition : $targetPosition,
        ];
    }

    private function assertNoRackMutationPending(int $barangId, int $warehouseId): void
    {
        if (MutasiRak::query()->where('status', MutasiRak::STATUS_PENDING)
            ->where('barang_id', $barangId)->where('lokasi_id', $warehouseId)->exists()) {
            throw new RuntimeException('Selesaikan mutasi antar-rak pending untuk barang dan gudang ini terlebih dahulu.');
        }
    }
}
