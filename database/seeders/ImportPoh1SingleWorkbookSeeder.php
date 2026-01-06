<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Services\MutasiPoh1ImportService;

class ImportPoh1SingleWorkbookSeeder extends Seeder
{
    public function run(): void
    {
        // =============================
        // 1) SETTING YANG KAMU UBAH SAJA
        // =============================

        // Nama file yang mau diimport (sesuaikan nama persis)
        $fileName = 'FILE1.xlsx';

        // Nama gudang
        $gudangName = 'Gudang POH 1';

        // actor user untuk created_by / approved_by
        $actorUserId = 1;

        // fileKey stabil (untuk kode produk & no_ref hash)
        $fileKey = 'POH1';

        // =============================
        // 2) AUTO-DETECT PATH
        // =============================

        $candidates = [
            storage_path("app/imports/{$fileName}"),
            storage_path("app/imports/poh1/{$fileName}"),
            storage_path("app/{$fileName}"),
            base_path("storage/app/imports/{$fileName}"),
            base_path("storage/app/imports/poh1/{$fileName}"),
        ];

        $filePath = null;
        foreach ($candidates as $p) {
            if (is_file($p) && is_readable($p)) {
                $filePath = $p;
                break;
            }
        }

        if (! $filePath) {
            $this->command?->error("File tidak ditemukan: {$fileName}");
            $this->command?->warn("Saya sudah cek path kandidat berikut:");
            foreach ($candidates as $p) {
                $this->command?->line(" - {$p}");
            }

            // tampilkan isi folder imports biar kelihatan file mana yang ada
            $importsDir = storage_path('app/imports');
            $this->command?->warn("Isi folder {$importsDir}:");
            if (is_dir($importsDir)) {
                $items = scandir($importsDir) ?: [];
                foreach ($items as $it) {
                    if ($it === '.' || $it === '..') continue;
                    $this->command?->line(" - {$it}");
                }
            } else {
                $this->command?->line("Folder imports belum ada.");
            }

            $poh1Dir = storage_path('app/imports/poh1');
            $this->command?->warn("Isi folder {$poh1Dir}:");
            if (is_dir($poh1Dir)) {
                $items = scandir($poh1Dir) ?: [];
                foreach ($items as $it) {
                    if ($it === '.' || $it === '..') continue;
                    $this->command?->line(" - {$it}");
                }
            } else {
                $this->command?->line("Folder imports/poh1 belum ada.");
            }

            return;
        }

        // =============================
        // 3) RUN IMPORT
        // =============================

        $service = app(MutasiPoh1ImportService::class);

        $result = $service->import(
            absolutePath: $filePath,
            gudangName: $gudangName,
            actorUserId: $actorUserId,
            fileKey: $fileKey
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
