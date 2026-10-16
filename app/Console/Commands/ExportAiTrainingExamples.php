<?php

namespace App\Console\Commands;

use App\Models\AiTrainingExample;
use App\Services\Ai\AiTrainingExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ekspor contoh pelatihan (distilasi) ke JSONL untuk fine-tune model lokal.
 *
 * Format tiap baris (OpenAI chat / function-calling):
 * {"messages":[
 *    {"role":"user","content":"<perintah>"},
 *    {"role":"assistant","content":"<jawaban>","tool_calls":[{"type":"function","function":{"name":"cari_barang","arguments":"{...}"}}]}
 * ]}
 *
 * Aman & read-only terhadap data transaksi: hanya membaca ai_training_examples
 * (opsional menandai sudah diekspor). Tidak pernah mengubah data belanja/stok.
 */
class ExportAiTrainingExamples extends Command
{
    protected $signature = 'ai:export-training
        {--path=storage/app/ai-training/dataset.jsonl : Lokasi berkas keluaran}
        {--limit=0 : Batasi jumlah baris (0 = semua)}
        {--only-cloud : Hanya contoh yang dihasilkan model cloud}
        {--mark-exported : Tandai baris yang diekspor sebagai sudah diekspor}';

    protected $description = 'Ekspor contoh pelatihan AI ke JSONL (untuk fine-tune model lokal).';

    public function handle(AiTrainingExporter $exporter): int
    {
        if (! Schema::hasTable('ai_training_examples')) {
            $this->warn('Tabel ai_training_examples belum ada. Jalankan: php artisan migrate');

            return self::SUCCESS;
        }

        $onlyCloud = (bool) $this->option('only-cloud');
        $limit = (int) $this->option('limit');

        if ($exporter->count($onlyCloud) === 0) {
            $this->warn('Belum ada contoh pelatihan. Pakai Asisten dulu (dengan model cloud) agar data terkumpul.');

            return self::SUCCESS;
        }

        $jsonl = $exporter->toJsonl($onlyCloud, $limit);
        $path = (string) $this->option('path');

        if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            $path = base_path($path);
        }

        $dir = dirname($path);

        try {
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $handle = fopen($path, 'wb');

            if ($handle === false) {
                throw new \RuntimeException('Tidak bisa menulis berkas: '.$path);
            }

            fwrite($handle, $jsonl);
            fclose($handle);

            if ($this->option('mark-exported')) {
                AiTrainingExample::query()
                    ->when($onlyCloud, fn ($q) => $q->where('provider', 'like', '%Cloud%'))
                    ->update(['exported' => true]);
            }

            $baris = $jsonl === '' ? 0 : substr_count($jsonl, "\n");

            $this->info("Selesai. {$baris} contoh diekspor ke: {$path}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Gagal mengekspor: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
