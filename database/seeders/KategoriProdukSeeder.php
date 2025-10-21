<?php

namespace Database\Seeders;

use App\Models\KategoriProduk;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class KategoriProdukSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $kategori = ['Elektronik', 'Alat Tulis', 'Makanan', 'Minuman'];
        foreach ($kategori as $nama) {
            KategoriProduk::create(['nama' => $nama, 'slug' => str_replace(' ', '-', strtolower($nama))]);
        }
    }
}
