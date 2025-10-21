<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory(5)->create([
            'password' => Hash::make('12345678'),
        ]);

        User::create([
            'name' => 'Chaidar Saad',
            'email' => 'chaidarsaad55@gmail.com',
            'password' => Hash::make('12345678'),
        ]);

        $this->call([
            KategoriProdukSeeder::class,
            LokasiSeeder::class,
            ProdukSeeder::class,
            MutasiSeeder::class,
        ]);
    }
}
