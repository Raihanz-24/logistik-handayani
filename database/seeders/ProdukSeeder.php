<?php

namespace Database\Seeders;

use App\Models\KategoriProduk;
use App\Models\Produk;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProdukSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $kategoriIds = KategoriProduk::pluck('id')->toArray();

        Produk::factory(10)->create()->each(function ($produk) use ($kategoriIds) {
            $produk->kategoriProduks()->attach(
                collect($kategoriIds)->random(rand(1, 2))
            );
        });
    }
}
