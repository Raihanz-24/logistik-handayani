<?php

namespace Database\Factories;

use App\Models\Lokasi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Lokasi>
 */
class LokasiFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode_lokasi' => strtoupper(fake()->unique()->lexify('LOK???')),
            'nama_lokasi' => fake()->company(),
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
            'alamat' => fake()->address(),
            'keterangan' => fake()->sentence(),
        ];
    }
}
