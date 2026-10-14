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
 * CONTOH (boleh dihapus / ganti dengan daftar Anda):
 * ----------------------------------------------------------------------------
 */

return [
    // 'Beras',
    // 'Gula Pasir',
    // 'Minyak Goreng',
    // 'Telur Ayam',
    // 'Bawang Merah',
    // 'Bawang Putih',
    // 'Cabai Rawit',
    // 'Tomat',
    //
    // // Contoh dengan satuan & alias:
    // ['nama_barang' => 'Ayam Potong', 'satuan' => 'ekor', 'ai_alias' => 'ayam, daging ayam'],
    // ['nama_barang' => 'Ikan Segar', 'satuan' => 'kg', 'ai_alias' => 'ikan'],
];
