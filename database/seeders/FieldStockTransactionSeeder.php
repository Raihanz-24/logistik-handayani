<?php

namespace Database\Seeders;

use App\Models\Barang;
use App\Models\KategoriBarang;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class FieldStockTransactionSeeder extends Seeder
{
    private const SEED_PREFIX = 'FIELD-2026';

    /**
     * Seed real field item/location names and deterministic random transactions.
     */
    public function run(): void
    {
        $data = require database_path('seeders/data/field-stock-december-2025.php');
        $actor = User::query()->first();

        if (! $actor) {
            throw new RuntimeException('Jalankan SuperAdminSeeder atau buat user terlebih dahulu sebelum FieldStockTransactionSeeder.');
        }

        DB::transaction(function () use ($data, $actor): void {
            $warehouses = $this->seedWarehouses();
            $usageLocations = $this->seedUsageLocations($data['lokasi_pemakaian']);
            $barangs = $this->seedBarangs($data['barangs']);

            Mutasi::query()
                ->where('no_ref', 'like', self::SEED_PREFIX.'-%')
                ->delete();

            DB::table('barang_lokasi')
                ->whereIn('barang_id', $barangs->pluck('id'))
                ->whereIn('lokasi_id', $warehouses->pluck('id'))
                ->delete();

            $this->seedTransactions(
                barangs: $barangs,
                warehouses: $warehouses,
                usageLocations: $usageLocations,
                actor: $actor,
            );
        });
    }

    private function seedWarehouses()
    {
        return collect([
            [
                'kode_lokasi' => 'GDG-POH-1',
                'nama_lokasi' => 'Warehouse POH 1',
                'keterangan' => 'Gudang buffer stock POH 1.',
            ],
            [
                'kode_lokasi' => 'GDG-POH-2',
                'nama_lokasi' => 'Warehouse POH 2',
                'keterangan' => 'Gudang buffer stock POH 2.',
            ],
        ])->map(fn (array $warehouse): Lokasi => Lokasi::query()->updateOrCreate(
            ['kode_lokasi' => $warehouse['kode_lokasi']],
            [
                'nama_lokasi' => $warehouse['nama_lokasi'],
                'jenis_lokasi' => Lokasi::JENIS_GUDANG,
                'alamat' => 'PT ISS Indonesia Area Paiton Energy',
                'keterangan' => $warehouse['keterangan'],
            ],
        ))->values();
    }

    private function seedUsageLocations(array $locationNames)
    {
        return collect($locationNames)
            ->values()
            ->map(fn (string $name, int $index): Lokasi => Lokasi::query()->updateOrCreate(
                ['kode_lokasi' => 'LKP-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)],
                [
                    'nama_lokasi' => Str::title($name),
                    'jenis_lokasi' => Lokasi::JENIS_PEMAKAIAN,
                    'alamat' => 'Area pemakaian Paiton Energy',
                    'keterangan' => 'Lokasi pemakaian barang habis pakai.',
                ],
            ))
            ->values();
    }

    private function seedBarangs(array $items)
    {
        return collect($items)
            ->values()
            ->map(function (array $item, int $index): Barang {
                $categoryName = trim((string) $item['kategori']) !== '-'
                    ? trim((string) $item['kategori'])
                    : 'LAINNYA';

                $category = KategoriBarang::query()->firstOrCreate(
                    ['slug' => Str::slug($categoryName)],
                    ['nama' => $categoryName],
                );

                $code = 'BRG-POMI-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
                $payload = [
                    'nama_barang' => $item['nama_barang'],
                    'satuan' => $item['satuan'] ?: 'Pcs',
                    'deskripsi' => 'Data barang dari stock POMI Desember 2025.',
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('barangs', 'kategori')) {
                    $payload['kategori'] = $categoryName;
                }

                if (Schema::hasColumn('barangs', 'kategori_barang_id')) {
                    $payload['kategori_barang_id'] = $category->id;
                }

                $barang = Barang::query()
                    ->where('kode_barang', $code)
                    ->orWhere('nama_barang', $item['nama_barang'])
                    ->first();

                if ($barang) {
                    $barang->update($payload);
                } else {
                    $barang = Barang::query()->create($payload);
                }

                DB::table('barang_kategori_barang')->insertOrIgnore([
                    'barang_id' => $barang->id,
                    'kategori_barang_id' => $category->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $barang->fresh();
            })
            ->values();
    }

    private function seedTransactions($barangs, $warehouses, $usageLocations, User $actor): void
    {
        mt_srand(20260620);

        $stocks = [];
        $sequence = 1;
        $startDate = CarbonImmutable::create(2026, 1, 1);
        $endDate = CarbonImmutable::create(2026, 6, 20);

        foreach ($barangs as $barang) {
            foreach ($warehouses as $warehouse) {
                $quantity = mt_rand(18, 120);
                $stocks[$barang->id][$warehouse->id] = 0;
                $this->recordApprovedMutation(
                    sequence: $sequence++,
                    date: $startDate->addDays(mt_rand(0, 6)),
                    type: 'masuk',
                    barang: $barang,
                    warehouse: $warehouse,
                    destination: null,
                    quantity: $quantity,
                    stocks: $stocks,
                    actor: $actor,
                    note: 'Stok awal hasil input data lapangan.',
                );
            }
        }

        for ($date = $startDate->addDay(); $date->lte($endDate); $date = $date->addDay()) {
            $dailyTransactions = mt_rand(5, 11);

            for ($i = 0; $i < $dailyTransactions; $i++) {
                $barang = $barangs->random();
                $warehouse = $warehouses->random();
                $currentStock = (int) ($stocks[$barang->id][$warehouse->id] ?? 0);
                $isInbound = mt_rand(1, 100) <= 28 || $currentStock < 8;

                if ($isInbound) {
                    $this->recordApprovedMutation(
                        sequence: $sequence++,
                        date: $date,
                        type: 'masuk',
                        barang: $barang,
                        warehouse: $warehouse,
                        destination: null,
                        quantity: mt_rand(8, 45),
                        stocks: $stocks,
                        actor: $actor,
                        note: 'Restock gudang dari pengadaan.',
                    );

                    continue;
                }

                $quantity = min($currentStock, mt_rand(1, 8));

                if ($quantity < 1) {
                    continue;
                }

                $destination = mt_rand(1, 100) <= 12
                    ? $warehouses->where('id', '!=', $warehouse->id)->random()
                    : $usageLocations->random();

                $this->recordApprovedMutation(
                    sequence: $sequence++,
                    date: $date,
                    type: 'keluar',
                    barang: $barang,
                    warehouse: $warehouse,
                    destination: $destination,
                    quantity: $quantity,
                    stocks: $stocks,
                    actor: $actor,
                    note: $destination->isGudang()
                        ? 'Transfer antar gudang.'
                        : 'Pemakaian barang habis pakai di lokasi.',
                );
            }
        }

        foreach ($stocks as $barangId => $warehouseStocks) {
            foreach ($warehouseStocks as $warehouseId => $stock) {
                DB::table('barang_lokasi')->updateOrInsert(
                    ['barang_id' => $barangId, 'lokasi_id' => $warehouseId],
                    [
                        'stok' => $stock,
                        'stok_baik' => $stock,
                        'stok_rusak' => 0,
                        'stok_hilang' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    private function recordApprovedMutation(
        int $sequence,
        CarbonImmutable $date,
        string $type,
        Barang $barang,
        Lokasi $warehouse,
        ?Lokasi $destination,
        int $quantity,
        array &$stocks,
        User $actor,
        string $note,
    ): void {
        $stockBefore = (int) ($stocks[$barang->id][$warehouse->id] ?? 0);
        $stockAfter = $type === 'masuk'
            ? $stockBefore + $quantity
            : $stockBefore - $quantity;

        $stocks[$barang->id][$warehouse->id] = $stockAfter;

        if ($type === 'keluar' && $destination?->isGudang()) {
            $stocks[$barang->id][$destination->id] = (int) ($stocks[$barang->id][$destination->id] ?? 0) + $quantity;
        }

        Mutasi::query()->create([
            'tanggal' => $date->toDateString(),
            'jenis_mutasi' => $type,
            'kondisi_asal' => $type === 'masuk' ? null : Mutasi::KONDISI_BAIK,
            'kondisi_tujuan' => Mutasi::KONDISI_BAIK,
            'jumlah' => $quantity,
            'stok_awal' => $stockBefore,
            'stok_akhir' => $stockAfter,
            'stok_kondisi_asal_awal' => $type === 'masuk' ? null : $stockBefore,
            'stok_kondisi_asal_akhir' => $type === 'masuk' ? null : $stockAfter,
            'stok_kondisi_tujuan_awal' => $type === 'masuk' ? $stockBefore : null,
            'stok_kondisi_tujuan_akhir' => $type === 'masuk' ? $stockAfter : null,
            'keterangan' => $note,
            'no_ref' => self::SEED_PREFIX.'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
            'status' => 'approved',
            'user_id' => $actor->id,
            'created_by' => $actor->id,
            'approved_by' => $actor->id,
            'approved_at' => $date->setTime(mt_rand(8, 16), mt_rand(0, 59)),
            'barang_id' => $barang->id,
            'lokasi_id' => $warehouse->id,
            'lokasi_tujuan_id' => $destination?->id,
            'created_at' => $date->setTime(mt_rand(8, 16), mt_rand(0, 59)),
            'updated_at' => now(),
        ]);
    }
}
