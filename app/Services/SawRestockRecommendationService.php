<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\Mutasi;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SawRestockRecommendationService
{
    /**
     * @return array{
     *     start: CarbonInterface,
     *     end: CarbonInterface,
     *     weights: array<string, float>,
     *     recommendations: Collection<int, array<string, mixed>>
     * }
     */
    public function calculate(
        ?CarbonInterface $start = null,
        ?CarbonInterface $end = null,
        ?int $limit = null,
    ): array {
        [$start, $end] = $this->resolvePeriod($start, $end);
        $weights = $this->normalizedWeights();
        $limit ??= (int) config('saw-restock.limit', 5);

        $usageByBarang = Mutasi::query()
            ->select('barang_id')
            ->selectRaw('COUNT(*) as frekuensi_pemakaian')
            ->selectRaw('COALESCE(SUM(jumlah), 0) as jumlah_pemakaian')
            ->where('jenis_mutasi', 'keluar')
            ->where('status', 'approved')
            ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
            ->groupBy('barang_id')
            ->get()
            ->keyBy('barang_id');

        $stockByBarang = DB::table('barang_lokasi')
            ->join('lokasis', 'lokasis.id', '=', 'barang_lokasi.lokasi_id')
            ->where('lokasis.jenis_lokasi', Lokasi::JENIS_GUDANG)
            ->select('barang_lokasi.barang_id')
            ->selectRaw('COALESCE(SUM(stok), 0) as sisa_stok')
            ->groupBy('barang_lokasi.barang_id')
            ->pluck('sisa_stok', 'barang_lokasi.barang_id');

        $alternatives = Barang::query()
            ->orderBy('nama_barang')
            ->get(['id', 'kode_barang', 'nama_barang', 'satuan'])
            ->map(function (Barang $barang) use ($usageByBarang, $stockByBarang): array {
                $usage = $usageByBarang->get($barang->id);

                return [
                    'barang_id' => $barang->id,
                    'kode_barang' => $barang->kode_barang,
                    'nama_barang' => $barang->nama_barang,
                    'satuan' => $barang->satuan,
                    'frekuensi_pemakaian' => (int) ($usage?->frekuensi_pemakaian ?? 0),
                    'jumlah_pemakaian' => (int) ($usage?->jumlah_pemakaian ?? 0),
                    'sisa_stok' => (int) ($stockByBarang->get($barang->id, 0)),
                ];
            });

        $recommendations = $this->rank($alternatives, $limit, $weights);

        return compact('start', 'end', 'weights', 'recommendations');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $alternatives
     * @param  array<string, float>|null  $weights
     * @return Collection<int, array<string, mixed>>
     */
    public function rank(
        Collection $alternatives,
        ?int $limit = null,
        ?array $weights = null,
    ): Collection {
        if ($alternatives->isEmpty()) {
            return collect();
        }

        $weights ??= $this->normalizedWeights();
        $limit ??= (int) config('saw-restock.limit', 5);
        $maxFrequency = max(1, (int) $alternatives->max('frekuensi_pemakaian'));
        $maxQuantity = max(1, (int) $alternatives->max('jumlah_pemakaian'));

        // Penambahan 1 menjaga normalisasi cost tetap terdefinisi saat stok = 0.
        $minimumAdjustedStock = (int) $alternatives
            ->min(fn (array $alternative): int => $alternative['sisa_stok'] + 1);

        return $alternatives
            ->map(function (array $alternative) use (
                $maxFrequency,
                $maxQuantity,
                $minimumAdjustedStock,
                $weights,
            ): array {
                $normalizedFrequency = $alternative['frekuensi_pemakaian'] / $maxFrequency;
                $normalizedQuantity = $alternative['jumlah_pemakaian'] / $maxQuantity;
                $normalizedStock = $minimumAdjustedStock / ($alternative['sisa_stok'] + 1);

                $score = ($normalizedFrequency * $weights['frekuensi_pemakaian'])
                    + ($normalizedQuantity * $weights['jumlah_pemakaian'])
                    + ($normalizedStock * $weights['sisa_stok']);

                return $alternative + [
                    'normalisasi_frekuensi' => $normalizedFrequency,
                    'normalisasi_jumlah' => $normalizedQuantity,
                    'normalisasi_stok' => $normalizedStock,
                    'nilai_preferensi' => $score,
                ];
            })
            ->sort(function (array $first, array $second): int {
                return [
                    -$first['nilai_preferensi'],
                    $first['sisa_stok'],
                    -$first['jumlah_pemakaian'],
                    -$first['frekuensi_pemakaian'],
                    $first['nama_barang'],
                ] <=> [
                    -$second['nilai_preferensi'],
                    $second['sisa_stok'],
                    -$second['jumlah_pemakaian'],
                    -$second['frekuensi_pemakaian'],
                    $second['nama_barang'],
                ];
            })
            ->take(max(1, $limit))
            ->values()
            ->map(fn (array $recommendation, int $index): array => $recommendation + [
                'peringkat' => $index + 1,
            ]);
    }

    /**
     * @return array{CarbonInterface, CarbonInterface}
     */
    private function resolvePeriod(
        ?CarbonInterface $start,
        ?CarbonInterface $end,
    ): array {
        $end = $end
            ? Carbon::instance($end)->endOfDay()
            : now()->endOfDay();

        $start = $start
            ? Carbon::instance($start)->startOfDay()
            : $end->copy()
                ->subDays(max(1, (int) config('saw-restock.period_days', 30)) - 1)
                ->startOfDay();

        if ($start->greaterThan($end)) {
            return [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }

    /**
     * @return array<string, float>
     */
    private function normalizedWeights(): array
    {
        $weights = collect(config('saw-restock.weights', []))
            ->only(['frekuensi_pemakaian', 'jumlah_pemakaian', 'sisa_stok'])
            ->map(fn (mixed $weight): float => max(0, (float) $weight));

        if ($weights->count() !== 3 || $weights->sum() <= 0) {
            throw new InvalidArgumentException('Bobot SAW harus memuat tiga kriteria dengan total lebih dari nol.');
        }

        $total = (float) $weights->sum();

        return $weights
            ->map(fn (float $weight): float => $weight / $total)
            ->all();
    }
}
