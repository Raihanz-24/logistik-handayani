<?php

namespace Database\Seeders;

use App\Models\KategoriProduk;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\Produk;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SawRestockMay2026Seeder extends Seeder
{
    private const REFERENCE_PREFIX = 'SAW-MEI26-';

    public function run(): void
    {
        $actor = User::query()->where('email', 'admin@local.test')->first()
            ?? User::query()->first();

        if (! $actor) {
            throw new RuntimeException('Buat minimal satu user sebelum menjalankan seeder SAW.');
        }

        DB::transaction(function () use ($actor): void {
            $category = KategoriProduk::query()->firstOrCreate(
                ['slug' => 'dummy-saw'],
                ['nama' => 'Dummy SPK SAW'],
            );
            $locations = $this->createLocations();
            $products = $this->productProfiles();

            Mutasi::query()
                ->where('no_ref', 'like', self::REFERENCE_PREFIX.'%')
                ->delete();

            foreach ($products as $index => $profile) {
                $product = Produk::query()->updateOrCreate(
                    ['kode_produk' => $profile['code']],
                    [
                        'nama_produk' => $profile['name'],
                        'satuan' => $profile['unit'],
                        'deskripsi' => 'Data dummy pengujian rekomendasi restock metode SAW.',
                        'harga_beli' => $profile['purchase_price'],
                        'harga_jual' => $profile['selling_price'],
                        'barcode' => '202605'.str_pad((string) ($index + 1), 7, '0', STR_PAD_LEFT),
                    ],
                );

                DB::table('produks')
                    ->where('id', $product->id)
                    ->update(['kategori_produk_id' => $category->id]);

                $product->kategoriProduks()->syncWithoutDetaching([$category->id]);

                $location = $locations[$index % $locations->count()];
                $usages = $this->generateUsages(
                    $index + 1,
                    $profile['frequency'],
                    $profile['minimum_usage'],
                    $profile['maximum_usage'],
                );
                $totalUsage = array_sum(array_column($usages, 'quantity'));
                $initialStock = $profile['final_stock'] + $totalUsage;

                DB::table('produk_lokasi')
                    ->where('produk_id', $product->id)
                    ->delete();

                DB::table('produk_lokasi')->insert([
                    'produk_id' => $product->id,
                    'lokasi_id' => $location->id,
                    'stok' => $profile['final_stock'],
                    'created_at' => Carbon::parse('2026-05-01 08:00:00'),
                    'updated_at' => Carbon::parse('2026-05-31 17:00:00'),
                ]);

                $this->createApprovedMutation(
                    product: $product,
                    location: $location,
                    actor: $actor,
                    date: Carbon::parse('2026-05-01 08:00:00'),
                    type: 'masuk',
                    quantity: $initialStock,
                    stockBefore: 0,
                    stockAfter: $initialStock,
                    reference: self::REFERENCE_PREFIX.$profile['code'].'-IN',
                    description: 'Stok awal dummy periode Mei 2026.',
                );

                $runningStock = $initialStock;

                foreach ($usages as $usageIndex => $usage) {
                    $stockBefore = $runningStock;
                    $runningStock -= $usage['quantity'];

                    $this->createApprovedMutation(
                        product: $product,
                        location: $location,
                        actor: $actor,
                        date: $usage['date'],
                        type: 'keluar',
                        quantity: $usage['quantity'],
                        stockBefore: $stockBefore,
                        stockAfter: $runningStock,
                        reference: sprintf(
                            '%s%s-OUT-%02d',
                            self::REFERENCE_PREFIX,
                            $profile['code'],
                            $usageIndex + 1,
                        ),
                        description: 'Pemakaian barang dummy untuk pengujian SAW.',
                    );
                }
            }
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, Lokasi>
     */
    private function createLocations()
    {
        return collect([
            ['code' => 'SAW-GD-01', 'name' => 'Gudang Utama SAW'],
            ['code' => 'SAW-GD-02', 'name' => 'Gudang Workshop SAW'],
            ['code' => 'SAW-GD-03', 'name' => 'Gudang Cadangan SAW'],
        ])->map(fn (array $location): Lokasi => Lokasi::query()->updateOrCreate(
            ['kode_lokasi' => $location['code']],
            [
                'nama_lokasi' => $location['name'],
                'alamat' => 'Lokasi dummy untuk pengujian SPK SAW',
                'keterangan' => 'Data testing Mei 2026',
            ],
        ));
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function productProfiles(): array
    {
        return [
            ['code' => 'SAW-P001', 'name' => 'Bearing 6204', 'unit' => 'pcs', 'frequency' => 15, 'minimum_usage' => 8, 'maximum_usage' => 15, 'final_stock' => 3, 'purchase_price' => 45000, 'selling_price' => 60000],
            ['code' => 'SAW-P002', 'name' => 'Filter Oli Mesin', 'unit' => 'pcs', 'frequency' => 13, 'minimum_usage' => 6, 'maximum_usage' => 12, 'final_stock' => 5, 'purchase_price' => 35000, 'selling_price' => 48000],
            ['code' => 'SAW-P003', 'name' => 'V-Belt A-42', 'unit' => 'pcs', 'frequency' => 11, 'minimum_usage' => 5, 'maximum_usage' => 10, 'final_stock' => 2, 'purchase_price' => 52000, 'selling_price' => 68000],
            ['code' => 'SAW-P004', 'name' => 'Oli Hidrolik ISO 46', 'unit' => 'liter', 'frequency' => 10, 'minimum_usage' => 8, 'maximum_usage' => 16, 'final_stock' => 8, 'purchase_price' => 42000, 'selling_price' => 55000],
            ['code' => 'SAW-P005', 'name' => 'Baut Hexagonal M12', 'unit' => 'pcs', 'frequency' => 9, 'minimum_usage' => 10, 'maximum_usage' => 20, 'final_stock' => 12, 'purchase_price' => 4000, 'selling_price' => 6500],
            ['code' => 'SAW-P006', 'name' => 'Lampu Indikator 24V', 'unit' => 'pcs', 'frequency' => 7, 'minimum_usage' => 3, 'maximum_usage' => 8, 'final_stock' => 4, 'purchase_price' => 28000, 'selling_price' => 40000],
            ['code' => 'SAW-P007', 'name' => 'Aki Kering 12V 7Ah', 'unit' => 'pcs', 'frequency' => 6, 'minimum_usage' => 2, 'maximum_usage' => 5, 'final_stock' => 1, 'purchase_price' => 180000, 'selling_price' => 225000],
            ['code' => 'SAW-P008', 'name' => 'Selang Pneumatic 8mm', 'unit' => 'meter', 'frequency' => 5, 'minimum_usage' => 4, 'maximum_usage' => 9, 'final_stock' => 15, 'purchase_price' => 12000, 'selling_price' => 18000],
            ['code' => 'SAW-P009', 'name' => 'Seal O-Ring NBR', 'unit' => 'pcs', 'frequency' => 4, 'minimum_usage' => 2, 'maximum_usage' => 6, 'final_stock' => 6, 'purchase_price' => 3000, 'selling_price' => 5000],
            ['code' => 'SAW-P010', 'name' => 'Sarung Tangan Mekanik', 'unit' => 'pasang', 'frequency' => 3, 'minimum_usage' => 10, 'maximum_usage' => 25, 'final_stock' => 20, 'purchase_price' => 22000, 'selling_price' => 32000],
        ];
    }

    /**
     * @return array<int, array{date: Carbon, quantity: int}>
     */
    private function generateUsages(
        int $seed,
        int $frequency,
        int $minimumUsage,
        int $maximumUsage,
    ): array {
        $days = range(2, 31);
        usort($days, fn (int $first, int $second): int => $this->pseudoRandom(
            "{$seed}-day-{$first}",
        ) <=> $this->pseudoRandom("{$seed}-day-{$second}"));
        $days = array_slice($days, 0, $frequency);
        sort($days);

        return array_map(
            fn (int $day): array => [
                'date' => Carbon::create(
                    2026,
                    5,
                    $day,
                    8 + ($this->pseudoRandom("{$seed}-hour-{$day}") % 9),
                    $this->pseudoRandom("{$seed}-minute-{$day}") % 60,
                ),
                'quantity' => $minimumUsage + (
                    $this->pseudoRandom("{$seed}-quantity-{$day}")
                    % ($maximumUsage - $minimumUsage + 1)
                ),
            ],
            $days,
        );
    }

    private function pseudoRandom(string $value): int
    {
        return (int) sprintf('%u', crc32("202605-{$value}"));
    }

    private function createApprovedMutation(
        Produk $product,
        Lokasi $location,
        User $actor,
        Carbon $date,
        string $type,
        int $quantity,
        int $stockBefore,
        int $stockAfter,
        string $reference,
        string $description,
    ): void {
        Mutasi::query()->create([
            'tanggal' => $date->toDateString(),
            'jenis_mutasi' => $type,
            'jumlah' => $quantity,
            'stok_awal' => $stockBefore,
            'stok_akhir' => $stockAfter,
            'keterangan' => $description,
            'no_ref' => $reference,
            'status' => 'approved',
            'user_id' => $actor->id,
            'created_by' => $actor->id,
            'approved_by' => $actor->id,
            'approved_at' => $date,
            'produk_id' => $product->id,
            'lokasi_id' => $location->id,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }
}
