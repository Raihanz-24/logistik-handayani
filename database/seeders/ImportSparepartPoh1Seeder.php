<?php

namespace Database\Seeders;

use App\Models\Mutasi;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ImportSparepartPoh1Seeder extends Seeder
{
    /**
     * ====== CONFIG ======
     */
    private string $sheetName  = 'Week  (4)';          // ganti kalau sheet name beda
    private string $gudangName = 'Gudang POH 1';       // nama lokasi gudang utama (stok asal/tujuan)
    private string $fileName   = 'sparepart_poh1.xlsx';// nama file excel di database/seeders/data/

    /**
     * Kolom fixed sesuai file excel kamu:
     * A: No, B: Nama Barang, C: Satuan, D: KATEGORI, E: Stock Awal, F: Tanggal Buffer Stock, G: Jumlah Buffer
     */
    private string $colNo         = 'A';
    private string $colNama       = 'B';
    private string $colSatuan     = 'C';
    private string $colKategori   = 'D';
    private string $colStockAwal  = 'E';
    private string $colBufferDate = 'F';
    private string $colBufferQty  = 'G';

    public function run(): void
    {
        $filePath = database_path('seeders/data/' . $this->fileName);

        if (! file_exists($filePath)) {
            $this->command?->error("File tidak ditemukan: {$filePath}");
            return;
        }

        // Pastikan tabel ada
        $tables = ['mutasis', 'produks', 'lokasis', 'produk_lokasi'];
        foreach ($tables as $t) {
            if (! Schema::hasTable($t)) {
                $this->command?->error("Tabel '{$t}' tidak ditemukan.");
                return;
            }
        }

        // Kolom table
        $mutasiCols = Schema::getColumnListing('mutasis');
        $produkCols = Schema::getColumnListing('produks');
        $lokasiCols = Schema::getColumnListing('lokasis');
        $pivotCols  = Schema::getColumnListing('produk_lokasi');

        // Validasi kolom minimal yang kita butuhkan
        if (!in_array('nama_produk', $produkCols)) {
            $this->command?->error("Kolom 'nama_produk' tidak ada di tabel 'produks'.");
            return;
        }
        if (!in_array('nama_lokasi', $lokasiCols)) {
            $this->command?->error("Kolom 'nama_lokasi' tidak ada di tabel 'lokasis'.");
            return;
        }
        if (!in_array('stok', $pivotCols)) {
            $this->command?->error("Kolom 'stok' tidak ada di tabel 'produk_lokasi'.");
            return;
        }

        // User untuk pencatat/approver
        $user = User::where('email', 'superadmin@example.com')->first()
            ?? User::orderBy('id')->first();

        if (! $user) {
            $this->command?->error("Tidak ada user. Buat minimal 1 user dulu.");
            return;
        }

        $dicatatOlehId = $user->id;
        $approvedById  = $user->id;

        // Pastikan gudang utama ada
        $gudangId = $this->getOrCreateLokasi($this->gudangName, $lokasiCols);

        // Load spreadsheet
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName($this->sheetName) ?? $spreadsheet->getActiveSheet();

        $rows = $sheet->toArray(null, true, true, true);

        // Cari row header: kolom B == "Nama Barang"
        [$headerRow, $subHeaderRow] = $this->findHeaderRows($rows);
        if (! $headerRow || ! $subHeaderRow) {
            $this->command?->error("Header 'Nama Barang' tidak ditemukan.");
            return;
        }

        // Cari kolom "Ending Stock"
        $endingCol = $this->findEndingStockCol($rows[$headerRow]);
        if (! $endingCol) {
            $this->command?->error("Kolom 'Ending Stock' tidak ditemukan.");
            return;
        }

        // Bangun daftar pasangan tanggal (Lokasi, Jumlah) dari H.. sebelum Ending Stock
        $datePairs = $this->buildDatePairs($rows[$headerRow], $endingCol);

        // Data dimulai setelah subheader
        $startRow = $subHeaderRow + 1;

        $madeMutasi = 0;
        $skippedOut = 0;

        DB::transaction(function () use (
            $rows,
            $startRow,
            $datePairs,
            $produkCols,
            $lokasiCols,
            $mutasiCols,
            $gudangId,
            $dicatatOlehId,
            $approvedById,
            &$madeMutasi,
            &$skippedOut
        ) {
            foreach ($rows as $rIndex => $row) {
                if ($rIndex < $startRow) continue;

                $namaBarang = trim((string) ($row[$this->colNama] ?? ''));
                if ($namaBarang === '') continue;

                // Produk
                $produkId = $this->getOrCreateProduk(
                    $namaBarang,
                    $row[$this->colSatuan] ?? null,
                    $row[$this->colKategori] ?? null,
                    $produkCols
                );

                // Set stok awal gudang (overwrite aman)
                $stockAwalGudang = (int) ($row[$this->colStockAwal] ?? 0);
                $this->upsertPivotStock($produkId, $gudangId, $stockAwalGudang);

                // Kumpulkan transaksi produk ini
                $tx = [];

                // Buffer Stock (mutasi masuk)
                $bufferDate = $this->parseExcelDateValue($row[$this->colBufferDate] ?? null);
                $bufferQty  = (int) ($row[$this->colBufferQty] ?? 0);

                if ($bufferDate && $bufferQty > 0) {
                    $tx[] = [
                        'tanggal' => $bufferDate->format('Y-m-d'),
                        'priority' => 0, // masuk dulu jika tanggal sama
                        'jenis' => 'masuk',
                        'jumlah' => $bufferQty,
                        'tujuan_lokasi_id' => null,
                        'keterangan' => 'Buffer Stock (Import Excel)',
                    ];
                }

                // Mutasi keluar per tanggal: pasangan (Lokasi, Jumlah)
                foreach ($datePairs as $pair) {
                    $tanggal = $pair['date']->format('Y-m-d');

                    $locText = trim((string) ($row[$pair['locCol']] ?? ''));
                    $qtyVal  = $row[$pair['qtyCol']] ?? null;

                    if ($qtyVal === null || $qtyVal === '') continue;

                    $qty = (int) $qtyVal;
                    if ($qty <= 0) continue;

                    // Lokasi bisa multi: "Cafe = 1, Sulawesi 21 = 1"
                    $parsed = $this->parseLokasiMulti($locText, $qty);

                    foreach ($parsed as $p) {
                        $lokasiName = $p['lokasi'];
                        $lokasiQty  = $p['qty'];
                        if ($lokasiQty <= 0) continue;

                        $tujuanId = $this->getOrCreateLokasi($lokasiName, $lokasiCols);

                        // pastikan pivot tujuan ada (minimal 0)
                        $this->upsertPivotStock($produkId, $tujuanId, $this->getPivotStock($produkId, $tujuanId));

                        $tx[] = [
                            'tanggal' => $tanggal,
                            'priority' => 1,
                            'jenis' => 'keluar',
                            'jumlah' => $lokasiQty,
                            'tujuan_lokasi_id' => $tujuanId,
                            'keterangan' => 'Issued (Import Excel)',
                        ];
                    }
                }

                if (empty($tx)) {
                    continue;
                }

                // Urutkan transaksi per produk berdasarkan tanggal
                usort($tx, function ($a, $b) {
                    $d = $a['tanggal'] <=> $b['tanggal'];
                    if ($d !== 0) return $d;
                    return ($a['priority'] ?? 0) <=> ($b['priority'] ?? 0);
                });

                // Apply transaksi satu per satu agar stok benar
                $seq = 1;
                foreach ($tx as $t) {
                    $tanggal = $t['tanggal'];
                    $jumlah  = (int) $t['jumlah'];

                    // Ambil stok gudang dengan lock
                    $stokAwal = $this->getPivotStockForUpdate($produkId, $gudangId);

                    if ($t['jenis'] === 'masuk') {
                        // masuk: tambah stok gudang
                        $stokAkhir = $stokAwal + $jumlah;
                        $this->upsertPivotStock($produkId, $gudangId, $stokAkhir);

                        $noRef = $this->makeNoRef("IMP-POH1", $tanggal, "MASUK", $produkId, $seq);

                        $this->createApprovedMutasiSafe(
                            $mutasiCols,
                            [
                                'tanggal' => $tanggal,
                                'jenis_mutasi' => 'masuk',
                                'jumlah' => $jumlah,
                                'keterangan' => $t['keterangan'] ?? null,
                                'no_ref' => $noRef,
                                'status' => 'approved',
                                'user_id' => $dicatatOlehId,
                                'produk_id' => $produkId,
                                'lokasi_id' => $gudangId,          // untuk masuk = gudang tujuan
                                'lokasi_tujuan_id' => null,
                                'created_by' => $dicatatOlehId,
                                'approved_by' => $approvedById,
                                'approved_at' => $tanggal . ' 23:59:59',
                                'stok_awal' => $stokAwal,
                                'stok_akhir' => $stokAkhir,
                            ]
                        );

                        $madeMutasi++;
                    } else {
                        // keluar: kurangi stok gudang, tambah stok tujuan
                        $tujuanId = (int) ($t['tujuan_lokasi_id'] ?? 0);

                        if ($tujuanId <= 0) {
                            // tidak ada tujuan -> skip
                            $skippedOut++;
                            $seq++;
                            continue;
                        }

                        if ($stokAwal < $jumlah) {
                            // stok kurang -> skip (supaya seeder tidak fail total)
                            $skippedOut++;
                            $seq++;
                            continue;
                        }

                        $stokAkhir = $stokAwal - $jumlah;
                        $this->upsertPivotStock($produkId, $gudangId, $stokAkhir);

                        // tambah stok tujuan (lock)
                        $stokTujuanAwal = $this->getPivotStockForUpdate($produkId, $tujuanId);
                        $this->upsertPivotStock($produkId, $tujuanId, $stokTujuanAwal + $jumlah);

                        $noRef = $this->makeNoRef("IMP-POH1", $tanggal, "KELUAR", $produkId, $seq);

                        $this->createApprovedMutasiSafe(
                            $mutasiCols,
                            [
                                'tanggal' => $tanggal,
                                'jenis_mutasi' => 'keluar',
                                'jumlah' => $jumlah,
                                'keterangan' => $t['keterangan'] ?? null,
                                'no_ref' => $noRef,
                                'status' => 'approved',
                                'user_id' => $dicatatOlehId,
                                'produk_id' => $produkId,
                                'lokasi_id' => $gudangId,          // untuk keluar = gudang asal
                                'lokasi_tujuan_id' => $tujuanId,
                                'created_by' => $dicatatOlehId,
                                'approved_by' => $approvedById,
                                'approved_at' => $tanggal . ' 23:59:59',
                                'stok_awal' => $stokAwal,
                                'stok_akhir' => $stokAkhir,
                            ]
                        );

                        $madeMutasi++;
                    }

                    $seq++;
                }
            }
        });

        $this->command?->info("✅ Import selesai.");
        $this->command?->info("Mutasi dibuat/diupdate: {$madeMutasi}");
        if ($skippedOut > 0) {
            $this->command?->warn("Mutasi keluar di-skip (stok kurang/tujuan kosong): {$skippedOut}");
        }
    }

    /**
     * =======================
     * HEADER PARSING
     * =======================
     */
    private function findHeaderRows(array $rows): array
    {
        $headerRow = null;
        foreach ($rows as $i => $row) {
            $val = trim((string)($row[$this->colNama] ?? ''));
            if (strcasecmp($val, 'Nama Barang') === 0) {
                $headerRow = $i;
                break;
            }
        }
        return [$headerRow, $headerRow ? $headerRow + 1 : null];
    }

    private function findEndingStockCol(array $headerRow): ?string
    {
        foreach ($headerRow as $col => $val) {
            if (trim((string)$val) === 'Ending Stock') {
                return $col;
            }
        }
        return null;
    }

    private function buildDatePairs(array $headerRow, string $endingCol): array
    {
        $pairs = [];

        // Di file kamu mulai dari H, pola: (Lokasi, Jumlah) per tanggal
        $col = 'H';
        while ($this->colToIndex($col) < $this->colToIndex($endingCol)) {
            $dateVal = $headerRow[$col] ?? null;
            $date = $this->parseExcelDateValue($dateVal);

            $locCol = $col;
            $qtyCol = $this->nextCol($col);

            if ($date) {
                $pairs[] = [
                    'date' => $date,
                    'locCol' => $locCol,
                    'qtyCol' => $qtyCol,
                ];
            }

            // lompat 2 kolom (Lokasi, Jumlah)
            $col = $this->nextCol($this->nextCol($col));
        }

        return $pairs;
    }

    /**
     * =======================
     * EXCEL VALUE PARSING
     * =======================
     */
    private function parseExcelDateValue($value): ?\DateTimeInterface
    {
        if ($value === null || $value === '') return null;

        if ($value instanceof \DateTimeInterface) return $value;

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject($value);
            } catch (\Throwable $e) {
                return null;
            }
        }

        $str = trim((string)$value);
        if ($str === '') return null;

        try {
            return new \DateTime($str);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return $text ?: '';
    }

    private function normalizeLokasi(string $name): string
    {
        $name = $this->normalizeText($name);
        $name = str_replace([';', "\t"], ['', ' '], $name);

        // perbaiki typo umum
        $name = str_ireplace('Su;lawesi', 'Sulawesi', $name);

        return $this->normalizeText($name);
    }

    /**
     * Lokasi cell bisa multi: "Cafe = 1, Sulawesi 21 = 1"
     */
    private function parseLokasiMulti(string $lokasiCell, int $qtyTotal): array
    {
        $lokasiCell = $this->normalizeLokasi($lokasiCell);

        if ($lokasiCell === '' || $lokasiCell === '-') {
            return [['lokasi' => 'Pemakaian', 'qty' => $qtyTotal]];
        }

        preg_match_all('/([^=,]+?)\s*=\s*(\d+)/', $lokasiCell, $m, PREG_SET_ORDER);
        if (!empty($m)) {
            $out = [];
            foreach ($m as $x) {
                $out[] = [
                    'lokasi' => $this->normalizeLokasi($x[1]),
                    'qty' => (int) $x[2],
                ];
            }
            return $out;
        }

        return [[
            'lokasi' => $lokasiCell,
            'qty' => $qtyTotal,
        ]];
    }

    /**
     * =======================
     * KODE GENERATOR (AMAN)
     * =======================
     */
    private function makeKode(string $prefix, string $name, string $table, string $column, int $maxLen = 30): string
    {
        $base = strtoupper(trim($name));
        $base = preg_replace('/[^A-Z0-9]+/', '_', $base);
        $base = trim($base, '_');
        if ($base === '') $base = $prefix;

        $base = substr($base, 0, $maxLen);
        $kode = $base;

        $i = 1;
        while (DB::table($table)->where($column, $kode)->exists()) {
            $suffix = '_' . $i;
            $kode = substr($base, 0, $maxLen - strlen($suffix)) . $suffix;
            $i++;
            if ($i > 999) {
                $kode = substr($base, 0, $maxLen - 8) . '_' . substr(uniqid(), -8);
                break;
            }
        }

        return $kode;
    }

    private function makeNoRef(string $prefix, string $tanggal, string $jenis, int $produkId, int $seq): string
    {
        // deterministik => idempotent
        return "{$prefix}-{$tanggal}-{$jenis}-{$produkId}-{$seq}";
    }

    /**
     * =======================
     * CREATE / GET MASTER
     * =======================
     */
    private function getOrCreateProduk(string $nama, $satuan, $kategori, array $produkCols): int
    {
        $nama = $this->normalizeText($nama);

        $existing = DB::table('produks')->where('nama_produk', $nama)->first();
        if ($existing) {
            // kalau ada kode_produk tapi kosong, isi
            if (in_array('kode_produk', $produkCols) && empty($existing->kode_produk)) {
                $kode = $this->makeKode('PRD', $nama, 'produks', 'kode_produk', 30);
                DB::table('produks')->where('id', $existing->id)->update([
                    'kode_produk' => $kode,
                    'updated_at' => now(),
                ]);
            }
            return (int) $existing->id;
        }

        $data = [
            'nama_produk' => $nama,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // optional columns
        if (in_array('satuan', $produkCols) && $satuan !== null) {
            $data['satuan'] = $this->normalizeText((string)$satuan);
        }
        if (in_array('kategori', $produkCols) && $kategori !== null) {
            $data['kategori'] = $this->normalizeText((string)$kategori);
        }

        // required code if exists
        if (in_array('kode_produk', $produkCols)) {
            $data['kode_produk'] = $this->makeKode('PRD', $nama, 'produks', 'kode_produk', 30);
        }

        // Tambahan: isi kolom NOT NULL tanpa default (kalau ada)
        $data = $this->fillRequiredColumns('produks', $produkCols, $data, [
            'kode_produk' => fn() => $data['kode_produk'] ?? $this->makeKode('PRD', $nama, 'produks', 'kode_produk', 30),
        ]);

        DB::table('produks')->insert($data);

        return (int) DB::table('produks')->where('nama_produk', $nama)->value('id');
    }

    private function getOrCreateLokasi(string $namaLokasi, array $lokasiCols): int
    {
        $namaLokasi = $this->normalizeLokasi($namaLokasi);

        $existing = DB::table('lokasis')->where('nama_lokasi', $namaLokasi)->first();
        if ($existing) {
            if (in_array('kode_lokasi', $lokasiCols) && empty($existing->kode_lokasi)) {
                $kode = $this->makeKode('LOK', $namaLokasi, 'lokasis', 'kode_lokasi', 30);
                DB::table('lokasis')->where('id', $existing->id)->update([
                    'kode_lokasi' => $kode,
                    'updated_at' => now(),
                ]);
            }
            return (int) $existing->id;
        }

        $data = [
            'nama_lokasi' => $namaLokasi,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (in_array('kode_lokasi', $lokasiCols)) {
            $data['kode_lokasi'] = $this->makeKode('LOK', $namaLokasi, 'lokasis', 'kode_lokasi', 30);
        }

        // Isi kolom wajib lain bila ada
        $data = $this->fillRequiredColumns('lokasis', $lokasiCols, $data, [
            'kode_lokasi' => fn() => $data['kode_lokasi'] ?? $this->makeKode('LOK', $namaLokasi, 'lokasis', 'kode_lokasi', 30),
        ]);

        DB::table('lokasis')->insert($data);

        return (int) DB::table('lokasis')->where('nama_lokasi', $namaLokasi)->value('id');
    }

    /**
     * =======================
     * PIVOT STOCK
     * =======================
     */
    private function getPivotStock(int $produkId, int $lokasiId): int
    {
        $row = DB::table('produk_lokasi')
            ->where('produk_id', $produkId)
            ->where('lokasi_id', $lokasiId)
            ->first();

        return (int) ($row->stok ?? 0);
    }

    private function getPivotStockForUpdate(int $produkId, int $lokasiId): int
    {
        $row = DB::table('produk_lokasi')
            ->where('produk_id', $produkId)
            ->where('lokasi_id', $lokasiId)
            ->lockForUpdate()
            ->first();

        return (int) ($row->stok ?? 0);
    }

    private function upsertPivotStock(int $produkId, int $lokasiId, int $stok): void
    {
        $existing = DB::table('produk_lokasi')
            ->where('produk_id', $produkId)
            ->where('lokasi_id', $lokasiId)
            ->first();

        DB::table('produk_lokasi')->updateOrInsert(
            ['produk_id' => $produkId, 'lokasi_id' => $lokasiId],
            [
                'stok' => $stok,
                'updated_at' => now(),
                'created_at' => $existing ? ($existing->created_at ?? now()) : now(),
            ]
        );
    }

    /**
     * =======================
     * CREATE MUTASI (SAFE)
     * =======================
     * Hanya isi kolom yang benar-benar ada di tabel mutasis.
     */
    private function createApprovedMutasiSafe(array $mutasiCols, array $payload): void
    {
        // Filter payload: hanya kolom yang ada di tabel
        $data = [];
        foreach ($payload as $k => $v) {
            if (in_array($k, $mutasiCols, true)) {
                $data[$k] = $v;
            }
        }

        // wajib minimal
        if (!isset($data['no_ref']) || !isset($data['tanggal']) || !isset($data['jenis_mutasi'])) {
            return;
        }

        // timestamps kalau ada
        if (in_array('updated_at', $mutasiCols) && !isset($data['updated_at'])) {
            $data['updated_at'] = now();
        }
        if (in_array('created_at', $mutasiCols) && !isset($data['created_at'])) {
            $data['created_at'] = now();
        }

        // updateOrCreate by no_ref agar idempotent
        Mutasi::updateOrCreate(
            ['no_ref' => $data['no_ref']],
            $data
        );
    }

    /**
     * =======================
     * FILL REQUIRED COLUMNS
     * =======================
     * Isi kolom NOT NULL tanpa default (selain id) dengan nilai aman.
     * - $generators: callback khusus per kolom (mis: kode_lokasi)
     */
    private function fillRequiredColumns(string $table, array $tableCols, array $data, array $generators = []): array
    {
        // Ambil kolom wajib dari INFORMATION_SCHEMA
        $required = $this->getRequiredNoDefaultColumns($table);

        foreach ($required as $col) {
            if (!in_array($col, $tableCols, true)) continue;
            if (array_key_exists($col, $data)) continue;

            if (isset($generators[$col]) && is_callable($generators[$col])) {
                $data[$col] = $generators[$col]();
                continue;
            }

            // fallback aman:
            // - string => "AUTO_xxx"
            // - int/decimal => 0
            // kita ambil tipe dari INFORMATION_SCHEMA juga
            $type = $this->getColumnDataType($table, $col);

            if ($type && preg_match('/int|decimal|float|double/', $type)) {
                $data[$col] = 0;
            } elseif ($type && preg_match('/date|time|year/', $type)) {
                $data[$col] = now();
            } else {
                $data[$col] = 'AUTO_' . strtoupper(substr(uniqid(), -8));
            }
        }

        return $data;
    }

    private function getRequiredNoDefaultColumns(string $table): array
    {
        $db = DB::getDatabaseName();

        $rows = DB::select("
            SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ", [$db, $table]);

        $required = [];
        foreach ($rows as $r) {
            $col = $r->COLUMN_NAME;

            // skip id auto increment
            if (str_contains(strtolower((string)$r->EXTRA), 'auto_increment')) {
                continue;
            }

            // wajib: NOT NULL dan tidak ada default
            if ((string)$r->IS_NULLABLE === 'NO' && $r->COLUMN_DEFAULT === null) {
                $required[] = $col;
            }
        }

        // timestamps biasanya juga NOT NULL tapi kita sudah isi di data awal
        return $required;
    }

    private function getColumnDataType(string $table, string $column): ?string
    {
        $db = DB::getDatabaseName();

        $row = DB::selectOne("
            SELECT DATA_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
            LIMIT 1
        ", [$db, $table, $column]);

        return $row?->DATA_TYPE ?? null;
    }

    /**
     * =======================
     * EXCEL COLUMN UTIL
     * =======================
     */
    private function nextCol(string $col): string
    {
        $col = strtoupper($col);
        $index = $this->colToIndex($col) + 1;

        $out = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $out = chr(65 + $mod) . $out;
            $index = intdiv($index - 1, 26);
        }
        return $out;
    }

    private function colToIndex(string $col): int
    {
        $col = strtoupper($col);
        $index = 0;
        for ($i = 0; $i < strlen($col); $i++) {
            $index = $index * 26 + (ord($col[$i]) - 64);
        }
        return $index;
    }
}
