<?php

namespace App\Services;

use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\Produk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MutasiPoh1ImportService
{
    /**
     * Import workbook POH1 (XLSX) - multi sheet Week 1..5
     * - Produk dicocokkan berdasarkan nama_produk (sementara sesuai permintaan)
     * - kode_produk dibuat otomatis (wajib di hosting)
     * - Load hemat memory: per-sheet + readDataOnly + disk cache + row-by-row
     */
    public function import(string $absolutePath, string $gudangName, int $actorUserId): array
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new \RuntimeException('File harus XLSX untuk format workbook Week 1..5 ini.');
        }

        if (! class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xlsx::class)) {
            throw new \RuntimeException('PhpSpreadsheet tidak tersedia. Server ini tidak bisa import XLSX.');
        }

        DB::disableQueryLog();

        // ✅ hemat memory: cache cell ke disk (kalau API tersedia)
        $this->configurePhpSpreadsheetCaching();

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        // ambil nama sheet tanpa load data semua
        $sheetNames = $reader->listWorksheetNames($absolutePath);

        // ambil semua sheet yg judulnya Week...
        $weekNames = [];
        foreach ($sheetNames as $sn) {
            $snTrim = trim((string) $sn);
            if (preg_match('/^week/i', $snTrim)) {
                $weekNames[] = $snTrim;
            }
        }

        if (empty($weekNames)) {
            throw new \RuntimeException('Sheet "Week" tidak ditemukan pada file.');
        }

        $summary = [
            'sheets' => count($weekNames),
            'rows' => 0,
            'mutasi_created' => 0,
            'produk_upserted' => 0,
            'lokasi_created' => 0,
            'errors' => [],
        ];

        // Gudang utama dibuat sekali
        $gudang = $this->getOrCreateLokasi($gudangName, $summary);

        // untuk mencegah stok_awal diset berulang tiap sheet
        $produkInitialized = []; // [produk_id => true]

        foreach ($weekNames as $sheetName) {
            try {
                // ✅ Load hanya 1 sheet (hemat memory)
                $reader->setLoadSheetsOnly([$sheetName]);
                $spreadsheet = $reader->load($absolutePath);
                $sheet = $spreadsheet->getActiveSheet();

                // baca 60 baris pertama untuk cari header (cukup ringan)
                $highestColumn = $sheet->getHighestColumn();
                $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
                $endColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($highestColumnIndex);

                $previewRange = "A1:{$endColLetter}60";
                $previewRows = $sheet->rangeToArray($previewRange, null, true, false, false);

                if (count($previewRows) < 6) {
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);
                    gc_collect_cycles();
                    continue;
                }

                [$headerRowIndex0, $subHeaderRowIndex0] = $this->findHeaderRows($previewRows);

                $header = $previewRows[$headerRowIndex0] ?? [];
                $subHeader = $previewRows[$subHeaderRowIndex0] ?? [];

                // kolom-kolom utama
                $idxNo        = $this->findIndex($header, 'No.');
                $idxNama      = $this->findIndexContains($header, 'Nama');
                $idxSatuan    = $this->findIndex($header, 'Satuan');
                $idxStockAwal = $this->findIndexContains($header, 'Stock Awal');
                $idxBufDate   = $this->findIndexContains($header, 'Tanggal Buffer');
                $idxBufQty    = $this->findIndexExactFirst($header, 'Jumlah'); // jumlah buffer (header row)
                $idxEnding    = $this->findIndexContains($header, 'Ending Stock');

                if ($idxNama === null || $idxStockAwal === null || $idxEnding === null) {
                    $summary['errors'][] = "Sheet {$sheetName}: Header kolom utama tidak lengkap.";
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);
                    gc_collect_cycles();
                    continue;
                }

                $datePairs = $this->collectDatePairsFromMergedHeader(
                    header: $header,
                    subHeader: $subHeader,
                    startIndex: ($idxBufQty !== null ? $idxBufQty + 1 : $idxStockAwal + 1),
                    endIndex: ($idxEnding - 1)
                );

                $sheetHighestRow = (int) $sheet->getHighestRow();

                // data mulai setelah subheader
                $startRow = ($subHeaderRowIndex0 + 1) + 1; // karena previewRows 0-based, excel row = index+1
                $startRow += 1; // lewati baris subheader → mulai data

                for ($rowNum = $startRow; $rowNum <= $sheetHighestRow; $rowNum++) {
                    try {
                        $nama = trim((string) $this->cellValue($sheet, $idxNama, $rowNum));
                        if ($nama === '') {
                            continue;
                        }

                        $summary['rows']++;

                        $noExcel = null;
                        if ($idxNo !== null) {
                            $noExcel = trim((string) $this->cellValue($sheet, $idxNo, $rowNum));
                        }

                        $satuan = $idxSatuan !== null
                            ? trim((string) $this->cellValue($sheet, $idxSatuan, $rowNum))
                            : '';

                        $stockAwal = $this->toNumber($this->cellValue($sheet, $idxStockAwal, $rowNum));
                        $ending    = $this->toNumber($this->cellValue($sheet, $idxEnding, $rowNum));

                        // ✅ kode_produk wajib di hosting
                        $kodeProduk = $this->makeKodeProduk($noExcel, $nama);

                        // upsert produk berdasarkan nama (sementara)
                        $produk = Produk::query()->firstOrCreate(
                            ['nama_produk' => $nama],
                            [
                                'kode_produk' => $kodeProduk,
                                'satuan' => $satuan ?: null,
                                'deskripsi' => null,
                                'harga_beli' => null,
                                'harga_jual' => null,
                                'barcode' => null,
                                'gambar' => null,
                            ]
                        );

                        // kalau sudah ada tapi kode_produk kosong -> isi (aman)
                        $needsSave = false;
                        if (empty($produk->kode_produk)) {
                            $produk->kode_produk = $this->makeKodeProduk($noExcel, $nama);
                            $needsSave = true;
                        }
                        if ($satuan && empty($produk->satuan)) {
                            $produk->satuan = $satuan;
                            $needsSave = true;
                        }
                        if ($needsSave) {
                            $produk->save();
                        }

                        $summary['produk_upserted']++;

                        // set stok awal hanya sekali per produk
                        if (! isset($produkInitialized[$produk->id])) {
                            $this->setPivotStock($produk->id, $gudang->id, $stockAwal);
                            $produkInitialized[$produk->id] = true;
                        }

                        // Buffer Stock (masuk dari luar)
                        if ($idxBufDate !== null && $idxBufQty !== null) {
                            $bufTanggal = $this->parseDate($this->cellValue($sheet, $idxBufDate, $rowNum));
                            $bufJumlah  = $this->toNumber($this->cellValue($sheet, $idxBufQty, $rowNum));

                            if ($bufTanggal && $bufJumlah > 0) {
                                $this->createApprovedMutasiMasuk(
                                    produkId: $produk->id,
                                    gudangId: $gudang->id,
                                    tanggal: $bufTanggal,
                                    jumlah: $bufJumlah,
                                    actorUserId: $actorUserId,
                                    keterangan: 'Buffer Stock (import)'
                                );
                                $summary['mutasi_created']++;
                            }
                        }

                        // Issued harian (keluar -> tujuan berdasarkan lokasi pada kolom)
                        foreach ($datePairs as $pair) {
                            $tgl = $pair['date'];
                            $idxLok = $pair['idx_lokasi'];
                            $idxJml = $pair['idx_jumlah'];

                            $lokName = trim((string) $this->cellValue($sheet, $idxLok, $rowNum));
                            $qty = $this->toNumber($this->cellValue($sheet, $idxJml, $rowNum));

                            if ($lokName === '' || $qty <= 0) {
                                continue;
                            }

                            $tujuan = $this->getOrCreateLokasi($lokName, $summary);

                            $this->createApprovedMutasiKeluarTransfer(
                                produkId: $produk->id,
                                gudangAsalId: $gudang->id,
                                lokasiTujuanId: $tujuan->id,
                                tanggal: $tgl,
                                jumlah: $qty,
                                actorUserId: $actorUserId,
                                keterangan: 'Issued (import)'
                            );

                            $summary['mutasi_created']++;
                        }

                        // rekonsiliasi stok gudang mengikuti Ending Stock
                        $this->setPivotStock($produk->id, $gudang->id, $ending);
                    } catch (\Throwable $e) {
                        $summary['errors'][] = "Sheet {$sheetName} baris Excel ke-{$rowNum}: " . $e->getMessage();
                    }
                }

                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
                gc_collect_cycles();
            } catch (\Throwable $e) {
                $summary['errors'][] = "Sheet {$sheetName}: " . $e->getMessage();
                // pastikan memory dibersihkan
                if (isset($spreadsheet)) {
                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);
                }
                gc_collect_cycles();
            }
        }

        return $summary;
    }

    /**
     * Aktifkan cache cell ke disk untuk hemat RAM (jika API tersedia).
     */
    private function configurePhpSpreadsheetCaching(): void
    {
        try {
            if (
                class_exists(\PhpOffice\PhpSpreadsheet\Settings::class) &&
                class_exists(\PhpOffice\PhpSpreadsheet\CachedObjectStorageFactory::class) &&
                method_exists(\PhpOffice\PhpSpreadsheet\Settings::class, 'setCacheStorageMethod')
            ) {
                $tmp = sys_get_temp_dir();
                \PhpOffice\PhpSpreadsheet\Settings::setCacheStorageMethod(
                    \PhpOffice\PhpSpreadsheet\CachedObjectStorageFactory::cache_to_discISAM,
                    ['dir' => $tmp]
                );
            }
        } catch (\Throwable $e) {
            // kalau gagal, abaikan (tetap bisa jalan)
        }
    }

    /**
     * Ambil value cell (0-based col index, 1-based row number)
     */
    private function cellValue($sheet, ?int $colIndex0, int $rowNum)
    {
        if ($colIndex0 === null) return null;

        // PhpSpreadsheet column index starts at 1
        $col = $colIndex0 + 1;

        $cell = $sheet->getCellByColumnAndRow($col, $rowNum);
        $v = $cell?->getValue();

        // normalize RichText
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            return $v->getPlainText();
        }

        return $v;
    }

    /* =================== HEADER PARSER =================== */

    private function findHeaderRows(array $rows): array
    {
        for ($i = 0; $i < min(count($rows), 60); $i++) {
            $row = $rows[$i];

            $a = trim((string) ($row[0] ?? ''));
            $b = trim((string) ($row[1] ?? ''));

            if ($a === 'No.' && str_contains(mb_strtolower($b), 'nama')) {
                return [$i, $i + 1];
            }
        }

        throw new \RuntimeException('Header "No. / Nama Barang" tidak ditemukan.');
    }

    private function findIndex(array $row, string $exact): ?int
    {
        foreach ($row as $i => $v) {
            if (trim((string) $v) === $exact) {
                return $i;
            }
        }
        return null;
    }

    private function findIndexExactFirst(array $row, string $exact): ?int
    {
        foreach ($row as $i => $v) {
            if (trim((string) $v) === $exact) {
                return $i;
            }
        }
        return null;
    }

    private function findIndexContains(array $row, string $needle): ?int
    {
        $needle = mb_strtolower($needle);
        foreach ($row as $i => $v) {
            if (str_contains(mb_strtolower(trim((string) $v)), $needle)) {
                return $i;
            }
        }
        return null;
    }

    private function collectDatePairsFromMergedHeader(array $header, array $subHeader, int $startIndex, int $endIndex): array
    {
        $pairs = [];
        $lastDate = null;

        $startIndex = max(0, $startIndex);
        $endIndex = min(count($subHeader) - 1, $endIndex);

        for ($i = $startIndex; $i <= $endIndex - 1; $i++) {
            $subA = mb_strtolower(trim((string) ($subHeader[$i] ?? '')));
            $subB = mb_strtolower(trim((string) ($subHeader[$i + 1] ?? '')));

            if ($subA !== 'lokasi' || $subB !== 'jumlah') {
                continue;
            }

            $candidate = $header[$i] ?? null;
            $date = $this->parseDate($candidate);

            if (! $date && $lastDate) {
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

    /* =================== DATE & NUMBER =================== */

    private function toNumber($v): int
    {
        if ($v === null) return 0;

        if (is_numeric($v)) {
            return (int) round((float) $v);
        }

        $s = trim((string) $v);
        if ($s === '') return 0;

        $s = preg_replace('/[^\d\-]/', '', $s);
        return (int) ($s === '' ? 0 : $s);
    }

    private function parseDate($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        if (is_numeric($v)) {
            $n = (float) $v;
            if ($n > 20000) {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($n);
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

    /* =================== PRODUK CODE =================== */

    private function makeKodeProduk($noExcel, string $nama): string
    {
        // ambil angka dari No (kalau ada)
        $no = null;
        if ($noExcel !== null && $noExcel !== '') {
            $digits = preg_replace('/[^\d]/', '', (string) $noExcel);
            if ($digits !== '') {
                $no = (int) $digits;
            }
        }

        $noPart = $no !== null ? str_pad((string) $no, 6, '0', STR_PAD_LEFT) : '000000';

        // UID stabil: hash dari nama (biar sama terus jika import ulang)
        $uid = strtoupper(substr(md5(mb_strtolower(trim($nama))), 0, 6));

        // contoh: POH1-000123-A1B2C3
        $base = "POH1-{$noPart}-{$uid}";

        // pastikan tidak bentrok unik (kalau ada unique di DB)
        $kode = $base;
        $n = 1;
        while (Produk::query()->where('kode_produk', $kode)->exists()) {
            $kode = $base . '-' . $n;
            $n++;
            if ($n > 50) break;
        }

        return $kode;
    }

    /* =================== MASTER DATA HELPERS =================== */

    private function getOrCreateLokasi(string $nama, array &$summary): Lokasi
    {
        $nama = trim($nama);
        if ($nama === '') {
            $nama = 'Lokasi';
        }

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
        string $keterangan
    ): void {
        $jumlah = max(0, $jumlah);
        if ($jumlah <= 0) return;

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
            'no_ref' => 'IMP-IN-' . now()->format('YmdHis') . '-' . Str::random(6),
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
    }

    private function createApprovedMutasiKeluarTransfer(
        int $produkId,
        int $gudangAsalId,
        int $lokasiTujuanId,
        string $tanggal,
        int $jumlah,
        int $actorUserId,
        string $keterangan
    ): void {
        $jumlah = max(0, $jumlah);
        if ($jumlah <= 0) return;

        if ($lokasiTujuanId === $gudangAsalId) {
            return;
        }

        $pivotAsal = DB::table('produk_lokasi')
            ->where('produk_id', $produkId)
            ->where('lokasi_id', $gudangAsalId)
            ->lockForUpdate()
            ->first();

        $stokAwal = (int) ($pivotAsal->stok ?? 0);

        if ($stokAwal < $jumlah) {
            throw new \RuntimeException("Stok gudang tidak cukup untuk issued {$jumlah}. Stok: {$stokAwal}");
        }

        $stokAkhir = $stokAwal - $jumlah;

        DB::table('produk_lokasi')->updateOrInsert(
            ['produk_id' => $produkId, 'lokasi_id' => $gudangAsalId],
            [
                'stok' => $stokAkhir,
                'updated_at' => now(),
                'created_at' => $pivotAsal?->created_at ?? now(),
            ]
        );

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
            'no_ref' => 'IMP-OUT-' . now()->format('YmdHis') . '-' . Str::random(6),
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
    }
}
