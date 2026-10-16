<?php

namespace App\Console\Commands;

use App\Models\AiTrainingExample;
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

    public function handle(): int
    {
        if (! Schema::hasTable('ai_training_examples')) {
            $this->warn('Tabel ai_training_examples belum ada. Jalankan: php artisan migrate');

            return self::SUCCESS;
        }

        $query = AiTrainingExample::query()->orderBy('id');

        if ($this->option('only-cloud')) {
            $query->where('provider', 'like', '%Cloud%');
        }

        if ((int) $this->option('limit') > 0) {
            $query->limit((int) $this->option('limit'));
        }

        $examples = $query->get();

        if ($examples->isEmpty()) {
            $this->warn('Belum ada contoh pelatihan. Pakai Asisten dulu (dengan model cloud) agar data terkumpul.');

            return self::SUCCESS;
        }

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

            $baris = 0;

            foreach ($examples as $example) {
                $toolCalls = collect($example->actions ?? [])
                    ->filter(fn (array $a): bool => filled($a['name'] ?? null))
                    ->map(fn (array $a): array => [
                        'type' => 'function',
                        'function' => [
                            'name' => (string) $a['name'],
                            'arguments' => json_encode($a['arguments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ])
                    ->values()
                    ->all();

                $assistant = ['role' => 'assistant', 'content' => (string) ($example->output ?? '')];

                if ($toolCalls !== []) {
                    $assistant['tool_calls'] = $toolCalls;
                }

                $line = json_encode([
                    'messages' => [
                        ['role' => 'user', 'content' => (string) $example->input],
                        $assistant,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                fwrite($handle, $line."\n");
                $baris++;
            }

            fclose($handle);

            if ($this->option('mark-exported')) {
                AiTrainingExample::query()
                    ->whereIn('id', $examples->pluck('id'))
                    ->update(['exported' => true]);
            }

            $this->info("Selesai. {$baris} contoh diekspor ke: {$path}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Gagal mengekspor: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
