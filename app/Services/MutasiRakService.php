<?php

namespace App\Services;

use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\MutasiRak;
use App\Models\PosisiRak;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MutasiRakService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, int $createdBy): MutasiRak
    {
        return DB::transaction(function () use ($data, $createdBy): MutasiRak {
            $barangId = (int) ($data['barang_id'] ?? 0);
            $lokasiId = (int) ($data['lokasi_id'] ?? 0);
            $targetId = (int) ($data['posisi_rak_tujuan_id'] ?? 0);

            $warehouse = Lokasi::query()->lockForUpdate()->find($lokasiId);
            $this->assertRackWarehouse($warehouse);

            $pivot = $this->lockPivot($barangId, $lokasiId);
            if (! $pivot || (int) $pivot->stok <= 0) {
                throw new RuntimeException('Barang tidak memiliki stok di gudang yang dipilih.');
            }

            $sourceId = (int) ($pivot->posisi_rak_id ?? 0);
            if (! $sourceId) {
                throw new RuntimeException('Barang belum memiliki posisi rak tetap di gudang tersebut.');
            }

            $this->assertPosition($sourceId, $lokasiId, 'Posisi rak asal tidak aktif atau tidak valid.');
            $this->assertPosition($targetId, $lokasiId, 'Posisi rak tujuan tidak aktif atau bukan milik gudang tersebut.');
            if ($sourceId === $targetId) {
                throw new RuntimeException('Rak tujuan harus berbeda dari rak asal.');
            }

            $this->assertNoPendingTransactions($barangId, $lokasiId);
            $stock = $this->stock($pivot);
            $this->assertStockConsistent($stock);

            return MutasiRak::query()->create([
                'tanggal' => $data['tanggal'] ?? now()->toDateString(),
                'barang_id' => $barangId,
                'lokasi_id' => $lokasiId,
                'posisi_rak_asal_id' => $sourceId,
                'posisi_rak_tujuan_id' => $targetId,
                ...$stock,
                'status' => MutasiRak::STATUS_PENDING,
                'no_ref' => $data['no_ref'] ?? null,
                'keterangan' => $data['keterangan'] ?? null,
                'created_by' => $createdBy,
            ]);
        });
    }

    public function approve(MutasiRak $mutation, int $approvedBy): MutasiRak
    {
        return DB::transaction(function () use ($mutation, $approvedBy): MutasiRak {
            $mutation = MutasiRak::query()->lockForUpdate()->findOrFail($mutation->getKey());
            if ($mutation->status !== MutasiRak::STATUS_PENDING) {
                throw new RuntimeException('Mutasi rak bukan berstatus pending.');
            }

            $warehouse = Lokasi::query()->lockForUpdate()->find($mutation->lokasi_id);
            $this->assertRackWarehouse($warehouse);

            $pivot = $this->lockPivot((int) $mutation->barang_id, (int) $mutation->lokasi_id);
            if (! $pivot || (int) $pivot->stok <= 0) {
                throw new RuntimeException('Stok barang di gudang tidak ditemukan.');
            }

            if ((int) $pivot->posisi_rak_id !== (int) $mutation->posisi_rak_asal_id) {
                throw new RuntimeException('Rak barang telah berubah sejak permintaan dibuat. Silakan batalkan dan buat mutasi baru.');
            }

            $this->assertPosition(
                (int) $mutation->posisi_rak_asal_id,
                (int) $mutation->lokasi_id,
                'Posisi rak asal tidak lagi aktif atau valid.',
            );
            $this->assertPosition(
                (int) $mutation->posisi_rak_tujuan_id,
                (int) $mutation->lokasi_id,
                'Posisi rak tujuan tidak lagi aktif atau valid.',
            );

            $stock = $this->stock($pivot);
            $this->assertStockConsistent($stock);
            foreach (array_keys($stock) as $column) {
                if ($stock[$column] !== (int) $mutation->{$column}) {
                    throw new RuntimeException('Stok telah berubah sejak permintaan dibuat. Selesaikan transaksi terkait lalu buat mutasi rak baru.');
                }
            }

            $this->assertNoRegularMutationPending((int) $mutation->barang_id, (int) $mutation->lokasi_id);

            DB::table('barang_lokasi')
                ->where('barang_id', $mutation->barang_id)
                ->where('lokasi_id', $mutation->lokasi_id)
                ->update([
                    'posisi_rak_id' => $mutation->posisi_rak_tujuan_id,
                    'updated_at' => now(),
                ]);

            $mutation->update([
                'status' => MutasiRak::STATUS_APPROVED,
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            return $mutation->fresh([
                'barang', 'lokasi', 'posisiRakAsal', 'posisiRakTujuan', 'createdBy', 'approvedBy',
            ]);
        });
    }

    public function cancel(MutasiRak $mutation, int $cancelledBy, string $reason): MutasiRak
    {
        return DB::transaction(function () use ($mutation, $cancelledBy, $reason): MutasiRak {
            $mutation = MutasiRak::query()->lockForUpdate()->findOrFail($mutation->getKey());
            if ($mutation->status !== MutasiRak::STATUS_PENDING) {
                throw new RuntimeException('Hanya mutasi rak pending yang dapat dibatalkan.');
            }

            $mutation->update([
                'status' => MutasiRak::STATUS_CANCELLED,
                'cancelled_by' => $cancelledBy,
                'cancelled_at' => now(),
                'cancel_reason' => trim($reason),
            ]);

            return $mutation->fresh();
        });
    }

    private function assertRackWarehouse(?Lokasi $warehouse): void
    {
        if (! $warehouse?->isGudang() || ! $warehouse->menggunakan_rak) {
            throw new RuntimeException('Gudang tidak valid atau tidak menggunakan rak.');
        }
    }

    private function assertPosition(int $positionId, int $warehouseId, string $message): void
    {
        if (! $positionId || ! PosisiRak::query()->aktif()->whereKey($positionId)
            ->where('lokasi_id', $warehouseId)->lockForUpdate()->exists()) {
            throw new RuntimeException($message);
        }
    }

    private function assertNoPendingTransactions(int $barangId, int $warehouseId): void
    {
        if (MutasiRak::query()->where('status', MutasiRak::STATUS_PENDING)
            ->where('barang_id', $barangId)->where('lokasi_id', $warehouseId)->exists()) {
            throw new RuntimeException('Barang ini sudah memiliki mutasi antar-rak yang masih pending.');
        }

        $this->assertNoRegularMutationPending($barangId, $warehouseId);
    }

    private function assertNoRegularMutationPending(int $barangId, int $warehouseId): void
    {
        $exists = Mutasi::query()->where('status', 'pending')->where('barang_id', $barangId)
            ->where(function (Builder $query) use ($warehouseId): void {
                $query->where('lokasi_id', $warehouseId)
                    ->orWhere(function (Builder $query) use ($warehouseId): void {
                        $query->where('jenis_mutasi', 'keluar')->where('lokasi_tujuan_id', $warehouseId);
                    });
            })->exists();

        if ($exists) {
            throw new RuntimeException('Selesaikan mutasi stok pending untuk barang dan gudang ini terlebih dahulu.');
        }
    }

    private function lockPivot(int $barangId, int $warehouseId): ?object
    {
        return DB::table('barang_lokasi')->where('barang_id', $barangId)
            ->where('lokasi_id', $warehouseId)->lockForUpdate()->first();
    }

    /** @return array{stok: int, stok_baik: int, stok_rusak: int, stok_hilang: int} */
    private function stock(object $pivot): array
    {
        return [
            'stok' => (int) $pivot->stok,
            'stok_baik' => (int) $pivot->stok_baik,
            'stok_rusak' => (int) $pivot->stok_rusak,
            'stok_hilang' => (int) $pivot->stok_hilang,
        ];
    }

    /** @param array{stok: int, stok_baik: int, stok_rusak: int, stok_hilang: int} $stock */
    private function assertStockConsistent(array $stock): void
    {
        if ($stock['stok'] !== $stock['stok_baik'] + $stock['stok_rusak'] + $stock['stok_hilang']) {
            throw new RuntimeException('Data stok tidak konsisten. Perbaiki stok sebelum melakukan mutasi rak.');
        }
    }
}
