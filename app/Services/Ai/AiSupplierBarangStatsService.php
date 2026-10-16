<?php

namespace App\Services\Ai;

use App\Models\AiSupplierBarangStat;
use App\Models\Barang;
use App\Models\PengeluaranBelanja;
use App\Models\Supplier;
use App\Support\PolaUrutanBarang;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membangun & membaca agregat afinitas supplier <-> barang dari histori
 * transaksi belanja.
 *
 * Dipakai Asisten AI untuk tahu "biasanya order apa dari supplier X" dan untuk
 * memberi bonus skor pada barang yang relevan dengan supplier tujuan.
 *
 * Semua murni turunan (derived) dari data yang sudah ada; aman dibangun ulang.
 */
class AiSupplierBarangStatsService
{
    /** @var array<int, array<int, AiSupplierBarangStat>> */
    private array $cache = [];

    public function available(): bool
    {
        return Schema::hasTable('ai_supplier_barang_stats');
    }

    /**
     * Segarkan agregat untuk satu supplier (dipanggil setelah transaksi belanja
     * pada supplier tsb disimpan/diubah/dihapus).
     */
    public function refreshSupplier(int $supplierId): void
    {
        if (! $this->available() || $supplierId < 1) {
            return;
        }

        $rows = DB::table('pengeluaran_belanja_items as i')
            ->join('pengeluaran_belanjas as p', 'p.id', '=', 'i.pengeluaran_belanja_id')
            ->join('kalkulator_belanjas as k', 'k.id', '=', 'p.kalkulator_belanja_id')
            ->where('p.supplier_id', $supplierId)
            ->select(['i.barang_id', 'i.jumlah', 'i.harga_satuan', 'i.satuan_snapshot', 'i.urutan', 'k.tanggal'])
            ->orderBy('k.tanggal')
            ->orderBy('i.id')
            ->get();

        // Agregasi di PHP: baris terurut menaik, jadi nilai terakhir = terbaru.
        $agg = [];

        foreach ($rows as $row) {
            $barangId = (int) $row->barang_id;

            if ($barangId < 1) {
                continue;
            }

            $agg[$barangId] ??= [
                'frekuensi' => 0,
                'total_jumlah' => 0.0,
                'urutan_sum' => 0,
                'urutan_count' => 0,
                'satuan_terakhir' => null,
                'harga_terakhir' => null,
                'tanggal_terakhir' => null,
            ];

            $agg[$barangId]['frekuensi']++;
            $agg[$barangId]['total_jumlah'] += (float) $row->jumlah;

            if ($row->urutan !== null) {
                $agg[$barangId]['urutan_sum'] += (int) $row->urutan;
                $agg[$barangId]['urutan_count']++;
            }

            if ((int) $row->harga_satuan > 0) {
                $agg[$barangId]['harga_terakhir'] = (int) $row->harga_satuan;
            }

            if (filled($row->satuan_snapshot)) {
                $agg[$barangId]['satuan_terakhir'] = (string) $row->satuan_snapshot;
            }

            $agg[$barangId]['tanggal_terakhir'] = $row->tanggal;
        }

        DB::transaction(function () use ($supplierId, $agg): void {
            AiSupplierBarangStat::query()->where('supplier_id', $supplierId)->delete();

            if ($agg === []) {
                return;
            }

            $now = now();
            $insert = [];

            foreach ($agg as $barangId => $data) {
                $avgUrutan = $data['urutan_count'] > 0
                    ? round($data['urutan_sum'] / $data['urutan_count'], 2)
                    : null;

                $insert[] = [
                    'supplier_id' => $supplierId,
                    'barang_id' => $barangId,
                    'frekuensi' => $data['frekuensi'],
                    'total_jumlah' => $data['total_jumlah'],
                    'avg_urutan' => $avgUrutan,
                    'satuan_terakhir' => $data['satuan_terakhir'],
                    'harga_terakhir' => $data['harga_terakhir'],
                    'tanggal_terakhir' => $data['tanggal_terakhir'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            AiSupplierBarangStat::query()->insert($insert);
        });

        unset($this->cache[$supplierId]);
    }

    /** Bangun ulang SELURUH agregat dari awal (untuk command ai:learn). */
    public function refreshAll(): int
    {
        if (! $this->available()) {
            return 0;
        }

        $supplierIds = DB::table('pengeluaran_belanjas')
            ->whereNotNull('supplier_id')
            ->distinct()
            ->pluck('supplier_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        AiSupplierBarangStat::query()->delete();
        $this->cache = [];

        foreach ($supplierIds as $supplierId) {
            $this->refreshSupplier($supplierId);
        }

        return count($supplierIds);
    }

    /** Segarkan dari objek transaksi belanja (konvensi). */
    public function refreshFromPengeluaran(PengeluaranBelanja $expense): void
    {
        if ($expense->supplier_id) {
            $this->refreshSupplier((int) $expense->supplier_id);
        }
    }

    /**
     * Skor afinitas (0..N) supplier->barang. 0 bila belum pernah.
     */
    public function affinityScore(int $supplierId, int $barangId): float
    {
        $stat = $this->stat($supplierId, $barangId);

        if (! $stat) {
            return 0.0;
        }

        $frequencyCap = max(1, (int) config('ai.affinity.frequency_cap', 10));
        $recencyDays = max(1, (int) config('ai.affinity.recency_days', 60));

        $frequency = min((int) $stat->frekuensi, $frequencyCap);

        $recency = 0.0;

        if ($stat->tanggal_terakhir) {
            $days = $stat->tanggal_terakhir instanceof Carbon
                ? $stat->tanggal_terakhir->diffInDays(now())
                : Carbon::parse($stat->tanggal_terakhir)->diffInDays(now());

            $recency = exp(-((float) $days) / $recencyDays);
        }

        $quantity = log(1 + ((float) $stat->total_jumlah) / max(1, (int) $stat->frekuensi));

        // Pola urutan: item yang biasanya muncul lebih awal (avg_urutan kecil)
        // mendapat bonus lebih besar.
        $order = 0.0;

        if ($stat->avg_urutan !== null) {
            $order = 1.0 / (1.0 + (float) $stat->avg_urutan);
        }

        return (float) config('ai.affinity.weight_frequency', 1.0) * $frequency
            + (float) config('ai.affinity.weight_recency', 3.0) * $recency
            + (float) config('ai.affinity.weight_quantity', 0.5) * $quantity
            + (float) config('ai.affinity.weight_order', 2.0) * $order;
    }

    /**
     * Daftar barang yang biasanya diorder dari supplier, terurut afinitas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function suggestions(int $supplierId, int $limit = 15): array
    {
        if (! $this->available() || $supplierId < 1) {
            return [];
        }

        $limit = max(1, min($limit, 50));

        $stats = $this->statsFor($supplierId)->values()
            ->sortByDesc(fn (AiSupplierBarangStat $stat): float => $this->affinityScore($supplierId, (int) $stat->barang_id))
            ->take($limit)
            ->values();

        if ($stats->isEmpty()) {
            return [];
        }

        $names = Barang::query()
            ->whereKey($stats->pluck('barang_id')->all())
            ->pluck('nama_barang', 'id');

        return $stats->map(function (AiSupplierBarangStat $stat) use ($names): array {
            $barangId = (int) $stat->barang_id;

            return [
                'barang_id' => $barangId,
                'nama_barang' => (string) ($names[$barangId] ?? ('barang #'.$barangId)),
                'frekuensi' => (int) $stat->frekuensi,
                'avg_urutan' => $stat->avg_urutan !== null ? (float) $stat->avg_urutan : null,
                'satuan_terakhir' => $stat->satuan_terakhir,
                'harga_terakhir' => $stat->harga_terakhir,
                'terakhir' => $stat->tanggal_terakhir?->toDateString(),
            ];
        })->all();
    }

    /**
     * Pola urutan item yang biasa diorder dari supplier: barang yang biasanya
     * muncul lebih awal (avg_urutan kecil) lebih dulu. Hanya barang dengan
     * histori cukup (frekuensi >= 2) agar pola tidak menyesatkan.
     *
     * @return array<int, array{nama_barang: string, avg_urutan: float, frekuensi: int}>
     */
    public function orderPattern(int $supplierId, int $limit = 15): array
    {
        if (! $this->available() || $supplierId < 1) {
            return [];
        }

        $limit = max(1, min($limit, 50));

        $stats = $this->statsFor($supplierId)->values()
            ->filter(fn (AiSupplierBarangStat $stat): bool => $stat->avg_urutan !== null && (int) $stat->frekuensi >= 2)
            ->sortBy(fn (AiSupplierBarangStat $stat): float => (float) $stat->avg_urutan)
            ->take($limit)
            ->values();

        if ($stats->isEmpty()) {
            return [];
        }

        $names = Barang::query()
            ->whereKey($stats->pluck('barang_id')->all())
            ->pluck('nama_barang', 'id');

        return $stats->map(fn (AiSupplierBarangStat $stat): array => [
            'nama_barang' => (string) ($names[(int) $stat->barang_id] ?? ('barang #'.$stat->barang_id)),
            'avg_urutan' => (float) $stat->avg_urutan,
            'frekuensi' => (int) $stat->frekuensi,
        ])->all();
    }

    /**
     * Urutan barang_id sesuai pola yang dipelajari dari supplier (terurut).
     * Hanya barang dengan histori cukup (frekuensi >= 2) agar pola tidak menyesatkan.
     *
     * @return array<int, int>
     */
    public function patternBarangIds(int $supplierId, int $limit = 50): array
    {
        if (! $this->available() || $supplierId < 1) {
            return [];
        }

        $limit = max(1, min($limit, 100));

        return $this->statsFor($supplierId)->values()
            ->filter(fn (AiSupplierBarangStat $stat): bool => $stat->avg_urutan !== null && (int) $stat->frekuensi >= 2)
            ->sortBy(fn (AiSupplierBarangStat $stat): float => (float) $stat->avg_urutan)
            ->take($limit)
            ->map(fn (AiSupplierBarangStat $stat): int => (int) $stat->barang_id)
            ->values()
            ->all();
    }

    /**
     * Urutkan daftar item draft mengikuti pola urutan supplier. Item yang tak ada
     * di pola tetap kembali di belakang tanpa mengubah urutan relatifnya.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    public function orderItemsByPattern(int $supplierId, array $items): array
    {
        if ($supplierId < 1 || $items === []) {
            return $items;
        }

        $pola = $this->patternBarangIds($supplierId);

        if ($pola === []) {
            return $items;
        }

        $byId = [];
        $ids = [];

        foreach ($items as $item) {
            $id = (int) ($item['barang_id'] ?? 0);
            $byId[$id] = $item;
            $ids[] = $id;
        }

        $terurut = PolaUrutanBarang::urutkan($ids, $pola);

        return array_map(fn (int $id): array => $byId[$id], $terurut);
    }

    /**
     * Blok pola urutan untuk disuntik ke prompt. Kosong bila belum ada pola kuat.
     */
    public function orderPatternBlock(int $supplierId, string $supplierName): string
    {
        $pattern = $this->orderPattern($supplierId, 15);

        if ($pattern === []) {
            return '';
        }

        $lines = collect($pattern)->values()->map(function (array $item, int $index): string {
            return '  '.($index + 1).'. '.$item['nama_barang']
                .' (rata-rata baris ke-'.(round($item['avg_urutan'], 1) + 1).', '.$item['frekuensi'].'x)';
        })->implode("\n");

        return <<<PROMPT
POLA URUTAN ITEM DARI {$supplierName} (dari transaksi sebelumnya):
{$lines}
Saat menyusun draft belanja untuk {$supplierName}, urutkan item mengikuti pola di atas. Bila ada item pola yang TIDAK dibeli, cukup LOMPATI item itu — sisanya tetap urut seperti kebiasaan (mis. pola A,B,C,D tapi yang dibeli A,B,D → urutkan A,B,D). Bila pengguna menentukan urutan sendiri, ikuti urutan pengguna.
PROMPT;
    }

    /** Satuan terakhir untuk pasangan supplier+barang (bila ada). */
    public function lastUnit(int $supplierId, int $barangId): ?string
    {
        return $this->stat($supplierId, $barangId)?->satuan_terakhir;
    }

    /** Harga terakhir untuk pasangan supplier+barang (bila ada). */
    public function lastPrice(int $supplierId, int $barangId): ?int
    {
        $price = $this->stat($supplierId, $barangId)?->harga_terakhir;

        return $price !== null ? (int) $price : null;
    }

    public function supplierName(int $supplierId): ?string
    {
        return Supplier::query()->whereKey($supplierId)->value('nama_supplier');
    }

    private function stat(int $supplierId, int $barangId): ?AiSupplierBarangStat
    {
        return $this->statsFor($supplierId)->get($barangId);
    }

    /** @return Collection<int, AiSupplierBarangStat> */
    private function statsFor(int $supplierId)
    {
        if (! array_key_exists($supplierId, $this->cache)) {
            $this->cache[$supplierId] = $this->available()
                ? AiSupplierBarangStat::query()->where('supplier_id', $supplierId)->get()->keyBy('barang_id')
                : collect();
        }

        return $this->cache[$supplierId];
    }
}
