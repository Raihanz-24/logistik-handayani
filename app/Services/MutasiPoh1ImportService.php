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
     * - FORMULA Excel dibaca sesuai hasilnya (oldCalculatedValue -> calculatedValue -> formatted)
     * - Tidak throw "stok tidak cukup" agar import tidak gagal; stok tetap direkonsiliasi via Ending Stock
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

        // fileKey untuk idempotensi no_ref (kalau tidak dikirim, pakai nama file)
        $fileKey = $fileKey ?: Str::upper(Str::slug(pathinfo($absolutePath, PATHINFO_FILENAME)));

        // Ambil nama sheet tanpa load seluruh workbook
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

            foreach ($weekSheetNames as $sheetName) {
                try {
                    // Load 1 sheet saja (hemat memory)
                    $reader = IOFactory::createReaderForFile($absolutePath);

                    // ✅ penting: jangan dataOnly, supaya formula/format lebih stabil
                    $reader->setReadDataOnly(false);
                    $reader->setLoadSheetsOnly([$sheetName]);

                    $spreadsheet = $reader->load($absolutePath);
                    $sheet = $spreadsheet->getActiveSheet(); /** @var Worksheet $sheet */

                    // Cari header row "No." & "Nama"
                    [$headerRow, $subHeaderRow] = $this->findHeaderRows($sheet);

                    // Ambil header & subheader array (cukup scan 1..120 kolom biar aman kalau kolom melebar)
                    $header = $this->readRow($sheet, $headerRow, 1, 120);
                    $subHeader = $this->readRow($sheet, $subHeaderRow, 1, 120);

                    $idxNo        = $this->findIndexExact($header, 'No.');
                    $idxNama      = $this->findIndexContains($header, 'Nama');
                    $idxSatuan    = $this->findIndexExact($header, 'Satuan');
                    $idxStockAwal = $this->findIndexContains($header, 'Stock Awal');
                    $idxBufDate   = $this->findIndexContains($header, 'Tanggal Buffer');
                    $idxBufQty    = $this->findIndexExactFirst($header, 'Jumlah'); // jumlah buffer (header row)
                    $idxEnding    = $this->findIndexContains($header, 'Ending Stock');
                    $idxKategori  = $this->findIndexContains($header, 'Kategori'); // kolom KATEGORI (paling kanan header utama)

                    if ($idxNama === null || $idxStockAwal === null || $idxEnding === null) {
                        $summary['errors'][] = "Sheet {$sheetName}: Header kolom utama tidak lengkap.";
                        $spreadsheet->disconnectWorksheets();
                        unset($spreadsheet);
                        continue;
                    }

                    // date pairs berdasarkan subHeader Lokasi|Jumlah dan header tanggal merge (carry forward)
                    $datePairs = $this->collectDatePairsFromMergedHeader(
                        header: $header,
                        subHeader: $subHeader,
                        startIndex: ($idxBufQty !== null ? $idxBufQty + 1 : $idxStockAwal + 1),
                        endIndex: ($idxEnding - 1)
                    );

                    // Proses row data mulai setelah subHeader
                    $highestRow = (int) $sheet->getHighestDataRow();

                    for ($r = $subHeaderRow + 1; $r <= $highestRow; $r++) {
                        try {
                            $noVal = ($idxNo !== null) ? $this->cellValue($sheet, (int) $idxNo, $r) : null;
                            $no = $this->toIntNo($noVal); // untuk kode produk

                            $nama = trim((string) $this->cellValue($sheet, (int) $idxNama, $r));
                            if ($nama === '') {
                                continue;
                            }

                            $summary['rows']++;

                            $satuan = ($idxSatuan !== null) ? trim((string) $this->cellValue($sheet, (int) $idxSatuan, $r)) : '';
                            $katName = ($idxKategori !== null) ? trim((string) $this->cellValue($sheet, (int) $idxKategori, $r)) : '';

                            // ✅ FIX: baca qty dari cell (dukung formula + potong koma/titik)
                            $stockAwal = $this->qtyFromCell($sheet, (int) $idxStockAwal, $r);
                            $ending    = $this->qtyFromCell($sheet, (int) $idxEnding, $r);

                            // Upsert produk by nama (sesuai keputusan kamu saat ini)
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
                             * ✅ PENTING: Set stok awal gudang PER BARIS.
                             * Karena Excel report memang punya Stock Awal per baris/per periode.
                             * Ini mencegah kasus stok kebaca 0 lalu nyangkut.
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

                                $lokName = trim((string) $this->cellValue($sheet, $idxLok, $r));
                                $qty = $this->qtyFromCell($sheet, $idxJml, $r);

                                if ($lokName === '' || $qty <= 0) {
                                    continue;
                                }

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
                                    noRefSeed: "{$fileKey}|{$sheetName}|R{$r}|ISS|{$tgl}|{$lokasiTujuanId}"
                                );
                                if ($created) {
                                    $summary['mutasi_created']++;
                                }
                            }

                            // Rekonsiliasi stok gudang = Ending Stock (source of truth)
                            $this->setPivotStock($produk->id, $gudang->id, $ending);
                        } catch (\Throwable $e) {
                            $summary['errors'][] = "Sheet {$sheetName} baris Excel ke-{$r}: " . $e->getMessage();
                        }
                    }

                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                } catch (\Throwable $e) {
                    $summary['errors'][] = "Sheet {$sheetName}: " . $e->getMessage();
                }
            }
        });

        return $summary;
    }

    /* =================== Helpers =================== */

    private function cellValue(Worksheet $sheet, int $col, int $row)
    {
        return $sheet->getCell([$col, $row])->getValue();
    }

    /**
     * ✅ FIX utama untuk angka + formula:
     * prioritas:
     * 1) oldCalculatedValue (cached dari Excel, paling stabil)
     * 2) calculatedValue (hitung, kalau memungkinkan)
     * 3) formattedValue (agar 231.230 / 231,230 kebaca benar)
     * 4) raw value (fallback)
     */
    private function qtyFromCell(Worksheet $sheet, int $col, int $row): int
    {
        $cell = $sheet->getCell([$col, $row]);
        $raw = $cell->getValue();

        // formula?
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
                // lanjut fallback
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
     * ✅ Angka "231.230" / "231,230" dianggap 231 (ambil sebelum titik/koma).
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

        // buang NBSP & spasi ribuan
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
            return sprintf('
