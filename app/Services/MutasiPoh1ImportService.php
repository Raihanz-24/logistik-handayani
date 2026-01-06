<?php

namespace App\Services;

use App\Models\KategoriProduk;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\Produk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MutasiPoh1ImportService
{
    /**
     * Import workbook POH1 (XLSX) - multi sheet Week...
     * - Produk dicocokkan berdasarkan nama_produk (sementara sesuai keputusan kamu)
     * - Kode produk dibuat dari No Excel + UID (sekali saat produk baru dibuat)
     * - Kolom KATEGORI dibaca dari sheet (posisi paling kanan header utama)
     * - Angka "231.230" / "231,230" dianggap "231" (ambil sebelum titik/koma)
     * - FORMULA Excel dibaca sesuai hasilnya (oldCalculatedValue -> calculatedValue -> formatted -> raw)
     * - Tujuan yang berisi 2+ lokasi akan dipecah menjadi beberapa mutasi
     * - Import tidak error walau stok kurang (tidak throw); stok tetap aman (tidak minus) & direkonsiliasi via Ending Stock
     *
     * Return summary:
     *  sheets, rows, mutasi_created, produk_upserted, lokasi_created, kategori_created, errors[]
     */
    public function import(string $absolutePath, string $gudangName, int $actorUserId, ?string $fileKey = null): array
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new \RuntimeException('File harus XLSX.');
        }

        if (!class_exists(IOFactory::class)) {
            throw new \RuntimeException('PhpSpreadsheet tidak tersedia. Server ini tidak bisa import XLSX.');
        }

        $fileKey = $fileKey ?: Str::upper(Str::slug(pathinfo($absolutePath, PATHINFO_FILENAME)));

        // Ambil nama sheet
        $readerProbe = IOFactory::createReaderForFile($absolutePath);
        $sheetNames = $readerProbe->listWorksheetNames($absolutePath);

        $weekSheetNames = [];
        foreach ($sheetNames as $sn) {
            $name = (string) $sn;
            if (preg_match('/^week/i', trim($name))) {
                $weekSheetNames[] = $name;
            }
        }

        if (empty($weekSheetNames)) {
            throw new \RuntimeException('Sheet "Week" tidak ditemukan pada file.');
        }

        $summary = [
            'sheets' => count($weekSheetNames),
            'rows' => 0,
            'mutasi_created' => 0,
            'produk_upserted' => 0,
            'lokasi_created' => 0,
            'kategori_created' => 0,
            'errors' => [],
        ];

        DB::transaction(function () use ($weekSheetNames, $absolutePath, $gudangName, $actorUserId, $fileKey, &$summary) {
            // Gudang utama
            $gudang = $this->getOrCreateLokasi($gudangName, $summary);

            // cache untuk mengurangi query
            $lokasiCache = [];
            $kategoriCache = [];

            /**
             * ✅ FIX PENTING:
             * Load workbook SEKALI full sheet (biar formula antar sheet kebaca benar).
             * File kamu kecil, aman.
             */
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(false);
            $spreadsheet = $reader->load($absolutePath);

            foreach ($weekSheetNames as $sheetName) {
                try {
                    $sheet = $spreadsheet->getSheetByName($sheetName);
                    if (!$sheet instanceof Worksheet) {
                        $summary['errors'][] = "Sheet {$sheetName}: tidak ditemukan saat load workbook.";
                        continue;
                    }

                    // Cari header row "No." & "Nama"
                    [$headerRow, $subHeaderRow] = $this->findHeaderRows($sheet);

                    // Ambil header & subheader array (scan 1..120 kolom)
                    $header = $this->readRow($sheet, $headerRow, 1, 120);
                    $subHeader = $this->readRow($sheet, $subHeaderRow, 1, 120);

                    $idxNo        = $this->findIndexExact($header, 'No.');
                    $idxNama      = $this->findIndexContains($header, 'Nama');
                    $idxSatuan    = $this->findIndexExact($header, 'Satuan');
                    $idxStockAwal = $this->findIndexContains($header, 'Stock Awal');
                    $idxBufDate   = $this->findIndexContains($header, 'Tanggal Buffer');
                    $idxBufQty    = $this->findIndexExactFirst($header, 'Jumlah'); // jumlah buffer (header row)
                    $idxEnding    = $this->findIndexContains($header, 'Ending Stock');
                    $idxKategori  = $this->findIndexContains($header, 'Kategori'); // kolom KATEGORI

                    if ($idxNama === null || $idxStockAwal === null || $idxEnding === null) {
                        $summary['errors'][] = "Sheet {$sheetName}: Header kolom utama tidak lengkap.";
                        continue;
                    }

                    // date pairs berdasarkan subHeader Lokasi|Jumlah dan header tanggal merge (carry forward)
                    $datePairs = $this->collectDatePairsFromMergedHeader(
                        header: $header,
                        subHeader: $subHeader,
                        startIndex: ($idxBufQty !== null ? $idxBufQty + 1 : $idxStockAwal + 1),
                        endIndex: ($idxEnding - 1)
                    );

                    $highestRow = (int) $sheet->getHighestDataRow();

                    for ($r = $subHeaderRow + 1; $r <= $highestRow; $r++) {
                        try {
                            $noVal = ($idxNo !== null) ? $this->cellValue($sheet, (int) $idxNo, $r) : null;
                            $no = $this->toIntNo($noVal);

                            $nama = trim((string) $this->cellValue($sheet, (int) $idxNama, $r));
                            if ($nama === '') {
                                continue;
                            }

                            $summary['rows']++;

                            $satuan = ($idxSatuan !== null) ? trim((string) $this->cellValue($sheet, (int) $idxSatuan, $r)) : '';
                            $katName = ($idxKategori !== null) ? trim((string) $this->cellValue($sheet, (int) $idxKategori, $r)) : '';

                            // ✅ qty akurat (formula antar sheet sudah bisa kebaca karena workbook full loaded)
                            $stockAwal = $this->qtyFromCell($sheet, (int) $idxStockAwal, $r);
                            $ending    = $this->qtyFromCell($sheet, (int) $idxEnding, $r);

                            // Upsert produk by nama
                            $produk = Produk::query()->where('nama_produk', $nama)->first();

                            if (!$produk) {
                                $kode = $this->makeKodeProduk($fileKey, $no);

                                $produk = Produk::create([
                                    'nama_produk' => $nama,
                                    'kode_produk' => $kode,
                                    'satuan' => $satuan !== '' ? $satuan : null,
                                    'deskripsi' => null,
                                    'harga_beli' => 0,
                                    'harga_jual' => 0,
                                    'barcode' => null,
                                    'gambar' => null,
                                ]);

                                $summary['produk_upserted']++;
                            } else {
                                $needsSave = false;

                                if ($satuan !== '' && empty($produk->satuan)) {
                                    $produk->satuan = $satuan;
                                    $needsSave = true;
                                }

                                if (empty($produk->kode_produk)) {
                                    $produk->kode_produk = $this->makeKodeProduk($fileKey, $no);
                                    $needsSave = true;
                                }

                                if ($produk->harga_beli === null) {
                                    $produk->harga_beli = 0;
                                    $needsSave = true;
                                }
                                if ($produk->harga_jual === null) {
                                    $produk->harga_jual = 0;
                                    $needsSave = true;
                                }

                                if ($needsSave) {
                                    $produk->save();
                                }
                            }

                            // attach kategori kalau ada
                            if ($katName !== '') {
                                $katKey = mb_strtolower($katName);
                                if (!isset($kategoriCache[$katKey])) {
                                    $kategori = $this->getOrCreateKategori($katName, $summary);
                                    $kategoriCache[$katKey] = $kategori->id;
                                }

                                DB::table('kategori_produk_produk')->updateOrInsert(
                                    [
                                        'kategori_produk_id' => $kategoriCache[$katKey],
                                        'produk_id' => $produk->id,
                                    ],
                                    [
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]
                                );
                            }

                            /**
                             * ✅ Set stok awal gudang PER BARIS sesuai report Excel
                             */
                            $this->setPivotStock($produk->id, $gudang->id, $stockAwal);

                            // Buffer Stock (masuk dari luar)
                            if ($idxBufDate !== null && $idxBufQty !== null) {
                                $bufTanggal = $this->parseDate($this->cellValue($sheet, (int) $idxBufDate, $r));
                                $bufJumlah  = $this->qtyFromCell($sheet, (int) $idxBufQty, $r);

                                if ($bufTanggal && $bufJumlah > 0) {
                                    $created = $this->createApprovedMutasiMasuk(
                                        produkId: $produk->id,
                                        gudangId: $gudang->id,
                                        tanggal: $bufTanggal,
                                        jumlah: $bufJumlah,
                                        actorUserId: $actorUserId,
                                        keterangan: 'Buffer Stock (import)',
                                        noRefSeed: "{$fileKey}|{$sheetName}|R{$r}|BUF"
                                    );
                                    if ($created) {
                                        $summary['mutasi_created']++;
                                    }
                                }
                            }

                            // Issued harian (keluar -> tujuan berdasarkan kolom)
                            foreach ($datePairs as $pair) {
                                $tgl = $pair['date'];
                                $idxLok = (int) $pair['idx_lokasi'];
                                $idxJml = (int) $pair['idx_jumlah'];

                                $lokRaw = trim((string) $this->cellValue($sheet, $idxLok, $r));
                                $qtyTotal = $this->qtyFromCell($sheet, $idxJml, $r);

                                if ($lokRaw === '' || $qtyTotal <= 0) {
                                    continue;
                                }

                                // ✅ pecah lokasi multi tujuan
                                $targets = $this->splitLokasiTargets($lokRaw, $qtyTotal);
                                if (empty($targets)) {
                                    continue;
                                }

                                foreach ($targets as $t) {
                                    $lokName = trim((string) ($t['lokasi'] ?? ''));
                                    $qty = (int) ($t['qty'] ?? 0);
                                    if ($lokName === '' || $qty <= 0) continue;

                                    $lokKey = mb_strtolower($lokName);
                                    if (!isset($lokasiCache[$lokKey])) {
                                        $tujuan = $this->getOrCreateLokasi($lokName, $summary);
                                        $lokasiCache[$lokKey] = $tujuan->id;
                                    }
                                    $lokasiTujuanId = (int) $lokasiCache[$lokKey];

                                    $created = $this->createApprovedMutasiKeluarTransfer(
                                        produkId: $produk->id,
                                        gudangAsalId: $gudang->id,
                                        lokasiTujuanId: $lokasiTujuanId,
                                        tanggal: $tgl,
                                        jumlah: $qty,
                                        actorUserId: $actorUserId,
                                        keterangan: 'Issued (import)',
                                        // seed harus beda per tujuan biar idempotensi aman
                                        noRefSeed: "{$fileKey}|{$sheetName}|R{$r}|ISS|{$tgl}|{$lokasiTujuanId}|Q{$qty}"
                                    );

                                    if ($created) {
                                        $summary['mutasi_created']++;
                                    }
                                }
                            }

                            // Rekonsiliasi stok gudang = Ending Stock
                            $this->setPivotStock($produk->id, $gudang->id, $ending);
                        } catch (\Throwable $e) {
                            $summary['errors'][] = "Sheet {$sheetName} baris Excel ke-{$r}: " . $e->getMessage();
                        }
                    }

                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                } catch (\Throwable $e) {
                    $summary['errors'][] = "Sheet {$sheetName}: " . $e->getMessage();
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        });

        return $summary;
    }

    /* =================== Helpers =================== */

    private function cellValue(Worksheet $sheet, int $col, int $row)
    {
        return $sheet->getCell([$col, $row])->getValue();
    }

    /**
     * FIX untuk angka + formula:
     * prioritas:
     * 1) oldCalculatedValue (cached dari Excel)
     * 2) calculatedValue (hitung)
     * 3) formattedValue (agar 231.230 / 231,230 kebaca 231)
     * 4) raw
     */
    private function qtyFromCell(Worksheet $sheet, int $col, int $row): int
    {
        $cell = $sheet->getCell([$col, $row]);
        $raw = $cell->getValue();

        if (is_string($raw) && isset($raw[0]) && $raw[0] === '=') {
            $old = $cell->getOldCalculatedValue();
            if ($old !== null && $old !== '') {
                return $this->toIntQty($old);
            }

            try {
                $calc = $cell->getCalculatedValue();
                if ($calc !== null && $calc !== '') {
                    return $this->toIntQty($calc);
                }
            } catch (\Throwable $e) {
                // fallback
            }
        }

        $formatted = $cell->getFormattedValue();
        if ($formatted !== null && $formatted !== '') {
            return $this->toIntQty($formatted);
        }

        return $this->toIntQty($raw);
    }

    private function readRow(Worksheet $sheet, int $row, int $fromCol, int $toCol): array
    {
        $out = [];
        for ($c = $fromCol; $c <= $toCol; $c++) {
            $out[$c] = $this->cellValue($sheet, $c, $row);
        }
        return $out;
    }

    private function findHeaderRows(Worksheet $sheet): array
    {
        $maxScan = min(60, (int) $sheet->getHighestDataRow());
        for ($r = 1; $r <= $maxScan; $r++) {
            $a = trim((string) $this->cellValue($sheet, 1, $r));
            $b = trim((string) $this->cellValue($sheet, 2, $r));

            if ($a === 'No.' && str_contains(mb_strtolower($b), 'nama')) {
                return [$r, $r + 1];
            }
        }

        throw new \RuntimeException('Header "No. / Nama Barang" tidak ditemukan.');
    }

    private function findIndexExact(array $row, string $exact): ?int
    {
        foreach ($row as $i => $v) {
            if (trim((string) $v) === $exact) {
                return (int) $i;
            }
        }
        return null;
    }

    private function findIndexExactFirst(array $row, string $exact): ?int
    {
        foreach ($row as $i => $v) {
            if (trim((string) $v) === $exact) {
                return (int) $i;
            }
        }
        return null;
    }

    private function findIndexContains(array $row, string $needle): ?int
    {
        $needle = mb_strtolower($needle);
        foreach ($row as $i => $v) {
            if (str_contains(mb_strtolower(trim((string) $v)), $needle)) {
                return (int) $i;
            }
        }
        return null;
    }

    private function collectDatePairsFromMergedHeader(array $header, array $subHeader, int $startIndex, int $endIndex): array
    {
        $pairs = [];
        $lastDate = null;

        $startIndex = max(1, $startIndex);
        $endIndex = max($startIndex, $endIndex);

        for ($i = $startIndex; $i <= $endIndex - 1; $i++) {
            $subA = mb_strtolower(trim((string) ($subHeader[$i] ?? '')));
            $subB = mb_strtolower(trim((string) ($subHeader[$i + 1] ?? '')));

            if (!str_contains($subA, 'lokasi') || !str_contains($subB, 'jumlah')) {
                continue;
            }

            $candidate = $header[$i] ?? null;
            $date = $this->parseDate($candidate);

            if (!$date && $lastDate) {
                $date = $lastDate;
            }

            if ($date) {
                $lastDate = $date;

                $pairs[] = [
                    'date' => $date,
                    'idx_lokasi' => $i,
                    'idx_jumlah' => $i + 1,
                ];
            }
        }

        return $pairs;
    }

    /**
     * ✅ Pecah tujuan yang mengandung 2+ lokasi
     * Support:
     * - "Bali 24 = 2, Bali 15 = 4"
     * - "Lombok 16 & 18" (angka kedua akan diwarisi prefix "Lombok")
     * - "A, B" / "A & B" / "A dan B" => qty dibagi rata dari qtyTotal
     */
    private function splitLokasiTargets(string $lokasiRaw, int $qtyTotal): array
    {
        $lokasiRaw = trim($lokasiRaw);
        if ($lokasiRaw === '' || $lokasiRaw === '-' || $qtyTotal <= 0) return [];

        $lokasiRaw = rtrim($lokasiRaw, " \t\n\r\0\x0B,");

        $targets = [];

        // Case 1: format "Nama=2, Nama2=4"
        if (preg_match_all('/([^=,;&\n]+?)\s*=\s*(\d+)/u', $lokasiRaw, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $name = trim($mm[1]);
                $q = (int) $mm[2];
                if ($name !== '' && $q > 0) {
                    $targets[] = ['lokasi' => $name, 'qty' => $q];
                }
            }
            return $targets;
        }

        // Case 2: split by &, dan, comma, /
        $parts = preg_split('/\s*(?:&|\/|,|\bdan\b)\s*/iu', $lokasiRaw);
        $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));

        if (count($parts) <= 1) {
            return [['lokasi' => $lokasiRaw, 'qty' => $qtyTotal]];
        }

        // Inherit prefix: "Lombok 16 & 18" => "Lombok 16", "Lombok 18"
        $first = $parts[0];
        $prefix = '';
        if (preg_match('/^(.*?)(\d+)\s*$/u', $first, $mm)) {
            $prefix = trim($mm[1]);
        }

        for ($i = 1; $i < count($parts); $i++) {
            if ($prefix !== '' && preg_match('/^\d+$/', $parts[$i])) {
                $parts[$i] = $prefix . ' ' . $parts[$i];
            }
        }

        // bagi qty total rata
        $n = count($parts);
        $base = intdiv($qtyTotal, $n);
        $rem = $qtyTotal % $n;

        for ($i = 0; $i < $n; $i++) {
            $q = $base + ($i < $rem ? 1 : 0);
            if ($q > 0) {
                $targets[] = ['lokasi' => $parts[$i], 'qty' => $q];
            }
        }

        return $targets;
    }

    /**
     * Angka "231.230" / "231,230" dianggap 231 (ambil sebelum titik/koma).
     */
    private function toIntQty($v): int
    {
        if ($v === null) return 0;

        if (is_int($v)) return $v;

        if (is_float($v) || is_numeric($v)) {
            return (int) floor((float) $v);
        }

        $s = trim((string) $v);
        if ($s === '' || $s === '-') return 0;

        $s = str_replace(["\xc2\xa0", ' '], '', $s);

        $parts = preg_split('/[.,]/', $s, 2);
        $front = $parts[0] ?? '0';

        $front = preg_replace('/[^\d\-]/', '', $front);

        if ($front === '' || $front === '-') return 0;

        return (int) $front;
    }

    private function toIntNo($v): ?int
    {
        if ($v === null) return null;

        if (is_int($v)) return $v;

        if (is_float($v) || is_numeric($v)) {
            return (int) floor((float) $v);
        }

        $s = trim((string) $v);
        if ($s === '') return null;

        $digits = preg_replace('/[^\d]/', '', $s);
        if ($digits === '') return null;

        return (int) $digits;
    }

    private function parseDate($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        if (is_numeric($v)) {
            $n = (float) $v;
            if ($n > 20000) {
                $dt = ExcelDate::excelToDateTimeObject($n);
                return $dt->format('Y-m-d');
            }
        }

        $s = trim((string) $v);
        if ($s === '') return null;

        $s = str_replace(['\\', '.'], '/', $s);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        $ts = strtotime($s);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return null;
    }

    private function makeKodeProduk(string $fileKey, ?int $no): string
    {
        $noPart = $no
            ? str_pad((string) $no, 6, '0', STR_PAD_LEFT)
            : str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

        $uid = strtoupper(Str::random(6));
        return "{$fileKey}-{$noPart}-{$uid}";
    }

    private function getOrCreateLokasi(string $nama, array &$summary): Lokasi
    {
        $nama = trim($nama);
        if ($nama === '') $nama = 'Lokasi';

        $lok = Lokasi::query()->where('nama_lokasi', $nama)->first();
        if ($lok) return $lok;

        $kode = strtoupper(Str::slug($nama, '_'));
        if ($kode === '') $kode = 'LOKASI';
        $kode = Str::limit($kode, 50, '');

        $kodeFinal = $kode;
        $n = 1;
        while (Lokasi::query()->where('kode_lokasi', $kodeFinal)->exists()) {
            $kodeFinal = Str::limit($kode . '_' . $n, 50, '');
            $n++;
        }

        $summary['lokasi_created']++;

        return Lokasi::create([
            'nama_lokasi' => $nama,
            'kode_lokasi' => $kodeFinal,
        ]);
    }

    private function getOrCreateKategori(string $nama, array &$summary): KategoriProduk
    {
        $nama = trim($nama);
        if ($nama === '') {
            $nama = 'Tanpa Kategori';
        }

        $slug = Str::slug($nama);

        $existing = KategoriProduk::query()->where('slug', $slug)->first();
        if ($existing) return $existing;

        $base = $slug ?: 'kategori';
        $slugFinal = $base;
        $n = 1;
        while (KategoriProduk::query()->where('slug', $slugFinal)->exists()) {
            $slugFinal = $base . '-' . $n;
            $n++;
        }

        $summary['kategori_created']++;

        return KategoriProduk::create([
            'nama' => $nama,
            'slug' => $slugFinal,
        ]);
    }

    private function setPivotStock(int $produkId, int $lokasiId, int $stok): void
    {
        DB::table('produk_lokasi')->updateOrInsert(
            ['produk_id' => $produkId, 'lokasi_id' => $lokasiId],
            [
                'stok' => max(0, $stok),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /* =================== MUTASI CREATORS (APPROVED) =================== */

    private function createApprovedMutasiMasuk(
        int $produkId,
        int $gudangId,
        string $tanggal,
        int $jumlah,
        int $actorUserId,
        string $keterangan,
        string $noRefSeed
    ): bool {
        $jumlah = max(0, $jumlah);
        if ($jumlah <= 0) return false;

        $noRef = 'IMP-IN-' . substr(sha1($noRefSeed), 0, 12);

        if (Mutasi::query()->where('no_ref', $noRef)->exists()) {
            return false;
        }

        $pivot = DB::table('produk_lokasi')
            ->where('produk_id', $produkId)
            ->where('lokasi_id', $gudangId)
            ->lockForUpdate()
            ->first();

        $stokAwal = (int) ($pivot->stok ?? 0);
        $stokAkhir = $stokAwal + $jumlah;

        DB::table('produk_lokasi')->updateOrInsert(
            ['produk_id' => $produkId, 'lokasi_id' => $gudangId],
            [
                'stok' => $stokAkhir,
                'updated_at' => now(),
                'created_at' => $pivot?->created_at ?? now(),
            ]
        );

        Mutasi::create([
            'tanggal' => $tanggal,
            'jenis_mutasi' => 'masuk',
            'jumlah' => $jumlah,
            'keterangan' => $keterangan,
            'no_ref' => $noRef,
            'status' => 'approved',

            'user_id' => $actorUserId,
            'produk_id' => $produkId,

            'lokasi_id' => $gudangId,
            'lokasi_tujuan_id' => null,

            'created_by' => $actorUserId,
            'approved_by' => $actorUserId,
            'approved_at' => now(),

            'stok_awal' => $stokAwal,
            'stok_akhir' => $stokAkhir,
        ]);

        return true;
    }

    private function createApprovedMutasiKeluarTransfer(
        int $produkId,
        int $gudangAsalId,
        int $lokasiTujuanId,
        string $tanggal,
        int $jumlah,
        int $actorUserId,
        string $keterangan,
        string $noRefSeed
    ): bool {
        $jumlah = max(0, $jumlah);
        if ($jumlah <= 0) return false;

        if ($lokasiTujuanId === $gudangAsalId) {
            return false;
        }

        $noRef = 'IMP-OUT-' . substr(sha1($noRefSeed), 0, 12);

        if (Mutasi::query()->where('no_ref', $noRef)->exists()) {
            return false;
        }

        $pivotAsal = DB::table('produk_lokasi')
            ->where('produk_id', $produkId)
            ->where('lokasi_id', $gudangAsalId)
            ->lockForUpdate()
            ->first();

        $stokAwal = (int) ($pivotAsal->stok ?? 0);

        // tidak throw; stok tidak boleh minus
        $stokAkhir = $stokAwal - $jumlah;
        if ($stokAkhir < 0) $stokAkhir = 0;

        DB::table('produk_lokasi')->updateOrInsert(
            ['produk_id' => $produkId, 'lokasi_id' => $gudangAsalId],
            [
                'stok' => $stokAkhir,
                'updated_at' => now(),
                'created_at' => $pivotAsal?->created_at ?? now(),
            ]
        );

        // lokasi tujuan tetap bertambah stok (sesuai desain tabel kamu sekarang)
        $pivotT = DB::table('produk_lokasi')
            ->where('produk_id', $produkId)
            ->where('lokasi_id', $lokasiTujuanId)
            ->lockForUpdate()
            ->first();

        $stokTujuanAwal = (int) ($pivotT->stok ?? 0);
        $stokTujuanAkhir = $stokTujuanAwal + $jumlah;

        DB::table('produk_lokasi')->updateOrInsert(
            ['produk_id' => $produkId, 'lokasi_id' => $lokasiTujuanId],
            [
                'stok' => $stokTujuanAkhir,
                'updated_at' => now(),
                'created_at' => $pivotT?->created_at ?? now(),
            ]
        );

        Mutasi::create([
            'tanggal' => $tanggal,
            'jenis_mutasi' => 'keluar',
            'jumlah' => $jumlah,
            'keterangan' => $keterangan,
            'no_ref' => $noRef,
            'status' => 'approved',

            'user_id' => $actorUserId,
            'produk_id' => $produkId,

            'lokasi_id' => $gudangAsalId,
            'lokasi_tujuan_id' => $lokasiTujuanId,

            'created_by' => $actorUserId,
            'approved_by' => $actorUserId,
            'approved_at' => now(),

            'stok_awal' => $stokAwal,
            'stok_akhir' => $stokAkhir,
        ]);

        return true;
    }
}
