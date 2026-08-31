<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\BarangLokasi;
use App\Models\Lokasi;
use App\Services\Pdf\StockPdfDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockPdfExportService
{
    public function download(array $context = []): StreamedResponse
    {
        $path = $this->build($context);
        $fileName = 'laporan_stok_'.now('Asia/Jakarta')->format('Ymd_His').'.pdf';

        return response()->streamDownload(function () use ($path): void {
            try {
                $stream = fopen($path, 'rb');

                if ($stream === false) {
                    throw new RuntimeException('File PDF stok tidak dapat dibaca.');
                }

                fpassthru($stream);
                fclose($stream);
            } finally {
                @unlink($path);
            }
        }, $fileName, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function build(array $context = []): string
    {
        $report = $this->reportData($context);
        $directory = storage_path('app/temp-exports');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Folder sementara export tidak dapat dibuat.');
        }

        $path = $directory.'/stok-'.bin2hex(random_bytes(8)).'.pdf';
        $pdf = app(StockPdfDocument::class)->render($report['rows'], $report['context']);

        if (file_put_contents($path, $pdf, LOCK_EX) === false) {
            throw new RuntimeException('Gagal membuat file PDF stok.');
        }

        return $path;
    }

    /**
     * @return array{
     *     rows: array<int, array<string, int|string>>,
     *     context: array<string, int|string>
     * }
     */
    public function reportData(array $context = []): array
    {
        $warehouseFilters = collect($context['warehouses'] ?? [])
            ->filter(fn (mixed $warehouse): bool => is_array($warehouse) && filled($warehouse['keyword'] ?? null))
            ->values();
        $search = trim((string) ($context['search'] ?? ''));
        $stocks = $this->stockRows($warehouseFilters);
        $rows = [];
        $sequence = 1;

        $barangs = Barang::query()
            ->select(['id', 'kode_barang', 'nama_barang', 'satuan'])
            ->orderBy('nama_barang')
            ->orderBy('kode_barang')
            ->get();

        foreach ($barangs as $barang) {
            $barangStocks = $stocks->get((int) $barang->id, collect());

            if ($barangStocks->isEmpty()) {
                $rows[] = [
                    'sequence' => $sequence++,
                    'kode_barang' => (string) $barang->kode_barang,
                    'nama_barang' => (string) $barang->nama_barang,
                    'gudang' => $this->emptyWarehouseLabel($warehouseFilters),
                    'rak' => '-',
                    'stok_baik' => 0,
                    'stok_rusak' => 0,
                    'stok_hilang' => 0,
                    'stok' => 0,
                    'satuan' => (string) $barang->satuan,
                ];

                continue;
            }

            foreach ($barangStocks as $stock) {
                $rows[] = [
                    'sequence' => $sequence++,
                    'kode_barang' => (string) $barang->kode_barang,
                    'nama_barang' => (string) $barang->nama_barang,
                    'gudang' => (string) ($stock->lokasi?->nama_lokasi ?? '-'),
                    'rak' => (string) ($stock->posisiRak?->kode ?? 'Tanpa rak'),
                    'stok_baik' => (int) $stock->stok_baik,
                    'stok_rusak' => (int) $stock->stok_rusak,
                    'stok_hilang' => (int) $stock->stok_hilang,
                    'stok' => (int) $stock->stok,
                    'satuan' => (string) $barang->satuan,
                ];
            }
        }

        if ($search !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => $this->rowMatchesSearch($row, $search),
            ));

            foreach ($rows as $index => &$row) {
                $row['sequence'] = $index + 1;
            }
            unset($row);
        }

        $totalItems = count(array_unique(array_column($rows, 'kode_barang')));
        $totalStock = array_sum(array_map(static fn (array $row): int => (int) $row['stok'], $rows));

        return [
            'rows' => $rows,
            'context' => [
                'filter_description' => $this->filterDescription($warehouseFilters),
                'search' => $search,
                'total_items' => $totalItems,
                'total_stock' => $totalStock,
                'generated_at' => now('Asia/Jakarta')->locale('id')->translatedFormat('d F Y, H:i'),
            ],
        ];
    }

    /**
     * @param  Collection<int, array{label?: string, keyword?: string}>  $warehouseFilters
     * @return Collection<int, Collection<int, BarangLokasi>>
     */
    private function stockRows(Collection $warehouseFilters): Collection
    {
        return BarangLokasi::query()
            ->with([
                'lokasi:id,nama_lokasi,jenis_lokasi',
                'posisiRak:id,kode',
            ])
            ->whereHas('lokasi', function (Builder $query) use ($warehouseFilters): void {
                $query->where('jenis_lokasi', Lokasi::JENIS_GUDANG);

                if ($warehouseFilters->isNotEmpty()) {
                    $query->where(function (Builder $nameQuery) use ($warehouseFilters): void {
                        foreach ($warehouseFilters as $warehouse) {
                            $nameQuery->orWhere('nama_lokasi', 'like', '%'.$warehouse['keyword'].'%');
                        }
                    });
                }
            })
            ->orderBy('lokasi_id')
            ->get()
            ->groupBy(fn (BarangLokasi $stock): int => (int) $stock->barang_id);
    }

    /** @param Collection<int, array{label?: string, keyword?: string}> $warehouseFilters */
    private function emptyWarehouseLabel(Collection $warehouseFilters): string
    {
        if ($warehouseFilters->isEmpty()) {
            return 'Belum ditempatkan';
        }

        return 'Tidak ada stok di filter';
    }

    /** @param Collection<int, array{label?: string, keyword?: string}> $warehouseFilters */
    private function filterDescription(Collection $warehouseFilters): string
    {
        if ($warehouseFilters->isEmpty()) {
            return 'Semua gudang';
        }

        return $warehouseFilters
            ->pluck('label')
            ->filter()
            ->implode(' + ');
    }

    /** @param array<string, int|string> $row */
    private function rowMatchesSearch(array $row, string $search): bool
    {
        unset($row['sequence']);
        $haystack = implode(' ', array_map(static fn (mixed $value): string => (string) $value, $row));

        return mb_stripos($haystack, $search) !== false;
    }
}
