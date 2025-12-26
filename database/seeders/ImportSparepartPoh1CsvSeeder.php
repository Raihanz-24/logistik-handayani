<?php

namespace Database\Seeders;

use App\Models\Mutasi;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportSparepartPoh1CsvSeeder extends Seeder
{
    private string $gudangName = 'Gudang POH 1';
    private string $fileName   = 'sparepart_poh1.csv';

    public function run(): void
    {
        $filePath = database_path('seeders/data/' . $this->fileName);

        if (! file_exists($filePath)) {
            $this->command?->error("File tidak ditemukan: {$filePath}");
            return;
        }

        foreach (['mutasis', 'produks', 'lokasis', 'produk_lokasi'] as $t) {
            if (! Schema::hasTable($t)) {
                $this->command?->error("Tabel '{$t}' tidak ditemukan.");
                return;
            }
        }

        $mutasiCols = Schema::getColumnListing('mutasis');
        $produkCols = Schema::getColumnListing('produks');
        $lokasiCols = Schema::getColumnListing('lokasis');

        if (! in_array('nama_produk', $produkCols, true)) {
            $this->command?->error("Kolom 'nama_produk' tidak ada di tabel 'produks'.");
            return;
        }
        if (! in_array('nama_lokasi', $lokasiCols, true)) {
            $this->command?->error("Kolom 'nama_lokasi' tidak ada di tabel 'lokasis'.");
            return;
        }

        $user = User::where('email', 'superadmin@example.com')->first()
            ?? User::orderBy('id')->first();

        if (! $user) {
            $this->command?->error("Tidak ada user. Buat minimal 1 user dulu.");
            return;
        }

        $dicatatOlehId = $user->id;
        $approvedById  = $user->id;

        // ===== Read CSV (UTF-8) =====
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            $this->command?->error("Gagal membuka file: {$filePath}");
            return;
        }

        // Deteksi delimiter (koma / titik-koma)
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            $this->command?->error("CSV kosong.");
            return;
        }
        $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        // Balik ke awal file
        rewind($handle);

        // Cari header row yang berisi "Nama Barang"
        $header = null;
        $subHeader = null;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row = $this->trimRow($row);
            if (isset($row[1]) && strcasecmp($row[1], 'Nama Barang') === 0) {
                $header = $row;
                // subheader = baris berikutnya
                $subHeader = fgetcsv($handle, 0, $delimiter);
                $subHeader = $subHeader ? $this->trimRow($subHeader) : null;
                break;
            }
        }

        if (! $header) {
            fclose($handle);
            $this->command?->error("Header 'Nama Barang' tidak ditemukan di CSV.");
            return;
        }

        // Index fixed berdasarkan file kamu:
        // 0 No, 1 Nama Barang, 2 Satuan, 3 KATEGORI, 4 Stock Awal, 5 Tanggal Buffer Stock, 6 Jumlah (buffer)
        $idxNama      = 1;
        $idxSatuan    = 2;
        $idxKategori  = 3;
        $idxStokAwal  = 4;
        $idxBufDate   = 5;
        $idxBufQty    = 6;

        // Cari index kolom "Ending Stock"
        $endingIdx = null;
        foreach ($header as $i => $v) {
            if (strcasecmp($v, 'Ending Stock') === 0) {
                $endingIdx = $i;
                break;
            }
        }
        if ($endingIdx === null) {
            fclose($handle);
            $this->command?->error("Kolom 'Ending Stock' tidak ditemukan di CSV.");
            return;
        }

        // Bangun pasangan tanggal: mulai dari idx 7 sampai sebelum endingIdx
        // Pola: [TanggalHeader, Lokasi, Jumlah] => di CSV header: tanggal ada di kolom lokasi pertama untuk tanggal itu.
        // Pada file kamu: pasangan per tanggal (Lokasi, Jumlah) dan headernya tanggal berada di atas pasangan itu.
        // Saat CSV: biasanya header jadi: ..., 2025-11-23, Jumlah, 2025-11-24, Jumlah ...
        // Karena struktur bisa beda tergantung ekspor, kita pakai subHeader untuk deteksi: subHeader biasanya "Lokasi" dan "Jumlah".
        $pairs = [];
        $i = 7;
        while ($i < $endingIdx) {
            $dateText = $header[$i] ?? null;
            $sub1 = $subHeader[$i] ?? '';
            $sub2 = $subHeader[$i + 1] ?? '';

            // kita anggap pasangan valid kalau subHeader menunjukkan Lokasi/Jumlah
            if ($dateText && stripos($sub1, 'Lokasi') !== false && stripos($sub2, 'Jumlah') !== false) {
                $date = $this->parseDate($dateText);
                if ($date) {
                    $pairs[] = [
                        'date' => $date,      // Y-m-d
                        'locIdx' => $i,
                        'qtyIdx' => $i + 1,
                    ];
                }
            }

            $i += 2;
        }

        // Pastikan gudang ada
        $gudangId = $this->getOrCreateLokasi($this->gudangName, $lokasiCols);

        $made = 0;
        $skippedOut = 0;

        DB::transaction(function () use (
            $handle,
            $delimiter,
            $produkCols,
            $lokasiCols,
            $mutasiCols,
            $idxNama,
            $idxSatuan,
            $idxKategori,
            $idxStokAwal,
            $idxBufDate,
            $idxBufQty,
            $pairs,
            $gudangId,
            $dicatatOlehId,
            $approvedById,
            &$made,
            &$skippedOut
        ) {
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $row = $this->trimRow($row);

                $namaBarang = trim((string)($row[$idxNama] ?? ''));
                if ($namaBarang === '') continue;

                $produkId = $this->getOrCreateProduk(
                    $namaBarang,
                    $row[$idxSatuan] ?? null,
                    $row[$idxKategori] ?? null,
                    $produkCols
                );

                $stokAwalGudang = (int)($row[$idxStokAwal] ?? 0);
                $this->upsertPivotStock($produkId, $gudangId, $stokAwalGudang);

                $tx = [];

                // buffer masuk
                $bufDate = $this->parseDate($row[$idxBufDate] ?? null);
                $bufQty  = (int)($row[$idxBufQty] ?? 0);
                if ($bufDate && $bufQty > 0) {
                    $tx[] = [
                        'tanggal' => $bufDate,
                        'priority' => 0,
                        'jenis' => 'masuk',
                        'jumlah' => $bufQty,
                        'tujuan_id' => null,
                        'ket' => 'Buffer Stock (Import CSV)',
                    ];
                }

                // keluar per tanggal
                foreach ($pairs as $p) {
                    $locText = trim((string)($row[$p['locIdx']] ?? ''));
                    $qtyVal  = $row[$p['qtyIdx']] ?? null;

                    if ($qtyVal === null || $qtyVal === '') continue;

                    $qty = (int)$qtyVal;
                    if ($qty <= 0) continue;

                    $parsed = $this->parseLokasiMulti($locText, $qty);
                    foreach ($parsed as $x) {
                        $lokasiName = $x['lokasi'];
                        $lokasiQty  = $x['qty'];
                        if ($lokasiQty <= 0) continue;

                        $tujuanId = $this->getOrCreateLokasi($lokasiName, $lokasiCols);
                        $this->upsertPivotStock($produkId, $tujuanId, $this->getPivotStock($produkId, $tujuanId));

                        $tx[] = [
                            'tanggal' => $p['date'],
                            'priority' => 1,
                            'jenis' => 'keluar',
                            'jumlah' => $lokasiQty,
                            'tujuan_id' => $tujuanId,
                            'ket' => 'Issued (Import CSV)',
                        ];
                    }
                }

                if (empty($tx)) continue;

                usort($tx, fn($a, $b) => ($a['tanggal'] <=> $b['tanggal']) ?: (($a['priority'] ?? 0) <=> ($b['priority'] ?? 0)));

                $seq = 1;
                foreach ($tx as $t) {
                    $tanggal = $t['tanggal'];
                    $jumlah = (int)$t['jumlah'];

                    $stokAwal = $this->getPivotStockForUpdate($produkId, $gudangId);

                    if ($t['jenis'] === 'masuk') {
                        $stokAkhir = $stokAwal + $jumlah;
                        $this->upsertPivotStock($produkId, $gudangId, $stokAkhir);

                        $noRef = "IMP-POH1-{$tanggal}-MASUK-{$produkId}-{$seq}";

                        $this->createApprovedMutasiSafe($mutasiCols, [
                            'tanggal' => $tanggal,
                            'jenis_mutasi' => 'masuk',
                            'jumlah' => $jumlah,
                            'keterangan' => $t['ket'],
                            'no_ref' => $noRef,
                            'status' => 'approved',
                            'user_id' => $dicatatOlehId,
                            'produk_id' => $produkId,
                            'lokasi_id' => $gudangId,
                            'lokasi_tujuan_id' => null,
                            'created_by' => $dicatatOlehId,
                            'approved_by' => $approvedById,
                            'approved_at' => $tanggal . ' 23:59:59',
                            'stok_awal' => $stokAwal,
                            'stok_akhir' => $stokAkhir,
                        ]);

                        $made++;
                    } else {
                        $tujuanId = (int)($t['tujuan_id'] ?? 0);
                        if ($tujuanId <= 0) {
                            $skippedOut++;
                            $seq++;
                            continue;
                        }

                        if ($stokAwal < $jumlah) {
                            $skippedOut++;
                            $seq++;
                            continue;
                        }

                        $stokAkhir = $stokAwal - $jumlah;
                        $this->upsertPivotStock($produkId, $gudangId, $stokAkhir);

                        $stokTujuanAwal = $this->getPivotStockForUpdate($produkId, $tujuanId);
                        $this->upsertPivotStock($produkId, $tujuanId, $stokTujuanAwal + $jumlah);

                        $noRef = "IMP-POH1-{$tanggal}-KELUAR-{$produkId}-{$seq}";

                        $this->createApprovedMutasiSafe($mutasiCols, [
                            'tanggal' => $tanggal,
                            'jenis_mutasi' => 'keluar',
                            'jumlah' => $jumlah,
                            'keterangan' => $t['ket'],
                            'no_ref' => $noRef,
                            'status' => 'approved',
                            'user_id' => $dicatatOlehId,
                            'produk_id' => $produkId,
                            'lokasi_id' => $gudangId,
                            'lokasi_tujuan_id' => $tujuanId,
                            'created_by' => $dicatatOlehId,
                            'approved_by' => $approvedById,
                            'approved_at' => $tanggal . ' 23:59:59',
                            'stok_awal' => $stokAwal,
                            'stok_akhir' => $stokAkhir,
                        ]);

                        $made++;
                    }

                    $seq++;
                }
            }
        });

        fclose($handle);

        $this->command?->info("✅ Import CSV selesai. Mutasi dibuat/diupdate: {$made}");
        if ($skippedOut > 0) {
            $this->command?->warn("Mutasi keluar di-skip (stok kurang/tujuan kosong): {$skippedOut}");
        }
    }

    /**
     * ===== Helpers =====
     */
    private function trimRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v)) $row[$k] = trim($v);
        }
        return $row;
    }

    private function parseDate($value): ?string
    {
        if ($value === null) return null;
        $str = trim((string)$value);
        if ($str === '') return null;

        // coba parse format umum (yyyy-mm-dd)
        try {
            $dt = new \DateTime($str);
            return $dt->format('Y-m-d');
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
        $name = str_ireplace('Su;lawesi', 'Sulawesi', $name);
        return $this->normalizeText($name);
    }

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
                $out[] = ['lokasi' => $this->normalizeLokasi($x[1]), 'qty' => (int)$x[2]];
            }
            return $out;
        }

        return [['lokasi' => $lokasiCell, 'qty' => $qtyTotal]];
    }

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

    private function getOrCreateProduk(string $nama, $satuan, $kategori, array $produkCols): int
    {
        $nama = $this->normalizeText($nama);

        $existing = DB::table('produks')->where('nama_produk', $nama)->first();
        if ($existing) {
            if (in_array('kode_produk', $produkCols) && empty($existing->kode_produk)) {
                $kode = $this->makeKode('PRD', $nama, 'produks', 'kode_produk', 30);
                DB::table('produks')->where('id', $existing->id)->update([
                    'kode_produk' => $kode,
                    'updated_at' => now(),
                ]);
            }
            return (int)$existing->id;
        }

        $data = [
            'nama_produk' => $nama,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (in_array('kode_produk', $produkCols)) {
            $data['kode_produk'] = $this->makeKode('PRD', $nama, 'produks', 'kode_produk', 30);
        }
        if (in_array('satuan', $produkCols) && $satuan !== null) {
            $data['satuan'] = $this->normalizeText((string)$satuan);
        }
        if (in_array('kategori', $produkCols) && $kategori !== null) {
            $data['kategori'] = $this->normalizeText((string)$kategori);
        }

        $data = $this->fillRequiredColumns('produks', $produkCols, $data, [
            'kode_produk' => fn() => $data['kode_produk'] ?? $this->makeKode('PRD', $nama, 'produks', 'kode_produk', 30),
        ]);

        DB::table('produks')->insert($data);

        return (int)DB::table('produks')->where('nama_produk', $nama)->value('id');
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
            return (int)$existing->id;
        }

        $data = [
            'nama_lokasi' => $namaLokasi,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (in_array('kode_lokasi', $lokasiCols)) {
            $data['kode_lokasi'] = $this->makeKode('LOK', $namaLokasi, 'lokasis', 'kode_lokasi', 30);
        }

        $data = $this->fillRequiredColumns('lokasis', $lokasiCols, $data, [
            'kode_lokasi' => fn() => $data['kode_lokasi'] ?? $this->makeKode('LOK', $namaLokasi, 'lokasis', 'kode_lokasi', 30),
        ]);

        DB::table('lokasis')->insert($data);

        return (int)DB::table('lokasis')->where('nama_lokasi', $namaLokasi)->value('id');
    }

    private function getPivotStock(int $produkId, int $lokasiId): int
    {
        $row = DB::table('produk_lokasi')->where('produk_id', $produkId)->where('lokasi_id', $lokasiId)->first();
        return (int)($row->stok ?? 0);
    }

    private function getPivotStockForUpdate(int $produkId, int $lokasiId): int
    {
        $row = DB::table('produk_lokasi')
            ->where('produk_id', $produkId)
            ->where('lokasi_id', $lokasiId)
            ->lockForUpdate()
            ->first();

        return (int)($row->stok ?? 0);
    }

    private function upsertPivotStock(int $produkId, int $lokasiId, int $stok): void
    {
        $existing = DB::table('produk_lokasi')->where('produk_id', $produkId)->where('lokasi_id', $lokasiId)->first();

        DB::table('produk_lokasi')->updateOrInsert(
            ['produk_id' => $produkId, 'lokasi_id' => $lokasiId],
            [
                'stok' => $stok,
                'updated_at' => now(),
                'created_at' => $existing ? ($existing->created_at ?? now()) : now(),
            ]
        );
    }

    private function createApprovedMutasiSafe(array $mutasiCols, array $payload): void
    {
        $data = [];
        foreach ($payload as $k => $v) {
            if (in_array($k, $mutasiCols, true)) {
                $data[$k] = $v;
            }
        }

        if (!isset($data['no_ref'])) return;

        if (in_array('updated_at', $mutasiCols, true) && !isset($data['updated_at'])) $data['updated_at'] = now();
        if (in_array('created_at', $mutasiCols, true) && !isset($data['created_at'])) $data['created_at'] = now();

        Mutasi::updateOrCreate(['no_ref' => $data['no_ref']], $data);
    }

    private function fillRequiredColumns(string $table, array $tableCols, array $data, array $generators = []): array
    {
        $required = $this->getRequiredNoDefaultColumns($table);

        foreach ($required as $col) {
            if (!in_array($col, $tableCols, true)) continue;
            if (array_key_exists($col, $data)) continue;

            if (isset($generators[$col]) && is_callable($generators[$col])) {
                $data[$col] = $generators[$col]();
                continue;
            }

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

            if (str_contains(strtolower((string)$r->EXTRA), 'auto_increment')) continue;

            if ((string)$r->IS_NULLABLE === 'NO' && $r->COLUMN_DEFAULT === null) {
                $required[] = $col;
            }
        }

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
}
