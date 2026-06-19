<?php

namespace Database\Factories;

use App\Models\Barang;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Barang>
 */
class BarangFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Barang::class;

    public function definition(): array
    {
        return [
            'nama_barang' => $this->faker->word(),
            'kode_barang' => strtoupper($this->faker->unique()->bothify('PRD###')),
            'satuan' => $this->faker->randomElement(['pcs', 'kg', 'liter']),
            'deskripsi' => $this->faker->sentence(),
        ];
    }
}
