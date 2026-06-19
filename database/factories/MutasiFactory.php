<?php

namespace Database\Factories;

use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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
        $jenis = $this->faker->randomElement(['masuk', 'keluar']);

        return [
            'tanggal' => now(),
            'jenis_mutasi' => $jenis,
            'jumlah' => $this->faker->numberBetween(1, 50),
            'keterangan' => $this->faker->optional()->sentence(),
            'status' => $this->faker->randomElement(['pending', 'approved', 'cancelled']),
            'no_ref' => $this->faker->optional()->bothify('REF###'),
            'user_id' => User::factory(),
            'created_by' => User::factory(),
            'barang_id' => Barang::factory(),
            'lokasi_id' => Lokasi::factory()->state([
                'jenis_lokasi' => Lokasi::JENIS_GUDANG,
            ]),
            'lokasi_tujuan_id' => $jenis === 'keluar'
                ? Lokasi::factory()->state([
                    'jenis_lokasi' => Lokasi::JENIS_PEMAKAIAN,
                ])
                : null,
        ];
    }
}
