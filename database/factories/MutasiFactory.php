<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Mutasi;
use App\Models\Produk;
use App\Models\Lokasi;
use App\Models\User;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Mutasi>
 */
class MutasiFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = Mutasi::class;

    public function definition(): array
    {
        return [
            'tanggal' => now(),
            'jenis_mutasi' => $this->faker->randomElement(['masuk', 'keluar']),
            'jumlah' => $this->faker->numberBetween(1, 50),
            'keterangan' => $this->faker->optional()->sentence(),
            'status' => $this->faker->randomElement(['pending', 'approved', 'cancelled']),
            'no_ref' => $this->faker->optional()->bothify('REF###'),
            'user_id' => User::factory(),
            'created_by' => User::factory(),
            'produk_id' => Produk::factory(),
            'lokasi_id' => Lokasi::factory(),
        ];
    }
}
