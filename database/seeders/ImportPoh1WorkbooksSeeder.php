<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\MutasiPoh1ImportService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportPoh1WorkbooksSeeder extends Seeder
{
    public function run(): void
    {
        // ✅ Ambil user pelaku import (aktor)
        // Paling aman: pakai user pertama. Bisa kamu ganti sesuai kebutuhan.
        $actorUserId = User::query()->value('id');

        if (! $actorUserId) {
            throw new \RuntimeException('Tidak ada data user. Buat minimal 1 user dulu sebelum import.');
        }

        // ✅ Daftar 3 file excel (ubah nama file sesuai file kamu)
        $workbooks = [
            [
                'path'   => storage_path('app/imports/poh1/FILE1.xlsx'),
                'gudang' => 'Gudang POH 1',
            ],
            [
                'path'   => storage_path('app/imports/poh1/FILE2.xlsx'),
                'gudang' => 'Gudang POH 1',
            ],
            [
                'path'   => storage_path('app/imports/poh1/FILE3.xlsx'),
                'gudang' => 'Gudang POH 1',
            ],
        ];

        $service = app(MutasiPoh1ImportService::class);

        foreach ($workbooks as $i => $wb) {
            $filePath = $wb['path'];
            $gudang   = $wb['gudang'];

            if (! file_exists($filePath)) {
                $msg = "File tidak ditemukan: {$filePath}";
                $this->command?->error($msg);
                Log::error($msg);
                continue;
            }

            $this->command?->info("=== Import workbook #" . ($i + 1) . " ===");
            $this->command?->info("File  : {$filePath}");
            $this->command?->info("Gudang: {$gudang}");

            // ⚠️ Penting: Import ini akan MENAMBAH data. Jika seeder dijalankan ulang, data bisa dobel.
            // Kalau kamu ingin aman, kosongkan tabel dulu sebelum run (lihat Step 3).

            try {
                DB::disableQueryLog(); // hemat memori

                $result = $service->import(
                    absolutePath: $filePath,
                    gudangName: $gudang,
                    actorUserId: (int) $actorUserId
                );

                $this->command?->info("Sheets          : {$result['sheets']}");
                $this->command?->info("Rows processed   : {$result['rows']}");
                $this->command?->info("Produk upserted  : {$result['produk_upserted']}");
                $this->command?->info("Lokasi created   : {$result['lokasi_created']}");
                $this->command?->info("Mutasi created   : {$result['mutasi_created']}");

                if (! empty($result['errors'])) {
                    $this->command?->warn("Errors: " . count($result['errors']));
                    $sample = array_slice($result['errors'], 0, 5);
                    foreach ($sample as $err) {
                        $this->command?->warn(" - {$err}");
                    }

                    Log::warning('ImportPoh1WorkbooksSeeder errors', [
                        'file' => $filePath,
                        'errors_count' => count($result['errors']),
                        'errors_sample' => $sample,
                    ]);
                } else {
                    $this->command?->info("OK: Tidak ada error.");
                }
            } catch (\Throwable $e) {
                $this->command?->error("GAGAL import file: {$filePath}");
                $this->command?->error($e->getMessage());
                Log::error('ImportPoh1WorkbooksSeeder failed', [
                    'file' => $filePath,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
