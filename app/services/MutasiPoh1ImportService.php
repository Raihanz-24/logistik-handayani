<?php

namespace App\Services;

use App\Models\KategoriProduk;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\Produk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MutasiPoh1ImportService
{
    /**
     * Import workbook POH1 (XLSX) - multi sheet Week 1..5
     * - Produk dicocokkan berdasarkan nama_produk
     * - Membuat mutasi approved untuk:
     *   - Buffer Stock (masuk)
     *   - Issued harian (keluar + transfer ke lokasi tujuan)
     * - Mengupdate stok produk_lokasi untuk gudang & tujuan
     */
    public function import(string $absolutePath, string $gudangName, int $actorUserId): array
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if ($ext !== 'xlsx') {
            throw new \RuntimeException('File harus XLSX untuk format workbook Week 1..5 ini.');
        }

        if (! class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new \RuntimeException('PhpSpreadsheet tidak tersedia. Server ini tidak bisa import XLSX.');
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($absolutePath);

        // ✅ ambil semua sheet "Week"
        $weekSheets = [];
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $name = (string) $sheet->getTitle();
            if (preg_match('/^week/i', trim($name))) {
                $weekSheets[] = $sheet;
            }
        }

        if (empty($weekSheets)) {
            throw new \RuntimeException('Sheet "Week" tidak ditemukan pada file.');
        }

        $summary = [
            'sheets' => count($weekSheets),
            'rows' => 0,
            'mutasi_created' => 0,
            'produk_upserted' => 0,
            'lokasi_created' => 0,
            'errors' => [],
        ];

        DB::transaction(function () use ($weekSheets, $gudangName, $actorUserId, &$summary) {
            // Gudang utama POH 1
            $gudang = $this->getOrCreateLokasi($gudangName, $summary);

            // untuk mencegah stok_awal diset berulang tiap sheet
            $produkInitialized = []; // [produk_id => true]

            // proses tiap sheet Week, urut sesuai workbook
            foreach ($weekSheets as $sheet) {
                $rows = $sheet->toArray(null, true, false, false);
                if (count($rows) < 6) {
                    continue;
                }

                try {
                    [$headerRowIndex, $subHeaderRowIndex] = $this->findHeaderRows($rows);
                } catch (\Throwable $e) {
                    $summary['errors'][] = "Sheet {$sheet->getTitle()}: " . $e->getMessage();
                    continue;
                }

                $header = $rows[$headerRowIndex] ?? [];
                $subHeader = $rows[$subHeaderRowIndex] ?? [];

                $idxNama      = $this->findIndexContains($header, 'Nama');
                $idxSatuan    = $this->findIndex($header, 'Satuan');
                $idxStockAwal = $this->findIndexContains($header, 'Stock Awal');
                $idxBufDate   = $this->findIndexContains($header, 'Tanggal Buffer');
                $idxBufQty    = $this->findIndexExactFirst($header, 'Jumlah'); // jumlah buffer (header row)
                $idxEnding    = $this->findIndexContains($header, 'Ending Stock');

                if ($idxNama === null || $idxStockAwal === null || $idxEnding === null) {
                    $summary['errors'][] = "Sheet {$sheet->getTitle()}: Header kolom utama tidak lengkap.";
                    continue;
                }

                // ✅ pasang datePairs berdasarkan subheader Lokasi/Jumlah + carry forward tanggal (merge header)
                $datePairs = $this->collectDatePairsFromMergedHeader(
                    header: $header,
                    subHeader: $subHeader,
                    // mulai scan setelah buffer qty (kalau ada), kalau tidak ada, mulai dari setelah stockAwal
                    startIndex: ($idxBufQty !== null ? $idxBufQty + 1 : $idxStockAwal + 1),
                    endIndex: ($idxEnding - 1)
                );

                // data mulai setelah subheader
                for ($r = $subHeaderRowIndex + 1; $r < count($rows); $r++) {
                    $row = $rows[$r];
                    $nama = trim((string) ($row[$idxNama] ?? ''));

                    if ($nama === '') {
                        continue;
                    }

                    $summary['rows']++;

                    try {
                        $satuan = trim((string) ($row[$idxSatuan] ?? ''));

                        $stockAwal = $this->toNumber($row[$idxStockAwal] ?? 0);
                        $ending    = $this->toNumber($row[$idxEnding] ?? 0);

                        // upsert produk berdasarkan nama
                        $produk = Produk::query()->firstOrCreate(
                            ['nama_produk' => $nama],
                            [
                                'kode_produk' => null,
                                'satuan' => $satuan ?: null,
                                'deskripsi' => null,
                                'harga_beli' => null,
                                'harga_jual' => null,
                                'barcode' => null,
                                'gambar' => null,
                            ]
                        );

                        if ($produk->exists) {
                            $needsSave = false;
                            if ($satuan && empty($produk->satuan)) {
                                $produk->satuan = $satuan;
                                $needsSave = true;
                            }
                            if ($needsSave) {
                                $produk->save();
                            }
                        }

                        $summary['produk_upserted']++;

                        // ✅ set stok awal hanya sekali per produk (pada saat pertama kali item muncul)
                        if (! isset($produkInitialized[$produk->id])) {
                            $this->setPivotStock($produk->id, $gudang->id, $stockAwal);
                            $produkInitialized[$produk->id] = true;
                        }

                        // ✅ Buffer Stock (masuk dari luar)
                        if ($idxBufDate !== null && $idxBufQty !== null) {
                            $bufTanggal = $this->parseDate($row[$idxBufDate] ?? null);
                            $bufJumlah  = $this->toNumber($row[$idxBufQty] ?? 0);

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

                        // ✅ Issued harian (keluar -> tujuan berdasarkan lokasi pada kolom)
                        foreach ($datePairs as $pair) {
                            $tgl = $pair['date'];
                            $idxLok = $pair['idx_lokasi'];
                            $idxJml = $pair['idx_jumlah'];

                            $lokName = trim((string) ($row[$idxLok] ?? ''));
                            $qty = $this->toNumber($row[$idxJml] ?? 0);

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

                        /**
                         * ✅ Rekonsiliasi stok gudang mengikuti "Ending Stock" per sheet.
                         * Ini penting supaya stok tidak drift jika ada sel kosong / pembulatan / data manual di excel.
                         */
                        $this->setPivotStock($produk->id, $gudang->id, $ending);
                    } catch (\Throwable $e) {
                        $summary['errors'][] = "Sheet {$sheet->getTitle()} baris Excel ke-" . ($r + 1) . ": " . $e->getMessage();
                    }
                }
            }
        });

        return $summary;
    }

    /* =================== HEADER PARSER =================== */

    private function findHeaderRows(array $rows): array
    {
        // cari baris yang punya "No." dan "Nama"
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
        // cari "Jumlah" yang pertama (untuk Buffer Stock)
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

    /**
     * ✅ Untuk header yang merge:
     * - subHeader punya pasangan: Lokasi | Jumlah
     * - header atas punya tanggal di kolom Lokasi (kolom pertama pair), kolom Jumlah biasanya kosong
     * - kalau header tanggal kosong → gunakan tanggal terakhir di kiri (carry forward)
     */
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
                $date = $lastDate; // carry forward karena merge cell
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

        // buang selain digit dan minus
        $s = preg_replace('/[^\d\-]/', '', $s);
        return (int) ($s === '' ? 0 : $s);
    }

    /**
     * Parse date dari:
     * - DateTimeInterface
     * - serial Excel (angka)
     * - string berbagai format (Y-m-d, d/m/Y, d-m-Y, d-M-y, dll)
     */
    private function parseDate($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        // serial Excel date (umumnya > 20000 untuk tahun modern)
        if (is_numeric($v)) {
            $n = (float) $v;
            if ($n > 20000) {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($n);
                return $dt->format('Y-m-d');
            }
        }

        $s = trim((string) $v);
        if ($s === '') return null;

        // normalisasi separator
        $s = str_replace(['\\', '.'], '/', $s);

        // 1) yyyy-mm-dd
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // 2) dd/mm/yyyy
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // 3) dd-mm-yyyy (diubah jadi slash dulu)
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // 4) format excel "30-Nov-25" / "30 Nov 2025" dll
        $ts = strtotime($s);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        return null;
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

        // kode_lokasi wajib -> buat dari slug
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

        // lock stok gudang
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

            // masuk = gudang tujuan
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
            // skip jika tujuan sama gudang
            return;
        }

        // lock gudang asal
        $pivotAsal = DB::table('produk_lokasi')
            ->where('produk_id', $produkId)
            ->where('lokasi_id', $gudangAsalId)
            ->lockForUpdate()
            ->first();

        $stokAwal = (int) ($pivotAsal->stok ?? 0);

        // jika stok kurang, stop (biar konsisten)
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

        // tambah stok tujuan
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

            // keluar = gudang asal
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
