<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\BarangLokasi;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\MutasiRak;
use App\Models\PosisiRak;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

class HistoricalStockService
{
    public function applyCurrentSnapshotToQuery(Builder $query): Builder
    {
        return $query
            ->select('barang_lokasi.*')
            ->selectRaw('barang_lokasi.posisi_rak_id AS posisi_rak_tampil_id')
            ->selectRaw('barang_lokasi.stok AS stok_tampil')
            ->selectRaw('barang_lokasi.stok_baik AS stok_baik_tampil')
            ->selectRaw('barang_lokasi.stok_rusak AS stok_rusak_tampil')
            ->selectRaw('barang_lokasi.stok_hilang AS stok_hilang_tampil');
    }

    public function applyTableSnapshotToQuery(Builder $query, mixed $asOfDate): Builder
    {
        $date = $this->parseAsOfDate($asOfDate)->format('Y-m-d');

        $query->select('barang_lokasi.*')
            ->selectRaw($this->historicalRackExpression().' AS posisi_rak_tampil_id', [$date]);

        foreach ([
            'stok_baik' => 'baik',
            'stok_rusak' => 'rusak',
            'stok_hilang' => 'hilang',
        ] as $column => $condition) {
            $query->selectRaw(
                $this->historicalConditionExpression($column, $condition).' AS '.$column.'_tampil',
                [$date],
            );
        }

        return $query->selectRaw($this->historicalTotalExpression().' AS stok_tampil', [$date]);
    }

    /**
     * @return array{
     *     rows: array<int, array<string, int|string>>,
     *     context: array<string, int|string>
     * }
     */
    public function reportData(array $context = []): array
    {
        $asOfDate = $this->parseAsOfDate($context['as_of_date'] ?? null);
        $warehouseFilters = collect($context['warehouses'] ?? [])
            ->filter(fn (mixed $warehouse): bool => is_array($warehouse) && filled($warehouse['keyword'] ?? null))
            ->values();
        $search = trim((string) ($context['search'] ?? ''));

        $warehouses = Lokasi::query()
            ->gudang()
            ->select(['id', 'nama_lokasi'])
            ->orderBy('nama_lokasi')
            ->get()
            ->keyBy(fn (Lokasi $warehouse): int => (int) $warehouse->id);
        $warehouseIds = $warehouses->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $selectedWarehouseIds = $this->selectedWarehouseIds($warehouses, $warehouseFilters);

        $state = $this->currentState($warehouseIds);
        $state = $this->rewindState(
            $state,
            $this->futureMutations($asOfDate, $warehouseIds),
            $this->futureRackMutations($asOfDate, $warehouseIds),
            $warehouseIds,
        );

        $positions = PosisiRak::query()
            ->select(['id', 'kode'])
            ->get()
            ->pluck('kode', 'id');
        $rows = $this->makeRows(
            $state,
            $warehouses,
            $positions,
            $selectedWarehouseIds,
            $warehouseFilters,
            $search,
        );

        return [
            'rows' => $rows,
            'context' => [
                'filter_description' => $this->filterDescription($warehouseFilters),
                'search' => $search,
                'total_items' => count(array_unique(array_column($rows, 'kode_barang'))),
                'total_stock' => array_sum(array_map(
                    static fn (array $row): int => (int) $row['stok'],
                    $rows,
                )),
                'as_of_date' => $asOfDate->format('Y-m-d'),
                'as_of_label' => $asOfDate->locale('id')->translatedFormat('d F Y'),
                'generated_at' => now('Asia/Jakarta')->locale('id')->translatedFormat('d F Y, H:i'),
            ],
        ];
    }

    /**
     * Membalik transaksi setelah tanggal rekap. Method ini sengaja murni agar
     * perhitungannya dapat diuji tanpa menulis atau mengubah data database.
     *
     * @param  array<string, array<string, int|null>>  $state
     * @param  iterable<int, array<string, mixed>>  $mutations
     * @param  iterable<int, array<string, mixed>>  $rackMutations
     * @param  array<int, int>  $warehouseIds
     * @return array<string, array<string, int|null>>
     */
    public function rewindState(
        array $state,
        iterable $mutations,
        iterable $rackMutations,
        array $warehouseIds,
    ): array {
        $warehouseLookup = array_fill_keys(array_map('intval', $warehouseIds), true);

        foreach ($mutations as $mutation) {
            $barangId = (int) ($mutation['barang_id'] ?? 0);
            $sourceId = (int) ($mutation['lokasi_id'] ?? 0);
            $destinationId = (int) ($mutation['lokasi_tujuan_id'] ?? 0);
            $quantity = (int) ($mutation['jumlah'] ?? 0);
            $type = (string) ($mutation['jenis_mutasi'] ?? '');

            if ($barangId <= 0 || $quantity <= 0) {
                continue;
            }

            if ($type === 'masuk' && isset($warehouseLookup[$sourceId])) {
                $this->adjust(
                    $state,
                    $barangId,
                    $sourceId,
                    $this->conditionColumn($mutation['kondisi_tujuan'] ?? 'baik'),
                    -$quantity,
                    $this->nullableInteger($mutation['posisi_rak_tujuan_id'] ?? null),
                );
            } elseif ($type === 'keluar') {
                $condition = $this->conditionColumn($mutation['kondisi_asal'] ?? 'baik');

                if (isset($warehouseLookup[$sourceId])) {
                    $this->adjust(
                        $state,
                        $barangId,
                        $sourceId,
                        $condition,
                        $quantity,
                        $this->nullableInteger($mutation['posisi_rak_asal_id'] ?? null),
                    );
                }

                if ($destinationId > 0 && isset($warehouseLookup[$destinationId])) {
                    $this->adjust(
                        $state,
                        $barangId,
                        $destinationId,
                        $condition,
                        -$quantity,
                        $this->nullableInteger($mutation['posisi_rak_tujuan_id'] ?? null),
                    );
                }
            } elseif ($type === 'perubahan_kondisi' && isset($warehouseLookup[$sourceId])) {
                $this->adjust(
                    $state,
                    $barangId,
                    $sourceId,
                    $this->conditionColumn($mutation['kondisi_asal'] ?? 'baik'),
                    $quantity,
                    $this->nullableInteger($mutation['posisi_rak_asal_id'] ?? null),
                );
                $this->adjust(
                    $state,
                    $barangId,
                    $sourceId,
                    $this->conditionColumn($mutation['kondisi_tujuan'] ?? 'baik'),
                    -$quantity,
                    $this->nullableInteger($mutation['posisi_rak_tujuan_id'] ?? null),
                );
            }
        }

        foreach ($rackMutations as $mutation) {
            $barangId = (int) ($mutation['barang_id'] ?? 0);
            $warehouseId = (int) ($mutation['lokasi_id'] ?? 0);
            $sourcePositionId = $this->nullableInteger($mutation['posisi_rak_asal_id'] ?? null);

            if ($barangId <= 0 || ! isset($warehouseLookup[$warehouseId]) || ! $sourcePositionId) {
                continue;
            }

            $key = $this->stateKey($barangId, $warehouseId);
            $state[$key] ??= $this->emptyState($barangId, $warehouseId, $sourcePositionId);
            $state[$key]['posisi_rak_id'] = $sourcePositionId;
        }

        foreach ($state as $key => &$stock) {
            foreach (['stok_baik', 'stok_rusak', 'stok_hilang'] as $column) {
                if ((int) $stock[$column] < 0) {
                    throw new RuntimeException(
                        'Riwayat stok tidak konsisten untuk barang/lokasi '.$key
                        .'. Periksa mutasi approved atau perubahan stok manual.',
                    );
                }
            }

            $stock['stok'] = (int) $stock['stok_baik']
                + (int) $stock['stok_rusak']
                + (int) $stock['stok_hilang'];
        }
        unset($stock);

        return $state;
    }

    public function parseAsOfDate(mixed $value): CarbonImmutable
    {
        $dateString = trim((string) ($value ?: now('Asia/Jakarta')->toDateString()));

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $dateString, 'Asia/Jakarta');
        } catch (\Throwable) {
            $date = null;
        }

        if (! $date || $date->format('Y-m-d') !== $dateString) {
            throw new InvalidArgumentException('Tanggal rekap stok tidak valid.');
        }

        if ($date->isAfter(now('Asia/Jakarta')->startOfDay())) {
            throw new InvalidArgumentException('Tanggal rekap stok tidak boleh melewati hari ini.');
        }

        return $date;
    }

    private function historicalConditionExpression(string $column, string $condition): string
    {
        return "barang_lokasi.{$column} - COALESCE((
            SELECT SUM(CASE
                WHEN history_mutations.jenis_mutasi = 'masuk'
                    AND history_mutations.lokasi_id = barang_lokasi.lokasi_id
                    AND COALESCE(history_mutations.kondisi_tujuan, 'baik') = '{$condition}'
                    THEN history_mutations.jumlah
                WHEN history_mutations.jenis_mutasi = 'keluar'
                    AND history_mutations.lokasi_id = barang_lokasi.lokasi_id
                    AND COALESCE(history_mutations.kondisi_asal, 'baik') = '{$condition}'
                    THEN -history_mutations.jumlah
                WHEN history_mutations.jenis_mutasi = 'keluar'
                    AND history_mutations.lokasi_tujuan_id = barang_lokasi.lokasi_id
                    AND COALESCE(history_mutations.kondisi_asal, 'baik') = '{$condition}'
                    THEN history_mutations.jumlah
                WHEN history_mutations.jenis_mutasi = 'perubahan_kondisi'
                    AND history_mutations.lokasi_id = barang_lokasi.lokasi_id
                    AND COALESCE(history_mutations.kondisi_asal, 'baik') = '{$condition}'
                    THEN -history_mutations.jumlah
                WHEN history_mutations.jenis_mutasi = 'perubahan_kondisi'
                    AND history_mutations.lokasi_id = barang_lokasi.lokasi_id
                    AND COALESCE(history_mutations.kondisi_tujuan, 'baik') = '{$condition}'
                    THEN history_mutations.jumlah
                ELSE 0
            END)
            FROM mutasis AS history_mutations
            WHERE history_mutations.barang_id = barang_lokasi.barang_id
                AND history_mutations.status = 'approved'
                AND history_mutations.tanggal > ?
        ), 0)";
    }

    private function historicalTotalExpression(): string
    {
        return "barang_lokasi.stok - COALESCE((
            SELECT SUM(CASE
                WHEN history_mutations.jenis_mutasi = 'masuk'
                    AND history_mutations.lokasi_id = barang_lokasi.lokasi_id
                    THEN history_mutations.jumlah
                WHEN history_mutations.jenis_mutasi = 'keluar'
                    AND history_mutations.lokasi_id = barang_lokasi.lokasi_id
                    THEN -history_mutations.jumlah
                WHEN history_mutations.jenis_mutasi = 'keluar'
                    AND history_mutations.lokasi_tujuan_id = barang_lokasi.lokasi_id
                    THEN history_mutations.jumlah
                ELSE 0
            END)
            FROM mutasis AS history_mutations
            WHERE history_mutations.barang_id = barang_lokasi.barang_id
                AND history_mutations.status = 'approved'
                AND history_mutations.tanggal > ?
        ), 0)";
    }

    private function historicalRackExpression(): string
    {
        return "COALESCE((
            SELECT history_racks.posisi_rak_asal_id
            FROM mutasi_raks AS history_racks
            WHERE history_racks.barang_id = barang_lokasi.barang_id
                AND history_racks.lokasi_id = barang_lokasi.lokasi_id
                AND history_racks.status = 'approved'
                AND history_racks.tanggal > ?
            ORDER BY history_racks.approved_at ASC, history_racks.id ASC
            LIMIT 1
        ), barang_lokasi.posisi_rak_id)";
    }

    /** @param array<int, int> $warehouseIds */
    private function currentState(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }

        $state = [];
        BarangLokasi::query()
            ->whereIn('lokasi_id', $warehouseIds)
            ->get([
                'barang_id', 'lokasi_id', 'posisi_rak_id', 'stok',
                'stok_baik', 'stok_rusak', 'stok_hilang',
            ])
            ->each(function (BarangLokasi $stock) use (&$state): void {
                $key = $this->stateKey((int) $stock->barang_id, (int) $stock->lokasi_id);
                $state[$key] = [
                    'barang_id' => (int) $stock->barang_id,
                    'lokasi_id' => (int) $stock->lokasi_id,
                    'posisi_rak_id' => $this->nullableInteger($stock->posisi_rak_id),
                    'stok' => (int) $stock->stok,
                    'stok_baik' => (int) $stock->stok_baik,
                    'stok_rusak' => (int) $stock->stok_rusak,
                    'stok_hilang' => (int) $stock->stok_hilang,
                ];
            });

        return $state;
    }

    /** @param array<int, int> $warehouseIds */
    private function futureMutations(CarbonInterface $asOfDate, array $warehouseIds): iterable
    {
        if ($warehouseIds === []) {
            return [];
        }

        return Mutasi::query()
            ->where('status', 'approved')
            ->whereDate('tanggal', '>', $asOfDate->format('Y-m-d'))
            ->where(function ($query) use ($warehouseIds): void {
                $query->whereIn('lokasi_id', $warehouseIds)
                    ->orWhereIn('lokasi_tujuan_id', $warehouseIds);
            })
            ->select([
                'id', 'tanggal', 'jenis_mutasi', 'barang_id', 'lokasi_id', 'lokasi_tujuan_id',
                'posisi_rak_asal_id', 'posisi_rak_tujuan_id', 'kondisi_asal', 'kondisi_tujuan', 'jumlah',
            ])
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->lazy(500)
            ->map(fn (Mutasi $mutation): array => $mutation->getAttributes());
    }

    /** @param array<int, int> $warehouseIds */
    private function futureRackMutations(CarbonInterface $asOfDate, array $warehouseIds): iterable
    {
        if ($warehouseIds === []) {
            return [];
        }

        return MutasiRak::query()
            ->where('status', MutasiRak::STATUS_APPROVED)
            ->whereDate('tanggal', '>', $asOfDate->format('Y-m-d'))
            ->whereIn('lokasi_id', $warehouseIds)
            ->select([
                'id', 'tanggal', 'approved_at', 'barang_id', 'lokasi_id',
                'posisi_rak_asal_id', 'posisi_rak_tujuan_id',
            ])
            ->orderByDesc('approved_at')
            ->orderByDesc('id')
            ->lazy(500)
            ->map(fn (MutasiRak $mutation): array => $mutation->getAttributes());
    }

    private function adjust(
        array &$state,
        int $barangId,
        int $warehouseId,
        string $conditionColumn,
        int $quantity,
        ?int $positionId,
    ): void {
        $key = $this->stateKey($barangId, $warehouseId);
        $state[$key] ??= $this->emptyState($barangId, $warehouseId, $positionId);
        $state[$key][$conditionColumn] = (int) $state[$key][$conditionColumn] + $quantity;

        if (! $state[$key]['posisi_rak_id'] && $positionId) {
            $state[$key]['posisi_rak_id'] = $positionId;
        }
    }

    /** @return array<string, int|null> */
    private function emptyState(int $barangId, int $warehouseId, ?int $positionId): array
    {
        return [
            'barang_id' => $barangId,
            'lokasi_id' => $warehouseId,
            'posisi_rak_id' => $positionId,
            'stok' => 0,
            'stok_baik' => 0,
            'stok_rusak' => 0,
            'stok_hilang' => 0,
        ];
    }

    private function conditionColumn(mixed $condition): string
    {
        return BarangLokasi::conditionColumn((string) $condition);
    }

    private function nullableInteger(mixed $value): ?int
    {
        return filled($value) ? (int) $value : null;
    }

    private function stateKey(int $barangId, int $warehouseId): string
    {
        return $barangId.':'.$warehouseId;
    }

    /**
     * @param  Collection<int, Lokasi>  $warehouses
     * @param  Collection<int, array{label?: string, keyword?: string}>  $filters
     * @return array<int, int>
     */
    private function selectedWarehouseIds(Collection $warehouses, Collection $filters): array
    {
        if ($filters->isEmpty()) {
            return $warehouses->keys()->map(fn (mixed $id): int => (int) $id)->all();
        }

        return $warehouses
            ->filter(function (Lokasi $warehouse) use ($filters): bool {
                return $filters->contains(fn (array $filter): bool => mb_stripos(
                    (string) $warehouse->nama_lokasi,
                    (string) $filter['keyword'],
                ) !== false);
            })
            ->keys()
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, array<string, int|null>>  $state
     * @param  Collection<int, Lokasi>  $warehouses
     * @param  Collection<int, string>  $positions
     * @param  array<int, int>  $selectedWarehouseIds
     * @param  Collection<int, array{label?: string, keyword?: string}>  $warehouseFilters
     * @return array<int, array<string, int|string>>
     */
    private function makeRows(
        array $state,
        Collection $warehouses,
        Collection $positions,
        array $selectedWarehouseIds,
        Collection $warehouseFilters,
        string $search,
    ): array {
        $selectedLookup = array_fill_keys($selectedWarehouseIds, true);
        $statesByBarang = collect($state)
            ->filter(fn (array $stock): bool => isset($selectedLookup[(int) $stock['lokasi_id']]))
            ->groupBy(fn (array $stock): int => (int) $stock['barang_id']);
        $rows = [];

        $barangs = Barang::query()
            ->select(['id', 'kode_barang', 'nama_barang', 'satuan'])
            ->orderBy('nama_barang')
            ->orderBy('kode_barang')
            ->get();

        foreach ($barangs as $barang) {
            $barangStocks = $statesByBarang->get((int) $barang->id, collect())
                ->sortBy(fn (array $stock): string => (string) ($warehouses->get((int) $stock['lokasi_id'])?->nama_lokasi ?? ''));

            if ($barangStocks->isEmpty()) {
                $rows[] = $this->row(
                    $barang,
                    $this->emptyWarehouseLabel($warehouseFilters),
                    '-',
                    null,
                );

                continue;
            }

            foreach ($barangStocks as $stock) {
                $rows[] = $this->row(
                    $barang,
                    (string) ($warehouses->get((int) $stock['lokasi_id'])?->nama_lokasi ?? '-'),
                    (string) ($positions->get((int) ($stock['posisi_rak_id'] ?? 0)) ?? 'Tanpa rak'),
                    $stock,
                );
            }
        }

        if ($search !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => $this->rowMatchesSearch($row, $search),
            ));
        }

        foreach ($rows as $index => &$row) {
            $row['sequence'] = $index + 1;
        }
        unset($row);

        return $rows;
    }

    /** @return array<string, int|string> */
    private function row(Barang $barang, string $warehouse, string $rack, ?array $stock): array
    {
        return [
            'sequence' => 0,
            'kode_barang' => (string) $barang->kode_barang,
            'nama_barang' => (string) $barang->nama_barang,
            'gudang' => $warehouse,
            'rak' => $rack,
            'stok_baik' => (int) ($stock['stok_baik'] ?? 0),
            'stok_rusak' => (int) ($stock['stok_rusak'] ?? 0),
            'stok_hilang' => (int) ($stock['stok_hilang'] ?? 0),
            'stok' => (int) ($stock['stok'] ?? 0),
            'satuan' => (string) $barang->satuan,
        ];
    }

    /** @param Collection<int, array{label?: string, keyword?: string}> $filters */
    private function emptyWarehouseLabel(Collection $filters): string
    {
        return $filters->isEmpty() ? 'Belum ditempatkan' : 'Tidak ada stok di filter';
    }

    /** @param Collection<int, array{label?: string, keyword?: string}> $filters */
    private function filterDescription(Collection $filters): string
    {
        return $filters->isEmpty()
            ? 'Semua gudang'
            : $filters->pluck('label')->filter()->implode(' + ');
    }

    /** @param array<string, int|string> $row */
    private function rowMatchesSearch(array $row, string $search): bool
    {
        unset($row['sequence']);
        $haystack = implode(' ', array_map(static fn (mixed $value): string => (string) $value, $row));

        return mb_stripos($haystack, $search) !== false;
    }
}
