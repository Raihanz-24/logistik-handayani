<?php

namespace App\Services;

use App\Models\Lokasi;
use App\Models\Mutasi;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MutasiStockService
{
    public function approve(Mutasi $mutasi, int $approvedBy): Mutasi
    {
        return DB::transaction(function () use ($mutasi, $approvedBy): Mutasi {
            $mutasi = Mutasi::query()
                ->with(['lokasi', 'lokasiTujuan'])
                ->lockForUpdate()
                ->findOrFail($mutasi->getKey());

            if ($mutasi->status !== 'pending') {
                throw new RuntimeException('Mutasi bukan berstatus pending.');
            }

            $jenis = strtolower(trim((string) $mutasi->jenis_mutasi));
            if (! in_array($jenis, ['masuk', 'keluar'], true)) {
                throw new RuntimeException('Jenis mutasi tidak valid.');
            }

            $gudang = $mutasi->lokasi;
            if (! $gudang || ! $gudang->isGudang()) {
                throw new RuntimeException('Lokasi asal/tujuan stok harus bertipe Gudang.');
            }

            if ($jenis === 'keluar') {
                if (! $mutasi->lokasiTujuan) {
                    throw new RuntimeException('Lokasi tujuan wajib diisi untuk barang keluar.');
                }

                if ((int) $mutasi->lokasi_tujuan_id === (int) $mutasi->lokasi_id) {
                    throw new RuntimeException('Lokasi tujuan tidak boleh sama dengan gudang asal.');
                }
            }

            $barangId = (int) $mutasi->barang_id;
            $gudangId = (int) $mutasi->lokasi_id;
            $jumlah = (int) $mutasi->jumlah;

            if ($jumlah <= 0) {
                throw new RuntimeException('Jumlah mutasi harus lebih dari nol.');
            }

            $pivotGudang = DB::table('barang_lokasi')
                ->where('barang_id', $barangId)
                ->where('lokasi_id', $gudangId)
                ->lockForUpdate()
                ->first();

            $stokAwal = (int) ($pivotGudang->stok ?? 0);

            if ($jenis === 'keluar' && $stokAwal < $jumlah) {
                throw new RuntimeException('Stok gudang asal tidak mencukupi.');
            }

            $stokAkhir = $jenis === 'masuk'
                ? $stokAwal + $jumlah
                : $stokAwal - $jumlah;

            DB::table('barang_lokasi')->updateOrInsert(
                ['barang_id' => $barangId, 'lokasi_id' => $gudangId],
                [
                    'stok' => $stokAkhir,
                    'updated_at' => now(),
                    'created_at' => $pivotGudang?->created_at ?? now(),
                ],
            );

            if ($jenis === 'keluar' && $mutasi->lokasiTujuan?->isGudang()) {
                $this->addStockToDestinationWarehouse(
                    $barangId,
                    (int) $mutasi->lokasi_tujuan_id,
                    $jumlah,
                );
            }

            $mutasi->update([
                'stok_awal' => $stokAwal,
                'stok_akhir' => $stokAkhir,
                'status' => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            return $mutasi->fresh(['barang', 'lokasi', 'lokasiTujuan', 'user']);
        });
    }

    private function addStockToDestinationWarehouse(
        int $barangId,
        int $gudangTujuanId,
        int $jumlah,
    ): void {
        $gudangTujuan = Lokasi::query()
            ->lockForUpdate()
            ->find($gudangTujuanId);

        if (! $gudangTujuan?->isGudang()) {
            return;
        }

        $pivotTujuan = DB::table('barang_lokasi')
            ->where('barang_id', $barangId)
            ->where('lokasi_id', $gudangTujuanId)
            ->lockForUpdate()
            ->first();

        DB::table('barang_lokasi')->updateOrInsert(
            ['barang_id' => $barangId, 'lokasi_id' => $gudangTujuanId],
            [
                'stok' => (int) ($pivotTujuan->stok ?? 0) + $jumlah,
                'updated_at' => now(),
                'created_at' => $pivotTujuan?->created_at ?? now(),
            ],
        );
    }
}
