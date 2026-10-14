<?php

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\PosisiRak;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data dummy untuk pengujian Asisten AI (dan manual).
 *
 * Membuat:
 * - 10 supplier aktif
 * - 10 barang
 * - stok awal tiap barang di setiap gudang
 *
 * Idempotent: aman dijalankan berulang (mencari berdasarkan nama).
 * TIDAK dipanggil otomatis oleh DatabaseSeeder agar tidak mengganggu produksi.
 *
 * Jalankan: php artisan db:seed --class="Database\Seeders\DemoDataSeeder"
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = [
            ['nama_supplier' => 'Toko Bu Ciko', 'kontak_person' => 'Bu Ciko', 'telepon' => '0331-000001', 'alamat' => 'Paiton'],
            ['nama_supplier' => 'PT Sumber Makmur', 'kontak_person' => 'Andi', 'telepon' => '0331-000002', 'alamat' => 'Probolinggo'],
            ['nama_supplier' => 'CV Berkah Jaya', 'kontak_person' => 'Budi', 'telepon' => '0331-000003', 'alamat' => 'Kraksaan'],
            ['nama_supplier' => 'Toko Indomaret Paiton', 'kontak_person' => 'Rina', 'telepon' => '0331-000004', 'alamat' => 'Paiton'],
            ['nama_supplier' => 'PT Air Sejahtera', 'kontak_person' => 'Dewi', 'telepon' => '0331-000005', 'alamat' => 'Probolinggo'],
            ['nama_supplier' => 'UD Sinar Abadi', 'kontak_person' => 'Agus', 'telepon' => '0331-000006', 'alamat' => 'Gending'],
            ['nama_supplier' => 'Toko Aneka Jaya', 'kontak_person' => 'Siti', 'telepon' => '0331-000007', 'alamat' => 'Pajarakan'],
            ['nama_supplier' => 'CV Sumber Rejeki', 'kontak_person' => 'Hendra', 'telepon' => '0331-000008', 'alamat' => 'Kraksaan'],
            ['nama_supplier' => 'PT Paiton Supply', 'kontak_person' => 'Maya', 'telepon' => '0331-000009', 'alamat' => 'Paiton'],
            ['nama_supplier' => 'Toko Murah Meriah', 'kontak_person' => 'Wawan', 'telepon' => '0331-000010', 'alamat' => 'Besuk'],
        ];

        foreach ($suppliers as $data) {
            Supplier::query()->firstOrCreate(
                ['nama_supplier' => $data['nama_supplier']],
                [...$data, 'aktif' => true],
            );
        }

        $barangs = [
            ['nama_barang' => 'Air Galon 19L', 'satuan' => 'galon'],
            ['nama_barang' => 'Sabun Cuci Piring', 'satuan' => 'botol'],
            ['nama_barang' => 'Deterjen Bubuk', 'satuan' => 'kg'],
            ['nama_barang' => 'Kertas Tissue', 'satuan' => 'pack'],
            ['nama_barang' => 'Sabun Mandi', 'satuan' => 'buah'],
            ['nama_barang' => 'Pasta Gigi', 'satuan' => 'tube'],
            ['nama_barang' => 'Shampoo Sachet', 'satuan' => 'pack'],
            ['nama_barang' => 'Sapu Lantai', 'satuan' => 'buah'],
            ['nama_barang' => 'Pel Lantai', 'satuan' => 'buah'],
            ['nama_barang' => 'Masker Medis', 'satuan' => 'box'],
        ];

        $gudangs = Lokasi::query()->gudang()->get();

        foreach ($barangs as $data) {
            $barang = Barang::query()->firstOrCreate(
                ['nama_barang' => $data['nama_barang']],
                $data,
            );

            foreach ($gudangs as $gudang) {
                // Gudang yang memakai rak wajib punya posisi_rak_id agar mutasi
                // keluar/perubahan kondisi bisa divalidasi.
                $posisiRakId = null;

                if ((bool) $gudang->menggunakan_rak) {
                    $opsi = PosisiRak::query()->aktif()
                        ->where('lokasi_id', $gudang->getKey())
                        ->orderBy('id')
                        ->pluck('id');

                    if ($opsi->isNotEmpty()) {
                        // Sebar barang merata ke rak yang tersedia.
                        $posisiRakId = (int) $opsi[($barang->getKey() + $gudang->getKey()) % $opsi->count()];
                    }
                }

                DB::table('barang_lokasi')->updateOrInsert(
                    [
                        'barang_id' => $barang->getKey(),
                        'lokasi_id' => $gudang->getKey(),
                    ],
                    [
                        'stok' => 50,
                        'stok_baik' => 50,
                        'stok_rusak' => 0,
                        'stok_hilang' => 0,
                        'posisi_rak_id' => $posisiRakId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        $this->command?->info(sprintf(
            'Demo data siap: %d supplier, %d barang, stok di %d gudang.',
            count($suppliers),
            count($barangs),
            $gudangs->count(),
        ));
    }
}
