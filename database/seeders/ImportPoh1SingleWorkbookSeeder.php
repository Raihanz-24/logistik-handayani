<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\MutasiPoh1ImportService;

class ImportPoh1SingleWorkbookSeeder extends Seeder
{
    public function run(): void
    {
        // ✅ Ubah ini saja tiap kali mau import file berbeda
        $filePath = storage_path('app/imports/poh1/FILE1.xlsx');

        // Nama gudang sesuai kebutuhan
        $gudangName = 'Gudang POH 1';

        // actor user untuk created_by / approved_by
        $actorUserId = 1;

        $service = app(MutasiPoh1ImportService::class);

        $result = $service->import(
            absolutePath: $filePath,
            gudangName: $gudangName,
            actorUserId: $actorUserId,
            fileKey: 'POH1' // bebas, tapi stabil (untuk kode produk & no_ref hash)
        );

        $this->command?->info("=== Import 1 workbook ===");
        $this->command?->info("File  : {$filePath}");
        $this->command?->info("Gudang: {$gudangName}");
        $this->command?->info("Sheets          : {$result['sheets']}");
        $this->command?->info("Rows processed   : {$result['rows']}");
        $this->command?->info("Produk upserted  : {$result['produk_upserted']}");
        $this->command?->info("Lokasi created   : {$result['lokasi_created']}");
        $this->command?->info("Kategori created : {$result['kategori_created']}");
        $this->command?->info("Mutasi created   : {$result['mutasi_created']}");

        if (! empty($result['errors'])) {
            $this->command?->warn("Errors: " . count($result['errors']));
            foreach (array_slice($result['errors'], 0, 20) as $err) {
                $this->command?->warn(" - {$err}");
            }
            if (count($result['errors']) > 20) {
                $this->command?->warn(" ... (lebih banyak error, cek log/ulang perbaikan)");
            }
        }
    }
}
