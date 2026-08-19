<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\KategoriBarang;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class MutasiExcelImportService
{
    private const STATUS_PENDING = 'pending';

    private array $barangCache = [];

    private array $lokasiCache = [];

    private array $kategoriCache = [];

    public function import(string $path, int $actorId, string $statusMode = self::STATUS_PENDING): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('File import tidak ditemukan.');
        }

        $actor = User::query()->find($actorId);
        if (! $actor) {
            throw new RuntimeException('User import tidak valid.');
        }

        $sheets = $this->readWorkbook($path);
        $rows = $this->extractRows($sheets);

        if ($rows->isEmpty()) {
            throw new RuntimeException('Tidak ada data mutasi yang dapat dibaca dari file Excel.');
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        $rows->each(function (array $row) use ($actor, $statusMode, &$imported, &$skipped, &$errors): void {
            try {
                DB::transaction(function () use ($row, $actor, $statusMode, &$imported, &$skipped): void {
                    if (Mutasi::query()->where('no_ref', $row['no_ref'])->exists()) {
                        $skipped++;

                        return;
                    }

                    $barang = $this->firstOrCreateBarang(
                        name: $row['nama_barang'],
                        code: $row['kode_barang'] ?? null,
                        unit: $row['satuan'] ?? 'Pcs',
                        category: $row['kategori'] ?? null,
                    );

                    $warehouse = $this->firstOrCreateLokasi(
                        name: $row['gudang'],
                        type: Lokasi::JENIS_GUDANG,
                    );

                    $destination = null;
                    if ($row['jenis_mutasi'] === 'keluar') {
                        $destination = $this->firstOrCreateLokasi(
                            name: $row['lokasi_tujuan'],
                            type: $row['tujuan_is_gudang'] ? Lokasi::JENIS_GUDANG : Lokasi::JENIS_PEMAKAIAN,
                        );
                    }

                    $mutasi = Mutasi::query()->create([
                        'tanggal' => $row['tanggal']->toDateString(),
                        'jenis_mutasi' => $row['jenis_mutasi'],
                        'jumlah' => $row['jumlah'],
                        'keterangan' => $row['keterangan'],
                        'no_ref' => $row['no_ref'],
                        'status' => self::STATUS_PENDING,
                        'user_id' => $actor->id,
                        'created_by' => $actor->id,
                        'barang_id' => $barang->id,
                        'lokasi_id' => $warehouse->id,
                        'lokasi_tujuan_id' => $destination?->id,
                    ]);

                    if ($statusMode === 'approved') {
                        app(MutasiStockService::class)->approve($mutasi, $actor->id);
                    }

                    $imported++;
                });
            } catch (\Throwable $exception) {
                $errors[] = 'Baris '.$row['source_row'].': '.$exception->getMessage();
            }
        });

        return [
            'format' => $rows->first()['format'],
            'total_rows' => $rows->count(),
            'imported' => $imported,
            'skipped' => $skipped,
            'failed' => count($errors),
            'errors' => array_slice($errors, 0, 8),
        ];
    }

    private function extractRows(Collection $sheets): Collection
    {
        $exportRows = $this->extractExportRows($sheets);

        if ($exportRows->isNotEmpty()) {
            return $exportRows;
        }

        return $this->extractWeeklyIssuedRows($sheets);
    }

    private function extractExportRows(Collection $sheets): Collection
    {
        return $sheets->flatMap(function (array $sheet): array {
            $headerRowNumber = null;
            $headerMap = [];

            foreach ($sheet['rows'] as $rowNumber => $cells) {
                $normalized = collect($cells)
                    ->mapWithKeys(fn (string $value, string $column): array => [$column => $this->normalizeHeader($value)])
                    ->filter();

                if (
                    $normalized->contains('tanggal')
                    && $normalized->contains('nama_barang')
                    && $normalized->contains('jenis')
                    && $normalized->contains('jumlah')
                ) {
                    $headerRowNumber = (int) $rowNumber;
                    $headerMap = $normalized->flip()->all();

                    break;
                }
            }

            if (! $headerRowNumber) {
                return [];
            }

            $rows = [];
            foreach ($sheet['rows'] as $rowNumber => $cells) {
                if ((int) $rowNumber <= $headerRowNumber) {
                    continue;
                }

                $name = $this->cellByHeader($cells, $headerMap, 'nama_barang');
                $quantity = $this->parseQuantity($this->cellByHeader($cells, $headerMap, 'jumlah'));
                $date = $this->parseDate($this->cellByHeader($cells, $headerMap, 'tanggal'));
                $type = $this->normalizeMutationType($this->cellByHeader($cells, $headerMap, 'jenis'));

                if (! $name && $quantity <= 0) {
                    continue;
                }

                if (! $name || ! $date || ! $type || $quantity <= 0) {
                    throw new RuntimeException("Format export tidak valid pada sheet {$sheet['title']} baris {$rowNumber}.");
                }

                $source = $this->cleanLocationLabel($this->cellByHeader($cells, $headerMap, 'sumber_barang'));
                $destination = $this->cleanLocationLabel($this->cellByHeader($cells, $headerMap, 'lokasi_tujuan'));
                $warehouse = $type === 'masuk' ? $destination : $source;

                $rows[] = [
                    'format' => 'export',
                    'source_row' => "{$sheet['title']}!{$rowNumber}",
                    'tanggal' => $date,
                    'kode_barang' => $this->cleanNullable($this->cellByHeader($cells, $headerMap, 'kode_barang')),
                    'nama_barang' => $name,
                    'jenis_mutasi' => $type,
                    'jumlah' => $quantity,
                    'satuan' => $this->cleanNullable($this->cellByHeader($cells, $headerMap, 'satuan')) ?: 'Pcs',
                    'kategori' => null,
                    'gudang' => $warehouse ?: 'Warehouse POH 1',
                    'lokasi_tujuan' => $type === 'keluar' ? ($destination ?: 'Lokasi Pemakaian') : null,
                    'tujuan_is_gudang' => $type === 'keluar' && $this->isWarehouseLabel($destination),
                    'no_ref' => $this->reference(
                        $this->cellByHeader($cells, $headerMap, 'no_referensi'),
                        [$sheet['title'], $rowNumber, $name, $date->toDateString(), $type, $quantity],
                    ),
                    'keterangan' => 'Import dari format export Excel.',
                ];
            }

            return $rows;
        })->values();
    }

    private function extractWeeklyIssuedRows(Collection $sheets): Collection
    {
        return $sheets->flatMap(function (array $sheet, int $sheetIndex): array {
            $rows = $sheet['rows'];
            $title = $rows[1]['A'] ?? $sheet['title'];
            $warehouse = $this->warehouseFromTitle($title);
            $locationColumns = [];

            foreach (($rows[5] ?? []) as $column => $value) {
                if (Str::lower(trim($value)) !== 'lokasi') {
                    continue;
                }

                $date = $this->parseDate($rows[4][$column] ?? null);
                $quantityColumn = $this->nextColumn($column);

                if ($date) {
                    $locationColumns[$column] = [$date, $quantityColumn];
                }
            }

            if ($locationColumns === []) {
                return [];
            }

            $mutations = [];
            foreach ($rows as $rowNumber => $cells) {
                if ((int) $rowNumber < 6) {
                    continue;
                }

                $name = $this->cleanNullable($cells['B'] ?? null);
                if (! $name || preg_match('/^(nama barang|total|grand total)$/i', $name)) {
                    continue;
                }

                foreach ($locationColumns as $locationColumn => [$date, $quantityColumn]) {
                    foreach ($this->parseLocationQuantities($cells[$locationColumn] ?? null, $cells[$quantityColumn] ?? null) as $entry) {
                        $mutations[] = [
                            'format' => 'weekly-issued',
                            'source_row' => "{$sheet['title']}!{$rowNumber}",
                            'tanggal' => $date,
                            'kode_barang' => null,
                            'nama_barang' => $name,
                            'jenis_mutasi' => 'keluar',
                            'jumlah' => $entry['jumlah'],
                            'satuan' => $this->cleanNullable($cells['C'] ?? null) ?: 'Pcs',
                            'kategori' => $this->cleanNullable($cells['V'] ?? null),
                            'gudang' => $warehouse,
                            'lokasi_tujuan' => $entry['lokasi'],
                            'tujuan_is_gudang' => false,
                            'no_ref' => $this->reference(null, [
                                $sheetIndex,
                                $rowNumber,
                                $locationColumn,
                                $name,
                                $date->toDateString(),
                                $entry['lokasi'],
                                $entry['jumlah'],
                            ]),
                            'keterangan' => 'Import pemakaian barang dari file weekly issued POMI.',
                        ];
                    }
                }
            }

            return $mutations;
        })->values();
    }

    private function readWorkbook(string $path): Collection
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('File Excel tidak dapat dibuka. Pastikan file berformat .xlsx.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
            $relations = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));

            if (! $workbook || ! $relations) {
                throw new RuntimeException('Struktur workbook Excel tidak valid.');
            }

            $relationMap = [];
            foreach ($relations->Relationship as $relation) {
                $target = ltrim((string) $relation['Target'], '/');
                $relationMap[(string) $relation['Id']] = str_starts_with($target, 'xl/')
                    ? $target
                    : 'xl/'.$target;
            }

            $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $sheets = [];

            foreach ($workbook->sheets->sheet as $sheet) {
                $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $worksheetPath = $relationMap[(string) $attributes['id']] ?? null;
                $worksheet = $worksheetPath ? simplexml_load_string((string) $zip->getFromName($worksheetPath)) : false;

                if (! $worksheet) {
                    continue;
                }

                $sheets[] = [
                    'title' => (string) $sheet['name'],
                    'rows' => $this->readRows($worksheet, $sharedStrings),
                ];
            }

            return collect($sheets);
        } finally {
            $zip->close();
        }
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $sharedStrings = simplexml_load_string($xml);
        $strings = [];

        foreach ($sharedStrings->si as $item) {
            $text = '';

            if (isset($item->t)) {
                $text = (string) $item->t;
            } else {
                foreach ($item->r as $run) {
                    $text .= (string) $run->t;
                }
            }

            $strings[] = $this->normalizeWhitespace($text);
        }

        return $strings;
    }

    private function readRows(SimpleXMLElement $worksheet, array $sharedStrings): array
    {
        $rows = [];

        foreach ($worksheet->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $column = preg_replace('/\d+/', '', (string) $cell['r']);
                $cells[$column] = $this->cellValue($cell, $sharedStrings);
            }

            $rows[(int) $row['r']] = $cells;
        }

        return $rows;
    }

    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];
        $value = isset($cell->v) ? (string) $cell->v : '';

        if ($type === 's' && $value !== '') {
            return $sharedStrings[(int) $value] ?? $value;
        }

        if ($type === 'inlineStr') {
            return $this->normalizeWhitespace((string) $cell->is->t);
        }

        return $this->normalizeWhitespace($value);
    }

    private function firstOrCreateBarang(string $name, ?string $code, string $unit, ?string $category): Barang
    {
        $key = $this->normalizeKey($name);

        if (isset($this->barangCache[$key])) {
            return $this->barangCache[$key];
        }

        $barang = $code
            ? Barang::query()->where('kode_barang', $code)->first()
            : null;

        $barang ??= Barang::query()->get()
            ->first(fn (Barang $item): bool => $this->normalizeKey($item->nama_barang) === $key);

        $payload = [
            'nama_barang' => $name,
            'satuan' => $unit ?: 'Pcs',
            'deskripsi' => 'Dibuat otomatis dari import mutasi Excel.',
            'updated_at' => now(),
        ];

        $categoryModel = $this->firstOrCreateKategori($category);

        if (Schema::hasColumn('barangs', 'kategori') && $category) {
            $payload['kategori'] = $category;
        }

        if (Schema::hasColumn('barangs', 'kategori_barang_id') && $categoryModel) {
            $payload['kategori_barang_id'] = $categoryModel->id;
        }

        if ($barang) {
            DB::table('barangs')->whereKey($barang->id)->update($payload);
            $barang = Barang::query()->findOrFail($barang->id);
        } else {
            $code ??= $this->uniqueBarangCode($name);
            $id = DB::table('barangs')->insertGetId($payload + [
                'kode_barang' => $code,
                'created_at' => now(),
            ]);
            $barang = Barang::query()->findOrFail($id);
        }

        if ($categoryModel && Schema::hasTable('barang_kategori_barang')) {
            DB::table('barang_kategori_barang')->insertOrIgnore([
                'barang_id' => $barang->id,
                'kategori_barang_id' => $categoryModel->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->barangCache[$key] = $barang;
    }

    private function firstOrCreateKategori(?string $name): ?KategoriBarang
    {
        $name = $this->cleanNullable($name);

        if (! $name || $name === '-') {
            return null;
        }

        $key = $this->normalizeKey($name);

        if (isset($this->kategoriCache[$key])) {
            return $this->kategoriCache[$key];
        }

        return $this->kategoriCache[$key] = KategoriBarang::query()->firstOrCreate(
            ['slug' => Str::slug($name)],
            ['nama' => $name],
        );
    }

    private function firstOrCreateLokasi(string $name, string $type): Lokasi
    {
        $name = $this->cleanLocationLabel($name) ?: 'Lokasi Pemakaian';
        $key = $this->normalizeKey($type.'|'.$name);

        if (isset($this->lokasiCache[$key])) {
            return $this->lokasiCache[$key];
        }

        $lokasi = Lokasi::query()->get()
            ->first(fn (Lokasi $item): bool => $this->normalizeKey($item->nama_lokasi) === $this->normalizeKey($name));

        if ($lokasi) {
            if ($type === Lokasi::JENIS_GUDANG && ! $lokasi->isGudang()) {
                $lokasi->update(['jenis_lokasi' => Lokasi::JENIS_GUDANG]);
            }

            return $this->lokasiCache[$key] = $lokasi->fresh();
        }

        return $this->lokasiCache[$key] = Lokasi::query()->create([
            'nama_lokasi' => Str::title($name),
            'kode_lokasi' => $this->uniqueLokasiCode($name, $type),
            'jenis_lokasi' => $type,
            'alamat' => $type === Lokasi::JENIS_GUDANG
                ? 'PT ISS Indonesia Area Paiton Energy'
                : 'Area pemakaian Paiton Energy',
            'keterangan' => 'Dibuat otomatis dari import mutasi Excel.',
        ]);
    }

    private function parseLocationQuantities(?string $locationCell, ?string $quantityCell): array
    {
        $locationCell = $this->cleanNullable($locationCell);
        $quantity = $this->parseQuantity($quantityCell);

        if (! $locationCell) {
            return [];
        }

        $entries = [];
        foreach (preg_split('/[,;]+/', $locationCell) as $part) {
            $part = $this->normalizeWhitespace($part);

            if (! $part) {
                continue;
            }

            if (preg_match('/^(.*?)\s*=\s*([0-9]+(?:[.,][0-9]+)?)$/', $part, $match)) {
                $entries[] = [
                    'lokasi' => $this->cleanLocationLabel($match[1]),
                    'jumlah' => $this->parseQuantity($match[2]),
                ];

                continue;
            }

            if ($quantity > 0) {
                $entries[] = [
                    'lokasi' => $this->cleanLocationLabel($part),
                    'jumlah' => $quantity,
                ];
            }
        }

        return array_values(array_filter(
            $entries,
            fn (array $entry): bool => filled($entry['lokasi']) && $entry['jumlah'] > 0,
        ));
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        $value = $this->cleanNullable($value);

        if (! $value || $value === '-') {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $value));
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd M Y', 'd F Y'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $value);
            } catch (\Throwable) {
                //
            }
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeMutationType(?string $value): ?string
    {
        $value = Str::lower($this->cleanNullable($value) ?? '');

        return match ($value) {
            'masuk', 'in', 'barang masuk' => 'masuk',
            'keluar', 'out', 'barang keluar' => 'keluar',
            default => null,
        };
    }

    private function normalizeHeader(string $value): ?string
    {
        $key = Str::of($value)->lower()->trim()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        return match ($key) {
            'tanggal' => 'tanggal',
            'kode_barang' => 'kode_barang',
            'nama_barang' => 'nama_barang',
            'jenis' => 'jenis',
            'jumlah' => 'jumlah',
            'satuan' => 'satuan',
            'sumber_barang' => 'sumber_barang',
            'lokasi_tujuan', 'tujuan' => 'lokasi_tujuan',
            'no_referensi', 'no_ref', 'nomor_referensi' => 'no_referensi',
            'status' => 'status',
            default => null,
        };
    }

    private function cellByHeader(array $cells, array $headerMap, string $header): ?string
    {
        $column = $headerMap[$header] ?? null;

        return $column ? ($cells[$column] ?? null) : null;
    }

    private function parseQuantity(mixed $value): int
    {
        $value = str_replace(',', '.', $this->cleanNullable($value) ?? '');

        return max(0, (int) floor((float) $value));
    }

    private function reference(?string $value, array $fallbackParts): string
    {
        $value = $this->cleanNullable($value);

        if ($value && $value !== '-') {
            return $value;
        }

        return 'IMP-'.substr(sha1(implode('|', $fallbackParts)), 0, 16);
    }

    private function warehouseFromTitle(string $title): string
    {
        $title = Str::lower($title);

        if (str_contains($title, 'poh 2')) {
            return 'Warehouse POH 2';
        }

        return 'Warehouse POH 1';
    }

    private function isWarehouseLabel(?string $value): bool
    {
        $value = Str::lower($value ?? '');

        return str_contains($value, 'gudang') || str_contains($value, 'warehouse');
    }

    private function cleanLocationLabel(?string $value): ?string
    {
        $value = $this->cleanNullable($value);

        if (! $value || $value === '-') {
            return null;
        }

        return $this->normalizeWhitespace(preg_replace('/\s*\((Gudang|Lokasi Pemakaian|Lokasi)\)\s*$/i', '', $value) ?? $value);
    }

    private function cleanNullable(mixed $value): ?string
    {
        $value = $this->normalizeWhitespace((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function normalizeKey(string $value): string
    {
        return Str::of($value)->lower()->replaceMatches('/\s+/', ' ')->trim()->toString();
    }

    private function nextColumn(string $column): string
    {
        $number = 0;
        foreach (str_split($column) as $char) {
            $number = ($number * 26) + (ord($char) - 64);
        }

        $number++;
        $letters = '';

        while ($number > 0) {
            $number--;
            $letters = chr(65 + ($number % 26)).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }

    private function uniqueBarangCode(string $name): string
    {
        $base = 'BRG-'.Str::upper(substr(Str::slug($name, ''), 0, 10));
        $base = $base === 'BRG-' ? 'BRG-IMPORT' : $base;
        $code = $base;
        $counter = 1;

        while (Barang::query()->where('kode_barang', $code)->exists()) {
            $code = $base.'-'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
            $counter++;
        }

        return $code;
    }

    private function uniqueLokasiCode(string $name, string $type): string
    {
        $prefix = $type === Lokasi::JENIS_GUDANG ? 'GDG' : 'LKP';
        $base = $prefix.'-'.Str::upper(substr(Str::slug($name, ''), 0, 12));
        $code = $base ?: $prefix.'-IMPORT';
        $counter = 1;

        while (Lokasi::query()->where('kode_lokasi', $code)->exists()) {
            $code = $base.'-'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
            $counter++;
        }

        return $code;
    }
}
