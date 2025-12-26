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
    private string $sheetName = 'Week  (4)';

    // Nama lokasi gudang utama untuk POH 1 (sesuaikan kalau mau)
    private string $gudangName = 'Gudang POH 1';

    public function run(): void
    {
        $filePath = database_path('seeders/data/sparepart_poh1.xlsx');

        if (! file_exists($filePath)) {
            $this->command?->error("File tidak ditemukan: {$filePath}");
            return;
        }

        // Validasi tabel minimal
        foreach (['mutasis', 'produks', 'lokasis', 'produk_lokasi'] as $tbl) {
            if (! Schema::hasTable($tbl)) {
                $this->command?->error("Tabel {$tbl} tidak ditemukan.");
                return;
            }
        }

        $produkCols = Schema::getColumnListing('produks');
        $lokasiCols = Schema::getColumnListing('lokasis');

        if (! in_array('nama_produk', $produkCols)) {
            $this->command?->error("Kolom 'nama_produk' tidak ada di tabel produks.");
            return;
        }
        if (! in_array('nama_lokasi', $lokasiCols)) {
            $this->command?->error("Kolom 'nama_lokasi' tidak ada di tabel lokasis.");
            return;
        }

        // Ambil user untuk created_by, approved_by, user_id
        $superAdmin = User::where('email', 'superadmin@example.com')->first()
            ?? User::orderBy('id')->first();

        if (! $superAdmin) {
            $this->command?->error("User tidak ditemukan. Buat minimal 1 user dulu.");
            return;
        }

        $dicatatOlehId = $superAdmin->id;
        $approvedById  = $superAdmin->id;

        // Pastikan gudang POH 1 ada
        $gudangId = $this->getOrCreateLokasi($this->gudangName);

        // Load spreadsheet
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName($this->sheetName) ?? $spreadsheet->getActiveSheet();

        // Ambil semua data sebagai array [rowIndex => [colLetter => value]]
        $rows = $sheet->toArray(null, true, true, true);

        // Temukan baris header yang berisi "Nama Barang"
        [$headerRow, $subHeaderRow] = $this->findHeaderRows($rows);
        if (! $headerRow) {
            $this->command?->error("Header 'Nama Barang' tidak ditemukan.");
            return;
        }

        // Peta kolom fixed (berdasarkan file kamu)
        // A: No, B: Nama Barang, C: Satuan, D: KATEGORI, E: Stock Awal, F: Tanggal Buffer Stock, G: Jumlah buffer
        $colNo         = 'A';
        $colNama       = 'B';
        $colSatuan     = 'C';
        $colKategori   = 'D';
        $colStockAwal  = 'E';
        $colBufferDate = 'F';
        $colBufferQty  = 'G';

        // Cari kolom "Ending Stock" di header
        $endingCol = $this->findEndingStockCol($rows[$headerRow]);
        if (! $endingCol) {
            $this->command?->error("Kolom 'Ending Stock' tidak ditemukan.");
            return;
        }

        // Tentukan pasangan kolom tanggal (mulai dari H sampai sebelum Ending Stock)
        // Format: [ ['dateCol' => 'H', 'locCol' => 'H', 'qtyCol' => 'I', 'date' => DateTimeInterface], ... ]
        $datePairs = $this->buildDatePairs($rows[$headerRow], $subHeaderRow, $endingCol);

        // Data dimulai setelah sub header
        $startRow = $subHeaderRow + 1;

        DB::transaction(function () use (
            $rows,
            $startRow,
            $endingCol,
            $datePairs,
            $colNo,
            $colNama,
            $colSatuan,
            $colKategori,
            $colStockAwal,
            $colBufferDate,
            $colBufferQty,
            $gudangId,
            $dicatatOlehId,
            $approvedById,
            $produkCols
        ) {
            $mutasiCreated = 0;

            foreach ($rows as $rIndex => $row) {
                if ($rIndex < $startRow) continue;

                $namaBarang = trim((string) ($row[$colNama] ?? ''));
                if ($namaBarang === '') continue;

                // 1) Produk
                $produkId = $this->getOrCreateProduk($namaBarang, $row[$colSatuan] ?? null, $row[$colKategori] ?? null, $produkCols);

                // 2) Set stok awal gudang di pivot produk_lokasi
                $stockAwal = (int) ($row[$colStockAwal] ?? 0);
                $this->upsertPivotStock($produkId, $gudangId, $stockAwal);

                // 3) Kumpulkan transaksi untuk produk ini (masuk + keluar) lalu urutkan by tanggal
                $tx = [];

                // 3a) Mutasi masuk dari Buffer Stock (jika ada)
                $bufferDateVal = $row[$colBufferDate] ?? null;
                $bufferQtyVal  = $row[$colBufferQty] ?? null;

                $bufferDate = $this->parseExcelDateValue($bufferDateVal);
                $bufferQty  = is_null($bufferQtyVal) ? 0 : (int) $bufferQtyVal;

                if ($bufferDate && $bufferQty > 0) {
                    $tx[] = [
                        'tanggal' => $bufferDate->format('Y-m-d'),
                        'priority' => 0, // masuk dulu jika tanggal sama
                        'jenis' => 'masuk',
                        'jumlah' => $bufferQty,
                        'tujuan_lokasi_id' => null,
                        'tujuan_lokasi_name' => null,
                        'keterangan' => 'Buffer Stock (Import Excel)',
                    ];
                }

                // 3b) Mutasi keluar per tanggal (Lokasi + Jumlah)
                foreach ($datePairs as $pair) {
                    $tanggal = $pair['date']->format('Y-m-d');
                    $locText = trim((string) ($row[$pair['locCol']] ?? ''));
                    $qtyVal  = $row[$pair['qtyCol']] ?? null;

                    if (is_null($qtyVal) || (string)$qtyVal === '') continue;

                    $qty = (int) $qtyVal;
                    if ($qty <= 0) continue;

                    // Parse lokasi: bisa “Bali 17” atau format multi: “Cafe = 1, Sulawesi 21 = 1”
                    $parsed = $this->parseLokasiMulti($locText, $qty);

                    foreach ($parsed as $p) {
                        $lokasiName = $p['lokasi'];
                        $lokasiQty  = $p['qty'];
                        if ($lokasiQty <= 0) continue;

                        $tujuanId = $this->getOrCreateLokasi($lokasiName);

                        // Pastikan pivot tujuan ada (stok awal 0)
                        $this->upsertPivotStock($produkId, $tujuanId, $this->getPivotStock($produkId, $tujuanId));

                        $tx[] = [
                            'tanggal' => $tanggal,
                            'priority' => 1,
                            'jenis' => 'keluar',
                            'jumlah' => $lokasiQty,
                            'tujuan_lokasi_id' => $tujuanId,
                            'tujuan_lokasi_name' => $lokasiName,
                            'keterangan' => 'Issued (Import Excel)',
                        ];
                    }
                }

                if (empty($tx)) {
                    continue;
                }

                // Sort transaksi produk ini by tanggal lalu priority (masuk sebelum keluar di tanggal sama)
                usort($tx, function ($a, $b) {
                    $d1 = $a['tanggal'] <=> $b['tanggal'];
                    if ($d1 !== 0) return $d1;
                    return ($a['priority'] ?? 0) <=> ($b['priority'] ?? 0);
                });

                // 4) Apply transaksi berurutan: update pivot + create mutasi approved + isi stok_awal/stok_akhir
                $seq = 1;
                foreach ($tx as $t) {
                    $stokAwalGudang = $this->getPivotStockForUpdate($produkId, $gudangId);

                    if ($t['jenis'] === 'masuk') {
                        $stokAkhirGudang = $stokAwalGudang + $t['jumlah'];
                        $this->upsertPivotStock($produkId, $gudangId, $stokAkhirGudang);

                        $noRef = "IMP-POH1-{$t['tanggal']}-MASUK-{$produkId}-{$seq}";
                        $this->createApprovedMutasi(
                            $t['tanggal'],
                            'masuk',
                            $t['jumlah'],
                            $noRef,
                            $t['keterangan'],
                            $dicatatOlehId,
                            $produkId,
                            $gudangId,
                            null,
                            $approvedById,
                            $stokAwalGudang,
                            $stokAkhirGudang
                        );
                    } else {
                        // keluar
                        if ($stokAwalGudang < $t['jumlah']) {
                            // kalau stok kurang, tetap skip agar seeder tidak fail total
                            // kamu bisa ubah menjadi throw jika mau strict
                            continue;
                        }

                        $stokAkhirGudang = $stokAwalGudang - $t['jumlah'];
                        $this->upsertPivotStock($produkId, $gudangId, $stokAkhirGudang);

                        // tambah stok tujuan
                        $tujuanId = (int) $t['tujuan_lokasi_id'];
                        $stokTujuanAwal = $this->getPivotStockForUpdate($produkId, $tujuanId);
                        $this->upsertPivotStock($produkId, $tujuanId, $stokTujuanAwal + $t['jumlah']);

                        $noRef = "IMP-POH1-{$t['tanggal']}-KELUAR-{$produkId}-{$seq}";
                        $this->createApprovedMutasi(
                            $t['tanggal'],
                            'keluar',
                            $t['jumlah'],
                            $noRef,
                            $t['keterangan'],
                            $dicatatOlehId,
                            $produkId,
                            $gudangId,
                            $tujuanId,
                            $approvedById,
                            $stokAwalGudang,
                            $stokAkhirGudang
                        );
                    }

                    $mutasiCreated++;
                    $seq++;
                }
            }

            // Optional: info
            // $this->command?->info("Selesai import. Mutasi dibuat: {$mutasiCreated}");
        });
    }

    private function findHeaderRows(array $rows): array
    {
        $headerRow = null;
        foreach ($rows as $i => $row) {
            $val = trim((string)($row['B'] ?? ''));
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

    private function buildDatePairs(array $headerRow, int $subHeaderRow, string $endingCol): array
    {
        $pairs = [];

        // Kolom tanggal dimulai dari H pada file kamu (setelah G)
        $start = 'H';

        $col = $start;
        while ($this->colLessThan($col, $endingCol)) {
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

    private function parseExcelDateValue($value): ?\DateTimeInterface
    {
        if (is_null($value) || $value === '') return null;

        // Kalau sudah DateTime
        if ($value instanceof \DateTimeInterface) return $value;

        // Excel serial number
        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject($value);
            } catch (\Throwable $e) {
                return null;
            }
        }

        // String date
        $str = trim((string)$value);
        if ($str === '') return null;

        try {
            return new \DateTime($str);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeLokasi(string $name): string
    {
        $name = trim($name);
        $name = str_replace(';', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);

        // perbaiki typo umum di file kamu
        $name = str_ireplace('Su;lawesi', 'Sulawesi', $name);

        return trim($name);
    }

    private function parseLokasiMulti(string $lokasiCell, int $qtyTotal): array
    {
        $lokasiCell = $this->normalizeLokasi($lokasiCell);

        if ($lokasiCell === '' || $lokasiCell === '-') {
            return [['lokasi' => 'Pemakaian', 'qty' => $qtyTotal]];
        }

        // format multi: "Cafe = 1, Sulawesi 21 =1"
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

        // single
        return [[
            'lokasi' => $lokasiCell,
            'qty' => $qtyTotal,
        ]];
    }

    private function getOrCreateProduk(string $nama, $satuan, $kategori, array $produkCols): int
    {
        $nama = trim($nama);

        $data = [
            'nama_produk' => $nama,
            'updated_at' => now(),
            'created_at' => now(),
        ];

        // optional: simpan satuan/kategori jika kolom ada
        if ($satuan !== null && in_array('satuan', $produkCols)) {
            $data['satuan'] = trim((string)$satuan);
        }
        if ($kategori !== null && in_array('kategori', $produkCols)) {
            $data['kategori'] = trim((string)$kategori);
        }

        DB::table('produks')->updateOrInsert(
            ['nama_produk' => $nama],
            $data
        );

        return (int) DB::table('produks')->where('nama_produk', $nama)->value('id');
    }

    private function getOrCreateLokasi(string $namaLokasi): int
    {
        $namaLokasi = $this->normalizeLokasi($namaLokasi);

        DB::table('lokasis')->updateOrInsert(
            ['nama_lokasi' => $namaLokasi],
            [
                'nama_lokasi' => $namaLokasi,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('lokasis')->where('nama_lokasi', $namaLokasi)->value('id');
    }

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

    private function createApprovedMutasi(
        string $tanggal,
        string $jenis,
        int $jumlah,
        string $noRef,
        string $keterangan,
        int $userId,
        int $produkId,
        int $lokasiId,
        ?int $lokasiTujuanId,
        int $approvedById,
        int $stokAwal,
        int $stokAkhir
    ): void {
        Mutasi::updateOrCreate(
            ['no_ref' => $noRef],
            [
                'tanggal' => $tanggal,
                'jenis_mutasi' => $jenis,
                'jumlah' => $jumlah,
                'keterangan' => $keterangan,
                'status' => 'approved',
                'user_id' => $userId,
                'produk_id' => $produkId,
                'lokasi_id' => $lokasiId,
                'lokasi_tujuan_id' => $lokasiTujuanId,
                'created_by' => $userId,
                'approved_by' => $approvedById,
                'approved_at' => now(),

                'stok_awal' => $stokAwal,
                'stok_akhir' => $stokAkhir,
            ]
        );
    }

    private function nextCol(string $col): string
    {
        $col = strtoupper($col);
        $len = strlen($col);

        if ($len === 1) {
            $c = ord($col);
            return chr($c + 1);
        }

        // untuk safety kalau nanti lewat Z (jarang di file ini)
        $index = 0;
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($col[$i]) - 64);
        }
        $index++;

        $out = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $out = chr(65 + $mod) . $out;
            $index = intdiv($index - 1, 26);
        }
        return $out;
    }

    private function colLessThan(string $a, string $b): bool
    {
        return $this->colToIndex($a) < $this->colToIndex($b);
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
