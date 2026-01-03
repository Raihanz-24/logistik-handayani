<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\MutasiPoh1ImportService;

class ImportPoh1SingleWorkbookSeeder extends Seeder
{
    public function run(): void
    {
        // set dari env saat run: POH1_FILE="imports/poh1/FILE1.xlsx"
        $relativePath = env('POH1_FILE', 'imports/poh1/FILE1.xlsx');
        $gudangName   = env('POH1_GUDANG', 'Gudang POH 1');
        $actorUserId  = (int) env('POH1_ACTOR_ID', 1);

        $abs = storage_path('app/' . ltrim($relativePath, '/'));

        $this->command?->info("=== Import 1 workbook ===");
        $this->command?->info("File  : {$abs}");
        $this->command?->info("Gudang: {$gudangName}");

        $result = app(MutasiPoh1ImportService::class)->import($abs, $gudangName, $actorUserId);

        $this->command?->info("Sheets          : " . ($result['sheets'] ?? 0));
        $this->command?->info("Rows processed   : " . ($result['rows'] ?? 0));
        $this->command?->info("Produk upserted  : " . ($result['produk_upserted'] ?? 0));
        $this->command?->info("Lokasi created   : " . ($result['lokasi_created'] ?? 0));
        $this->command?->info("Mutasi created   : " . ($result['mutasi_created'] ?? 0));

        $errors = $result['errors'] ?? [];
        $this->command?->info("Errors: " . count($errors));
        foreach (array_slice($errors, 0, 10) as $e) {
            $this->command?->warn(" - " . $e);
        }
        if (count($errors) > 10) {
            $this->command?->warn(" ... (lebih banyak error, cek log/ulang perbaikan)");
        }
    }
}
