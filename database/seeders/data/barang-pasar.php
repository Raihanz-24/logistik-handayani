<?php

/**
 * DAFTAR NAMA BARANG PASAR
 * ========================
 *
 * Isi file ini dengan nama-nama barang pasar, lalu jalankan seeder:
 *
 *   php artisan db:seed --class="Database\Seeders\BarangPasarSeeder"
 *
 * Setiap barang akan dibuat di master "Barang" dengan toggle
 * "Barang Pasar" = AKTIF, sehingga:
 *   - hanya dicatat pada transaksi belanja,
 *   - tidak masuk stok gudang,
 *   - tidak dihitung prioritas restock (SAW),
 *   - tidak muncul sebagai "stok hampir habis" di dashboard.
 *
 * ATURAN PENULISAN
 * ----------------
 * 1. Cukup tulis nama barang sebagai teks biasa (satu baris satu barang).
 *    Barang jenis ini tidak memakai satuan, jadi kolom satuan otomatis "-".
 *
 * 2. Bila ingin menyertakan satuan dan/atau alias AI, tulis dalam bentuk array:
 *    ['nama_barang' => 'Minyak Goreng', 'satuan' => 'liter', 'ai_alias' => 'minyak, minyak goreng']
 *
 * 3. Seeder aman dijalankan berulang (idempotent): barang yang namanya sudah ada
 *    tidak akan digandakan. Barang pasar yang sudah ada tidak akan terduplikasi.
 *
 * DAFTAR DI BAWAH INI
 * -------------------
 * Diambil dari dokumen "barang pasar" (storage/app/baraang pasar.docx),
 * sudah dirapikan dan duplikatnya dibuang. Silakan tambah/ubah sesuai kebutuhan.
 *
 * Satuan pasar yang bisa dipilih saat transaksi belanja:
 *   gr, kg, biji, buah, ekor, ikt (ikat), ppn (papan), btr (butir), bks (bungkus)
 * ----------------------------------------------------------------------------
 */

return [
    'AYAM BROILER',
    'TELUR AYAM',
    'TELUR ASIN',
    'IKAN PEE',
    'BAWANG PRE',
    'SERE',
    'BUNCIS',
    'SELADA',
    'TIMUN',
    'TOMAT',
    'KACANG PANJANG',
    'NANAS',
    'KANGKUNG',
    'TOGE PANJANG',
    'TOGE PENDEK',
    'TERONG',
    'DAUN BAWANG',
    'WORTEL',
    'TAHU',
    'PEPAYA',
    'MELON',
    'SEMANGKA',
    'JERUK PERAS',
    'DAUN PANDAN',
    'JAHE',
    'JERUK NIPIS',
    'TAPE',
    'GANDIK',
    'BUMBU PECEL',
    'MANGGA',
    'DAUN PISANG',
    'DAUN JERUK',
    'KELAPA PARUT',
    'CINCAU',
    'JERUK MANIS',
    'SAWI PUTIH',
    'PETIS UDANG',
    'CABE HIJAU BESAR',
    'JERUK LIMAU',
    'BRONGKOL',
    'KOLANG KALING',
    'KENTANG',
    'TELUR PUYUH',
    'BELIMBING WULUH',
    'TELUR AYAM KAMPUNG',
    'PISANG BIJI',
    'SELEDRI',
    'CENGKEH',
    'KAYU MANIS',
    'CUMI',
    'CABE MERAH BESAR',
    'CABE RAWIT',
    'BAWANG MERAH',
    'BAWANG PUTIH',
    'BAWANG BOMBAI',
    'KUNYIT',
    'KENCUR',
    'SAWI HIJAU',
    'MANISA',
    'JAGUNG MANIS',
    'KACANG TANAH',
    'GARAM KROSOK',
    'TEMPE',
    'GUBIS',
    'BAWANG GORENG',
    'KETUMBAR',
    'CABE RAWIT HIJAU',
    'TOMAT HIJAU',
    'BAWANG PREY',
    'LAOS',
    'KEMIRI',
    'IKAN ASIN',
    'PAPRIKA',
    'BENGKOANG',
    'TERI HALUS',
    'TELUR',
    'KUNCI',
    'IKAN TERI ASIN',
    'POLO',
    'KUNIR / KUNYIT',
    'GULA AREN',
    'JERUK SAMBAL',
    'CABE KECIL HIJAU',
    'CABE BESAR',
    'DAUN SELADA',
    'KEMANGI',
    'CABE HIJAU KECIL',
];
