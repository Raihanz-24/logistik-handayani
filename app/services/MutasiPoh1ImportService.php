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
    public function import(string $absolutePath, string $gudangName, int $actorUserId): array
    {
        $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $rows = match ($ext) {
            'csv'  => $this->readCsv($absolutePath),
            'xlsx' => $this->readXlsxOrFail($absolutePath),
            default => throw new \RuntimeException('Format tidak didukung. Gunakan CSV atau XLSX.'),
        };

        if (count($rows) < 8) {
            throw new \RuntimeException('File terlalu sedikit baris / tidak sesuai format.');
        }

        // cari row header: yang kolom 1 = "No." dan kolom 2 berisi "Nama Barang"
        [$headerRowIndex, $subHeaderRowIndex] = $this->findHeaderRows($rows);

        $header = $rows[$headerRowIndex];
        $subHeader = $rows[$subHeaderRowIndex];

        $idxNo        = $this->findIndex($header, 'No.');
        $idxNama      = $this->findIndexContains($header, 'Nama Barang');
        $idxSatuan    = $this->findIndex($header, 'Satuan');
        $idxKategori  = $this->findIndexContains($header, 'KATEGORI');
        $idxStockAwal = $this->findIndexContains($header, 'Stock Awal');
        $idxBufDate   = $this->findIndexContains($header, 'Tanggal Buffer Stock');
        $idxBufQty    = $this->findIndex($header, 'Jumlah'); // jumlah buffer (di header row)
        $idxEnding    = $this->findIndexContains($header, 'Ending Stock');

        if ($idxNama === null || $idxStockAwal === null || $idxEnding === null) {
            throw new \RuntimeException('Header tidak terbaca. Pastikan format sama seperti file POH 1.');
        }

        // pasangan tanggal: kolom header berisi tanggal, subHeader = Lokasi + Jumlah
        $datePairs = $this->collectDatePairs($header, $subHeader, $idxBufQty + 1, $idxEnding - 1);

        $summary = [
            'rows' => 0,
            'mutasi_created' => 0,
            'produk_upserted' => 0,
            'lokasi_created' => 0,
            'errors' => [],
        ];

        DB::transaction(function () use (
            $rows,
            $headerRowIndex,
            $subHeaderRowIndex,
            $idxNama,
            $idxSatuan,
            $idxKategori,
            $idxStockAwal,
            $idxBufDate,
            $idxBufQty,
            $idxEnding,
            $datePairs,
            $gudangName,
            $actorUserId,
            &$summary
        ) {
            // Gudang utama
            $gudang = $this->getOrCreateLokasi($gudangName, $summary);

            // data mulai setelah subheader
            for ($r = $subHeaderRowIndex + 1; $r < count($rows); $r++) {
                $row = $rows[$r];
                $nama = trim((string) ($row[$idxNama] ?? ''));

                if ($nama === '') {
                    continue;
                }

                $summary['rows']++;

                try {
                    $satuan   = trim((string) ($row[$idxSatuan] ?? ''));
                    $kategori = trim((string) ($row[$idxKategori] ?? ''));

                    $stockAwal = $this->toNumber($row[$idxStockAwal] ?? 0);
                    $ending    = $this->toNumber($row[$idxEnding] ?? 0);

                    // Upsert produk (kode tidak ada di file ini → gunakan nama unik)
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

                    // kalau sudah ada, update satuan bila kosong
                    if ($produk->exists) {
                        $needsSave = false;
                        if ($satuan && empty($produk->satuan)) {
                            $produk->satuan = $satuan;
                            $needsSave = true;
                        }
                        if ($needsSave) $produk->save();
                    }

                    $summary['produk_upserted']++;

                    // kategori + pivot
                    if ($kategori !== '') {
                        $cat = $this->getOrCreateKategori($kategori);
                        $produk->kategoriProduks()->syncWithoutDetaching([$cat->id]);
                    }

                    // 1) set stok awal gudang ke Stock Awal (sinkron)
                    $this->setPivotStock($produk->id, $gudang->id, $stockAwal);

                    // 2) buffer stock (Masuk) jika ada tanggal + jumlah
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

                    // 3) issued harian (Keluar) per tanggal: Lokasi + Jumlah
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

                    // 4) sinkron stok akhir gudang = Ending Stock (agar akurat)
                    $this->setPivotStock($produk->id, $gudang->id, $ending);
                } catch (\Throwable $e) {
                    $summary['errors'][] = "Baris Excel ke-" . ($r + 1) . ": " . $e->getMessage();
                }
            }
        });

        return $summary;
    }

    /* =================== READERS =================== */

    private function readCsv(string $path): array
    {
        $rows = [];
        $fh = fopen($path, 'r');
        if (! $fh) throw new \RuntimeException('Gagal membuka CSV.');

        // delimiter auto
        $first = fgets($fh);
        if ($first === false) return [];
        $delim = str_contains($first, ';') ? ';' : ',';
        rewind($fh);

        while (($data = fgetcsv($fh, 0, $delim)) !== false) {
            $rows[] = $data;
        }
        fclose($fh);

        return $rows;
    }

    private function readXlsxOrFail(string $path): array
    {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new \RuntimeException('PhpSpreadsheet tidak tersedia di server. Silakan upload CSV (Save As CSV dari Excel).');
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        return $sheet->toArray(null, true, false, false);
    }

    /* =================== HEADER PARSER =================== */

    private function findHeaderRows(array $rows): array
    {
        for ($i = 0; $i < min(count($rows), 50); $i++) {
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

    private function collectDatePairs(array $header, array $subHeader, int $from, int $to): array
    {
        $pairs = [];

        for ($i = $from; $i <= $to - 1; $i++) {
            $subA = mb_strtolower(trim((string) ($subHeader[$i] ?? '')));
            $subB = mb_strtolower(trim((string) ($subHeader[$i + 1] ?? '')));

            if ($subA !== 'lokasi' || $subB !== 'jumlah') {
                continue;
            }

            $date = $this->parseDate($header[$i] ?? null);
            if (! $date) continue;

            $pairs[] = [
                'date' => $date,
                'idx_lokasi' => $i,
                'idx_jumlah' => $i + 1,
            ];
        }

        return $pairs;
    }

    /* =================== BUSINESS =================== */

    private function toNumber($v): int
    {
        if ($v === null) return 0;
        if (is_numeric($v)) return (int) round((float) $v);
        $s = preg_replace('/[^\d\-]/', '', (string) $v);
        return (int) ($s === '' ? 0 : $s);
    }

    private function parseDate($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        $s = trim((string) $v);
        if ($s === '') return null;

        // coba parse umum: 2025-11-23, 23/11/2025, 23-11-2025
        $s2 = str_replace(['\\', '.'], '/', $s);

        // dd/mm/yyyy
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s2, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        // yyyy-mm-dd
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }

        return null;
    }

    private function getOrCreateLokasi(string $nama, array &$summary): Lokasi
    {
        $nama = trim($nama);
        if ($nama === '') $nama = 'Lokasi';

        $lok = Lokasi::query()->where('nama_lokasi', $nama)->first();
        if ($lok) return $lok;

        // kode_lokasi wajib
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

    private function getOrCreateKategori(string $nama): KategoriProduk
    {
        $nama = trim($nama);
        $slug = Str::slug($nama);

        return KategoriProduk::query()->firstOrCreate(
            ['slug' => $slug],
            ['nama' => $nama, 'slug' => $slug]
        );
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

        // lock gudang asal
        $pivotAsal = DB::table('produk_lokasi')
            ->where('produk_id', $produkId)
            ->where('lokasi_id', $gudangAsalId)
            ->lockForUpdate()
            ->first();

        $stokAwal = (int) ($pivotAsal->stok ?? 0);

        // kalau stok kurang, tetap dipaksakan? tidak, biar aman stop
        if ($stokAwal < $jumlah) {
            throw new \RuntimeException("Stok gudang tidak cukup untuk issued {$jumlah}. Stok: {$stokAwal}");
        }

        $stokAkhir = $stokAwal - $jumlah;

        DB::table('produk_lokasi')->updateOrInsert(
            ['produk_id' => $produkId, 'lokasi_id' => $gudangAsalId],
            ['stok' => $stokAkhir, 'updated_at' => now(), 'created_at' => $pivotAsal?->created_at ?? now()]
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
