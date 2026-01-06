<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\MutasiPoh1ImportService;

class ImportPoh1SingleWorkbookSeeder extends Seeder
{
    public function run(): void
    {
        // =============================
        // UBAH INI SESUAI FILE BARU KAMU
        // =============================
        $fileName = 'poh_des.xlsx'; // <- ganti sesuai nama file kamu
        $gudangName = 'Gudang POH 1';
        $actorUserId = 1;
        $fileKey = 'POH1';

        // =============================
        // PAKSA: HANYA DARI storage/app/imports/
        // =============================
        $filePath = storage_path("app/imports/{$fileName}");

        if (! is_file($filePath)) {
            $this->command?->error("File tidak ditemukan di imports: {$filePath}");
            $this->command?->warn("Isi folder storage/app/imports:");
            $dir = storage_path('app/imports');
            if (is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $it) {
                    if ($it === '.' || $it === '..') continue;
                    $this->command?->line(" - {$it}");
                }
            } else {
                $this->command?->line("Folder imports belum ada.");
            }
            return;
        }

        if (! is_readable($filePath)) {
            $this->command?->error("File ada tapi tidak bisa dibaca (permission): {$filePath}");
            return;
        }

        // Debug biar yakin file yang kebaca benar
        $this->command?->info("=== Import 1 workbook (FORCED imports/) ===");
        $this->command?->info("File  : {$filePath}");
        $this->command?->info("Size  : " . number_format(filesize($filePath)) . " bytes");
        $this->command?->info("MTime : " . date('Y-m-d H:i:s', filemtime($filePath)));

        $service = app(MutasiPoh1ImportService::class);

        $result = $service->import(
            absolutePath: $filePath,
            gudangName: $gudangName,
            actorUserId: $actorUserId,
            fileKey: $fileKey
        );

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
                $this->command?->warn(" ... (lebih banyak error, cek log)");
            }
        }
    }
}
