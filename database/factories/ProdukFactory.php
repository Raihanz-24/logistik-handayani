<?php

namespace Database\Factories;
use App\Models\Produk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Produk>
 */
class ProdukFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Produk::class;

    public function definition(): array
    {
        return [
            'nama_produk' => $this->faker->word(),
            'kode_produk' => strtoupper($this->faker->unique()->bothify('PRD###')),
            'satuan' => $this->faker->randomElement(['pcs', 'kg', 'liter']),
            'deskripsi' => $this->faker->sentence(),
            'harga_beli' => $this->faker->numberBetween(10000, 100000),
            'harga_jual' => $this->faker->numberBetween(110000, 200000),
            'barcode' => $this->faker->unique()->ean13(),
            'gambar' => null, // atau path dummy
        ];
    }
}
