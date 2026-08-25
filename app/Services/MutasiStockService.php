<?php

namespace App\Services;

use App\Models\BarangLokasi;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\MutasiRak;
use App\Models\PosisiRak;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MutasiStockService
{
    public function approve(Mutasi $mutasi, int $approvedBy): Mutasi
    {
        return DB::transaction(function () use ($mutasi, $approvedBy): Mutasi {
            $mutasi = Mutasi::query()->with(['lokasi', 'lokasiTujuan'])
                ->lockForUpdate()->findOrFail($mutasi->getKey());

            if ($mutasi->status !== 'pending') {
                throw new RuntimeException('Mutasi bukan berstatus pending.');
            }

            $jenis = strtolower(trim((string) $mutasi->jenis_mutasi));
            if (! array_key_exists($jenis, Mutasi::jenisOptions())) {
                throw new RuntimeException('Jenis mutasi tidak valid.');
            }
            if (! $mutasi->lokasi?->isGudang()) {
                throw new RuntimeException('Lokasi stok harus bertipe Gudang.');
            }

            $this->assertNoRackMutationPending((int) $mutasi->barang_id, (int) $mutasi->lokasi_id);
            if ($mutasi->lokasiTujuan?->isGudang()) {
                $this->assertNoRackMutationPending(
                    (int) $mutasi->barang_id,
                    (int) $mutasi->lokasi_tujuan_id,
                );
            }

            $jumlah = (int) $mutasi->jumlah;
            if ($jumlah <= 0) {
                throw new RuntimeException('Jumlah mutasi harus lebih dari nol.');
            }

            $conditions = array_keys(Mutasi::kondisiOptions());
            $asal = $mutasi->kondisi_asal;
            $tujuan = $mutasi->kondisi_tujuan ?: Mutasi::KONDISI_BAIK;
            if (! in_array($tujuan, $conditions, true)) {
                throw new RuntimeException('Kondisi tujuan barang tidak valid.');
            }
            if ($jenis !== 'masuk' && ! in_array($asal, $conditions, true)) {
                throw new RuntimeException('Kondisi asal barang tidak valid.');
            }

            $snapshots = match ($jenis) {
                'masuk' => $this->receive($mutasi, $mutasi->lokasi, $tujuan, $jumlah),
                'keluar' => $this->move($mutasi, $asal, $tujuan, $jumlah),
                default => $this->changeCondition($mutasi, $asal, $tujuan, $jumlah),
            };

            $mutasi->update([
                ...$snapshots,
                'kondisi_asal' => $jenis === 'masuk' ? null : $asal,
                'kondisi_tujuan' => $tujuan,
                'status' => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            return $mutasi->fresh([
                'barang', 'supplier', 'lokasi', 'lokasiTujuan', 'posisiRakAsal', 'posisiRakTujuan', 'user',
            ]);
        });
    }

    /** @return array<string, int|null> */
    private function receive(Mutasi $mutasi, Lokasi $warehouse, string $condition, int $quantity): array
    {
        $pivot = $this->lockPivot((int) $mutasi->barang_id, (int) $warehouse->id);
        $positionId = $this->resolvePosition(
            $warehouse, $pivot, $mutasi->posisi_rak_tujuan_id,
            'Pilih posisi rak tujuan untuk barang masuk.',
        );
        $before = $this->stock($pivot);
        $column = BarangLokasi::conditionColumn($condition);
        $after = $before;
        $after[$column] += $quantity;
        $after['stok'] = $this->total($after);

        $this->savePivot((int) $mutasi->barang_id, (int) $warehouse->id, $positionId, $after, $pivot);

        return [
            'stok_awal' => $before['stok'], 'stok_akhir' => $after['stok'],
            'stok_kondisi_asal_awal' => null, 'stok_kondisi_asal_akhir' => null,
            'stok_kondisi_tujuan_awal' => $before[$column],
            'stok_kondisi_tujuan_akhir' => $after[$column],
            'posisi_rak_asal_id' => null, 'posisi_rak_tujuan_id' => $positionId,
        ];
    }

    /** @return array<string, int|null> */
    private function move(Mutasi $mutasi, string $sourceCondition, string $targetCondition, int $quantity): array
    {
        $source = $mutasi->lokasi;
        $destination = $mutasi->lokasiTujuan;
        if (! $destination) {
            throw new RuntimeException('Lokasi tujuan wajib diisi untuk barang keluar.');
        }
        if ((int) $destination->id === (int) $source->id) {
            throw new RuntimeException('Lokasi tujuan tidak boleh sama dengan gudang asal.');
        }

        $sourcePivot = $this->lockPivot((int) $mutasi->barang_id, (int) $source->id);
        if (! $sourcePivot) {
            throw new RuntimeException('Barang tidak memiliki stok di gudang asal.');
        }
        $sourcePosition = $this->resolvePosition(
            $source, $sourcePivot, $mutasi->posisi_rak_asal_id,
            'Posisi rak barang di gudang asal tidak ditemukan.',
        );
        $sourceBefore = $this->stock($sourcePivot);
        $sourceColumn = BarangLokasi::conditionColumn($sourceCondition);
        if ($sourceBefore[$sourceColumn] < $quantity) {
            $label = Mutasi::kondisiOptions()[$sourceCondition];
            throw new RuntimeException("Stok kondisi {$label} di gudang asal tidak mencukupi.");
        }

        $sourceAfter = $sourceBefore;
        $sourceAfter[$sourceColumn] -= $quantity;
        $sourceAfter['stok'] = $this->total($sourceAfter);
        $this->savePivot(
            (int) $mutasi->barang_id, (int) $source->id, $sourcePosition, $sourceAfter, $sourcePivot,
        );

        $targetBeforeValue = null;
        $targetAfterValue = null;
        $targetPosition = null;
        if ($destination->isGudang()) {
            $targetPivot = $this->lockPivot((int) $mutasi->barang_id, (int) $destination->id);
            $targetPosition = $this->resolvePosition(
                $destination, $targetPivot, $mutasi->posisi_rak_tujuan_id,
                'Pilih posisi rak tujuan untuk transfer barang.',
            );
            $targetBefore = $this->stock($targetPivot);
            $targetColumn = BarangLokasi::conditionColumn($targetCondition);
            $targetAfter = $targetBefore;
            $targetBeforeValue = $targetBefore[$targetColumn];
            $targetAfter[$targetColumn] += $quantity;
            $targetAfter['stok'] = $this->total($targetAfter);
            $targetAfterValue = $targetAfter[$targetColumn];
            $this->savePivot(
                (int) $mutasi->barang_id, (int) $destination->id, $targetPosition, $targetAfter, $targetPivot,
            );
        }

        return [
            'stok_awal' => $sourceBefore['stok'], 'stok_akhir' => $sourceAfter['stok'],
            'stok_kondisi_asal_awal' => $sourceBefore[$sourceColumn],
            'stok_kondisi_asal_akhir' => $sourceAfter[$sourceColumn],
            'stok_kondisi_tujuan_awal' => $targetBeforeValue,
            'stok_kondisi_tujuan_akhir' => $targetAfterValue,
            'posisi_rak_asal_id' => $sourcePosition, 'posisi_rak_tujuan_id' => $targetPosition,
        ];
    }

    /** @return array<string, int|null> */
    private function changeCondition(Mutasi $mutasi, string $sourceCondition, string $targetCondition, int $quantity): array
    {
        if ($sourceCondition === $targetCondition) {
            throw new RuntimeException('Kondisi asal dan kondisi tujuan harus berbeda.');
        }
        if ($mutasi->lokasi_tujuan_id) {
            throw new RuntimeException('Perubahan kondisi tidak boleh memindahkan lokasi barang.');
        }

        $warehouse = $mutasi->lokasi;
        $pivot = $this->lockPivot((int) $mutasi->barang_id, (int) $warehouse->id);
        if (! $pivot) {
            throw new RuntimeException('Barang tidak memiliki stok di gudang tersebut.');
        }
        $position = $this->resolvePosition(
            $warehouse, $pivot, $mutasi->posisi_rak_asal_id, 'Posisi rak barang tidak ditemukan.',
        );
        $before = $this->stock($pivot);
        $sourceColumn = BarangLokasi::conditionColumn($sourceCondition);
        $targetColumn = BarangLokasi::conditionColumn($targetCondition);
        if ($before[$sourceColumn] < $quantity) {
            $label = Mutasi::kondisiOptions()[$sourceCondition];
            throw new RuntimeException("Stok kondisi {$label} tidak mencukupi.");
        }

        $after = $before;
        $after[$sourceColumn] -= $quantity;
        $after[$targetColumn] += $quantity;
        $after['stok'] = $this->total($after);
        $this->savePivot((int) $mutasi->barang_id, (int) $warehouse->id, $position, $after, $pivot);

        return [
            'stok_awal' => $before['stok'], 'stok_akhir' => $after['stok'],
            'stok_kondisi_asal_awal' => $before[$sourceColumn],
            'stok_kondisi_asal_akhir' => $after[$sourceColumn],
            'stok_kondisi_tujuan_awal' => $before[$targetColumn],
            'stok_kondisi_tujuan_akhir' => $after[$targetColumn],
            'posisi_rak_asal_id' => $position, 'posisi_rak_tujuan_id' => $position,
        ];
    }

    private function lockPivot(int $barangId, int $lokasiId): ?object
    {
        return DB::table('barang_lokasi')->where('barang_id', $barangId)
            ->where('lokasi_id', $lokasiId)->lockForUpdate()->first();
    }

    /** @return array{stok: int, stok_baik: int, stok_rusak: int, stok_hilang: int} */
    private function stock(?object $pivot): array
    {
        return [
            'stok' => (int) ($pivot->stok ?? 0),
            'stok_baik' => (int) ($pivot->stok_baik ?? 0),
            'stok_rusak' => (int) ($pivot->stok_rusak ?? 0),
            'stok_hilang' => (int) ($pivot->stok_hilang ?? 0),
        ];
    }

    /** @param array{stok: int, stok_baik: int, stok_rusak: int, stok_hilang: int} $stock */
    private function total(array $stock): int
    {
        return $stock['stok_baik'] + $stock['stok_rusak'] + $stock['stok_hilang'];
    }

    /** @param array{stok: int, stok_baik: int, stok_rusak: int, stok_hilang: int} $stock */
    private function savePivot(int $barangId, int $lokasiId, ?int $positionId, array $stock, ?object $existing): void
    {
        DB::table('barang_lokasi')->updateOrInsert(
            ['barang_id' => $barangId, 'lokasi_id' => $lokasiId],
            [
                'posisi_rak_id' => $positionId, ...$stock,
                'created_at' => $existing?->created_at ?? now(), 'updated_at' => now(),
            ],
        );
    }

    private function resolvePosition(Lokasi $warehouse, ?object $pivot, mixed $requested, string $requiredMessage): ?int
    {
        if (! $warehouse->menggunakan_rak) {
            return null;
        }

        $fixed = $pivot?->posisi_rak_id ? (int) $pivot->posisi_rak_id : null;
        $requested = $requested ? (int) $requested : null;
        if ($fixed && $requested && $fixed !== $requested) {
            throw new RuntimeException('Barang ini sudah memiliki posisi rak tetap di gudang tersebut.');
        }

        $positionId = $fixed ?: $requested;
        if (! $positionId) {
            throw new RuntimeException($requiredMessage);
        }

        $valid = PosisiRak::query()->whereKey($positionId)
            ->where('lokasi_id', $warehouse->id)->where('aktif', true)->lockForUpdate()->exists();
        if (! $valid) {
            throw new RuntimeException('Posisi rak tidak aktif atau bukan milik gudang yang dipilih.');
        }

        return $positionId;
    }

    private function assertNoRackMutationPending(int $barangId, int $warehouseId): void
    {
        if (MutasiRak::query()->where('status', MutasiRak::STATUS_PENDING)
            ->where('barang_id', $barangId)->where('lokasi_id', $warehouseId)->exists()) {
            throw new RuntimeException('Selesaikan mutasi antar-rak pending untuk barang dan gudang ini terlebih dahulu.');
        }
    }
}
