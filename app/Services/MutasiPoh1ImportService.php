<?php

namespace App\Services;

use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\Produk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class MutasiPoh1ImportService
{
    public function import(string $absolutePath, string $gudangName, int $actorUserId): array
    {
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new \RuntimeException('File harus XLSX untuk format workbook Week 1..5 ini.');
        }

        if (! class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xlsx::class)) {
            throw new \RuntimeException('PhpSpreadsheet tidak tersedia. Server ini tidak bisa import XLSX.');
        }

        DB::disableQueryLog();

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        $sheetNames = $reader->listWorksheetNames($absolutePath);

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

        // master gudang
        $gudang = $this->getOrCreateLokasi($gudangName, $summary);
        $produkInitialized = [];

        foreach ($weekNames as $sheetName) {
            try {
                $reader->setLoadSheetsOnly([$sheetName]);
                $spreadsheet = $reader->load($absolutePath);
                $sheet = $spreadsheet->getActiveSheet();

                $highestColumn = $sheet->getHighestColumn();
                $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
                $endColLetter = Coordinate::stringFromColumnIndex($highestColumnIndex);

                // preview buat header detection
                $previewRows = $sheet->rangeToArray("A1:{$endColLetter}60", null, true, false, false);

                [$headerRowIndex0, $subHeaderRowIndex0] = $this->findHeaderRows($previewRows);

                $header = $previewRows[$headerRowIndex0] ?? [];
                $subHeader = $previewRows[$subHeaderRowIndex0] ?? [];

                $idxNo        = $this->findIndex($header, 'No.');
                $idxNama      = $this->findIndexContains($header, 'Nama');
                $idxSatuan    = $this->findIndex($header, 'Satuan');
                $idxStockAwal = $this->findIndexContains($header, 'Stock Awal');
                $idxBufDate   = $this->findIndexContains($header, 'Tanggal Buffer');
                $idxBufQty    = $this->findIndexExactFirst($header, 'Jumlah');
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

                // data mulai setelah subheader (index 0-based -> excel row number = +1)
                $startRow = ($subHeaderRowIndex0 + 1) + 2;

                for ($rowNum = $startRow; $rowNum <= $sheetHighestRow; $rowNum++) {
                    try {
                        $nama = trim((string) $this->cellValue($sheet, $idxNama, $rowNum));
                        if ($nama === '') {
                            continue;
                        }

                        $summary['rows']++;

                        $noExcel = $idxNo !== null ? trim((string) $this->cellValue($sheet, $idxNo, $rowNum)) : null;
                        $satuan  = $idxSatuan !== null ? trim((string) $this->cellValue($sheet, $idxSatuan, $rowNum)) : '';

                        $stockAwal = $this->toNumber($this->cellValue($sheet, $idxStockAwal, $rowNum));
                        $ending    = $this->toNumber($this->cellValue($sheet, $idxEnding, $rowNum));

                        // ✅ kode wajib, stabil dari No + hash nama
                        $kodeProduk = $this->makeKodeProduk($noExcel, $nama);

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

                        $dirty = false;
                        if (empty($produk->kode_produk)) {
                            $produk->kode_produk = $kodeProduk;
                            $dirty = true;
                        }
                        if ($satuan && empty($produk->satuan)) {
                            $produk->satuan = $satuan;
                            $dirty = true;
                        }
                        if ($dirty) $produk->save();

                        $summary['produk_upserted']++;

                        if (!isset($produkInitialized[$produk->id])) {
                            $this->setPivotStock($produk->id, $gudang->id, $stockAwal);
                            $produkInitialized[$produk->id] = true;
                        }

                        // Buffer Stock (masuk)
                        if ($idxBufDate !== null && $idxBufQty !== null) {
                            $bufTanggal = $this->parseDate($this->cellValue($sheet, $idxBufDate, $rowNum));
                            $bufJumlah  = $this->toNumber($this->cellValue($sheet, $idxBufQty, $rowNum));

                            if ($bufTanggal && $bufJumlah > 0) {
                                $this->createApprovedMutasiMasuk(
                                    $produk->id,
                                    $gudang->id,
                                    $bufTanggal,
                                    $bufJumlah,
                                    $actorUserId,
                                    'Buffer Stock (import)'
                                );
                                $summary['mutasi_created']++;
                            }
                        }

                        // Issued (keluar -> tujuan)
                        foreach ($datePairs as $pair) {
                            $tgl = $pair['date'];
                            $lokName = trim((string) $this->cellValue($sheet, $pair['idx_lokasi'], $rowNum));
                            $qty = $this->toNumber($this->cellValue($sheet, $pair['idx_jumlah'], $rowNum));

                            if ($lokName === '' || $qty <= 0) continue;

                            $tujuan = $this->getOrCreateLokasi($lokName, $summary);

                            $this->createApprovedMutasiKeluarTransfer(
                                $produk->id,
                                $gudang->id,
                                $tujuan->id,
                                $tgl,
                                $qty,
                                $actorUserId,
                                'Issued (import)'
                            );
                            $summary['mutasi_created']++;
                        }

                        // Rekonsiliasi ending stock
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
            }
        }

        return $summary;
    }

    /**
     * ✅ Cara ambil nilai cell yang kompatibel lintas versi:
     * pakai coordinate "A1" via getCell()
     */
    private function cellValue(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, ?int $colIndex0, int $rowNum)
    {
        if ($colIndex0 === null) return null;

        $colLetter = Coordinate::stringFromColumnIndex($colIndex0 + 1);
        $addr = $colLetter . $rowNum;

        $cell = $sheet->getCell($addr);
        $v = $cell?->getValue();

        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            return $v->getPlainText();
        }

        return $v;
    }

    /* =================== HEADER PARSER =================== */

    private function findHeaderRows(array $rows): array
    {
        for ($i = 0; $i < min(count($rows), 60); $i++) {
            $a = trim((string) ($rows[$i][0] ?? ''));
            $b = trim((string) ($rows[$i][1] ?? ''));
            if ($a === 'No.' && str_contains(mb_strtolower($b), 'nama')) {
                return [$i, $i + 1];
            }
        }
        throw new \RuntimeException('Header "No. / Nama Barang" tidak ditemukan.');
    }

    private function findIndex(array $row, string $exact): ?int
    {
        foreach ($row as $i => $v) {
            if (trim((string) $v) === $exact) return $i;
        }
        return null;
    }

    private function findIndexExactFirst(array $row, string $exact): ?int
    {
        foreach ($row as $i => $v) {
            if (trim((string) $v) === $exact) return $i;
        }
        return null;
    }

    private function findIndexContains(array $row, string $needle): ?int
    {
        $needle = mb_strtolower($needle);
        foreach ($row as $i => $v) {
            if (str_contains(mb_strtolower(trim((string) $v)), $needle)) return $i;
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
            if ($subA !== 'lokasi' || $subB !== 'jumlah') continue;

            $candidate = $header[$i] ?? null;
            $date = $this->parseDate($candidate);

            if (! $date && $lastDate) $date = $lastDate;

            if ($date) {
                $lastDate = $date;
                $pairs[] = ['date' => $date, 'idx_lokasi' => $i, 'idx_jumlah' => $i + 1];
            }
        }

        return $pairs;
    }

    /* =================== DATE & NUMBER =================== */

    private function toNumber($v): int
    {
        if ($v === null) return 0;
        if (is_numeric($v)) return (int) round((float) $v);

        $s = trim((string) $v);
        if ($s === '') return 0;

        $s = preg_replace('/[^\d\-]/', '', $s);
        return (int) ($s === '' ? 0 : $s);
    }

    private function parseDate($v): ?string
    {
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');

        if (is_numeric($v)) {
            $n = (float) $v;
            if ($n > 20000) {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($n);
                return $dt->format('Y-m-d');
            }
        }

        $s = trim((string) $v);
        if ($s === '') return null;

        $ts = strtotime($s);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private function makeKodeProduk($noExcel, string $nama): string
    {
        $digits = $noExcel ? preg_replace('/[^\d]/', '', (string) $noExcel) : '';
        $noPart = $digits !== '' ? str_pad($digits, 6, '0', STR_PAD_LEFT) : '000000';
        $uid = strtoupper(substr(md5(mb_strtolower(trim($nama))), 0, 6));
        return "POH1-{$noPart}-{$uid}";
    }

    /* =================== MASTER DATA =================== */

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

    private function setPivotStock(int $produkId, int $lokasiId, int $stok): void
    {
        DB::table('produk_lokasi')->updateOrInsert(
            ['produk_id' => $produkId, 'lokasi_id' => $lokasiId],
            ['stok' => max(0, $stok), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /* =================== MUTASI CREATORS =================== */

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
            ['stok' => $stokAkhir, 'updated_at' => now(), 'created_at' => $pivot?->created_at ?? now()]
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
        if ($lokasiTujuanId === $gudangAsalId) return;

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
            ['stok' => $stokAkhir, 'updated_at' => now(), 'created_at' => $pivotAsal?->created_at ?? now()]
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
            ['stok' => $stokTujuanAkhir, 'updated_at' => now(), 'created_at' => $pivotT?->created_at ?? now()]
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
