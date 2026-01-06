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
     * - Angka "231.230" dianggap "231" (hapus belakang titik)
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

        if (! class_exists(IOFactory::class)) {
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
            $produkInitialized = []; // set stok awal sekali per produk

            foreach ($weekSheetNames as $sheetName) {
                try {
                    // Load 1 sheet saja (hemat memory)
                    $reader = IOFactory::createReaderForFile($absolutePath);
                    $reader->setReadDataOnly(true);
                    $reader->setLoadSheetsOnly([$sheetName]);

                    $spreadsheet = $reader->load($absolutePath);
                    $sheet = $spreadsheet->getActiveSheet(); /** @var Worksheet $sheet */

                    // Cari header row "No." & "Nama"
                    [$headerRow, $subHeaderRow] = $this->findHeaderRows($sheet);

                    // Ambil header & subheader array (cukup scan 1..80 kolom aja)
                    $header = $this->readRow($sheet, $headerRow, 1, 80);
                    $subHeader = $this->readRow($sheet, $subHeaderRow, 1, 80);

                    $idxNo       = $this->findIndexExact($header, 'No.');
                    $idxNama     = $this->findIndexContains($header, 'Nama');
                    $idxSatuan   = $this->findIndexExact($header, 'Satuan');
                    $idxStockAwal= $this->findIndexContains($header, 'Stock Awal');
                    $idxBufDate  = $this->findIndexContains($header, 'Tanggal Buffer');
                    $idxBufQty   = $this->findIndexExactFirst($header, 'Jumlah'); // jumlah buffer (header row)
                    $idxEnding   = $this->findIndexContains($header, 'Ending Stock');
                    $idxKategori = $this->findIndexContains($header, 'Kategori'); // kolom KATEGORI (paling kanan header utama)

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
                            $noVal = $idxNo ? $this->cellValue($sheet, $idxNo, $r) : null;
                            $no = $this->toIntNo($noVal); // untuk kode produk
                            $nama = trim((string) $this->cellValue($sheet, $idxNama, $r));

                            if ($nama === '') {
                                continue;
                            }

                            $summary['rows']++;

                            $satuan = $idxSatuan ? trim((string) $this->cellValue($sheet, $idxSatuan, $r)) : '';
                            $katName = $idxKategori ? trim((string) $this->cellValue($sheet, $idxKategori, $r)) : '';

                            $stockAwal = $this->toIntQty($this->cellValue($sheet, $idxStockAwal, $r));
                            $ending    = $this->toIntQty($this->cellValue($sheet, $idxEnding, $r));

                            // Upsert produk by nama (sesuai keputusan kamu saat ini)
                            // IMPORTANT: hosting kamu punya constraint NOT NULL (kode_produk, harga_beli, harga_jual) -> kasih default
                            $produk = Produk::query()->where('nama_produk', $nama)->first();

                            if (! $produk) {
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
                                // update satuan kalau kosong
                                $needsSave = false;

                                if ($satuan !== '' && empty($produk->satuan)) {
                                    $produk->satuan = $satuan;
                                    $needsSave = true;
                                }

                                // jaga-jaga kalau ada data lama tanpa kode (meski DB kamu biasanya tidak mengizinkan)
                                if (empty($produk->kode_produk)) {
                                    $produk->kode_produk = $this->makeKodeProduk($fileKey, $no);
                                    $needsSave = true;
                                }

                                // jaga-jaga constraint harga
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
                                if (! isset($kategoriCache[$katKey])) {
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

                            // Set stok awal gudang hanya sekali per produk
                            if (! isset($produkInitialized[$produk->id])) {
                                $this->setPivotStock($produk->id, $gudang->id, $stockAwal);
                                $produkInitialized[$produk->id] = true;
                            }

                            // Buffer Stock (masuk dari luar)
                            if ($idxBufDate !== null && $idxBufQty !== null) {
                                $bufTanggal = $this->parseDate($this->cellValue($sheet, $idxBufDate, $r));
                                $bufJumlah  = $this->toIntQty($this->cellValue($sheet, $idxBufQty, $r));

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
                                $idxLok = $pair['idx_lokasi'];
                                $idxJml = $pair['idx_jumlah'];

                                $lokName = trim((string) $this->cellValue($sheet, $idxLok, $r));
                                $qty = $this->toIntQty($this->cellValue($sheet, $idxJml, $r));

                                if ($lokName === '' || $qty <= 0) {
                                    continue;
                                }

                                $lokKey = mb_strtolower($lokName);
                                if (! isset($lokasiCache[$lokKey])) {
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

                            // Rekonsiliasi stok gudang = Ending Stock
                            $this->setPivotStock($produk->id, $gudang->id, $ending);
                        } catch (\Throwable $e) {
                            $summary['errors'][] = "Sheet {$sheetName} baris Excel ke-" . $r . ": " . $e->getMessage();
                        }
                    }

                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet);

                    // bantu GC di shared hosting
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
        // getCell([col,row]) kompatibel untuk versi PhpSpreadsheet baru
        return $sheet->getCell([$col, $row])->getValue();
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

            // format di file kamu: "Lokasi " dan "Jumlah" (kadang spasi)
            if (! str_contains($subA, 'lokasi') || ! str_contains($subB, 'jumlah')) {
                continue;
            }

            $candidate = $header[$i] ?? null;
            $date = $this->parseDate($candidate);

            if (! $date && $lastDate) {
                $date = $lastDate; // carry forward (merge)
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
     * Konversi angka qty/stok:
     * - numeric float: 231.230 -> 231 (hapus pecahan)
     * - string: "231.230" -> 231 (ambil sebelum titik)
     * - string: "231,230" -> 231 (ambil sebelum koma)
     * - string dengan simbol lain tetap aman
     */
    private function toIntQty($v): int
    {
        if ($v === null) return 0;

        if (is_int($v)) return $v;

        if (is_float($v) || is_numeric($v)) {
            // hapus pecahan
            return (int) floor((float) $v);
        }

        $s = trim((string) $v);
        if ($s === '') return 0;

        // kalau ada titik/koma -> ambil bagian sebelum titik/koma pertama
        if (str_contains($s, '.')) {
            $s = explode('.', $s, 2)[0];
        }
        if (str_contains($s, ',')) {
            $s = explode(',', $s, 2)[0];
        }

        // buang selain digit dan minus
        $s = preg_replace('/[^\d\-]/', '', $s);
        if ($s === '' || $s === '-') return 0;

        return (int) $s;
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

        // untuk No, kita ambil digit saja
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
        $noPart = $no ? str_pad((string) $no, 6, '0', STR_PAD_LEFT) : str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $uid = strtoupper(Str::random(6));
        // format: POH1-000123-ABCDEF (fileKey agar beda antar sumber)
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

        // pastikan slug unik
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
            return false; // idempotent
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

            'lokasi_id' => $gudangId,        // masuk = gudang tujuan
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
            return false; // skip tujuan sama
        }

        $noRef = 'IMP-OUT-' . substr(sha1($noRefSeed), 0, 12);

        if (Mutasi::query()->where('no_ref', $noRef)->exists()) {
            return false; // idempotent
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
