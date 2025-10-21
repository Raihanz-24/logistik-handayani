<?php

namespace Database\Seeders;

use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\Produk;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class MutasiSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        $produks = Produk::all();
        $lokasis = Lokasi::all();

        foreach (range(1, 10) as $i) {
            $produk = $produks->random();
            $lokasi = $lokasis->random();
            $user = $users->random();

            $jumlah = rand(1, 20);
            $jenis = rand(0, 1) ? 'masuk' : 'keluar';

            Mutasi::create([
                'tanggal' => Carbon::now()->subDays(rand(1, 30)),
                'jenis_mutasi' => $jenis,
                'jumlah' => $jumlah,
                'keterangan' => 'Contoh mutasi ke-' . $i,
                'no_ref' => 'REF' . rand(1000, 9999),
                'status' => 'approved',
                'user_id' => $user->id,
                'created_by' => $user->id,
                'produk_id' => $produk->id,
                'lokasi_id' => $lokasi->id,
            ]);

            $currentStok = $produk->lokasi()->where('lokasi_id', $lokasi->id)->first()?->pivot->stok ?? 0;
            $newStok = $jenis === 'masuk' ? $currentStok + $jumlah : max(0, $currentStok - $jumlah);

            $produk->lokasi()->syncWithoutDetaching([
                $lokasi->id => ['stok' => $newStok]
            ]);
        }

    }
}
