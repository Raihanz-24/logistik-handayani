<?php

namespace App\Services\Ai;

use App\Models\AiTrainingExample;
use Illuminate\Support\Facades\Schema;

/**
 * Menyusun contoh pelatihan (distilasi) menjadi JSONL — format OpenAI
 * chat/function-calling — agar bisa diunduh dari UI dan di-fine-tune di
 * komputer lain.
 *
 * Read-only terhadap data transaksi: hanya membaca ai_training_examples.
 */
class AiTrainingExporter
{
    /** Apakah tabel contoh pelatihan tersedia. */
    public function available(): bool
    {
        return Schema::hasTable('ai_training_examples');
    }

    /** Jumlah contoh (opsional hanya yang berasal dari model cloud). */
    public function count(bool $onlyCloud = false): int
    {
        if (! $this->available()) {
            return 0;
        }

        return $this->query($onlyCloud)->count();
    }

    /**
     * Bangun isi berkas JSONL. Mengembalikan string kosong bila tidak ada data.
     */
    public function toJsonl(bool $onlyCloud = false, int $limit = 0): string
    {
        if (! $this->available()) {
            return '';
        }

        $query = $this->query($onlyCloud)->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $buffer = '';

        $query->chunk(200, function ($examples) use (&$buffer): void {
            foreach ($examples as $example) {
                $buffer .= $this->encode($example)."\n";
            }
        });

        return $buffer;
    }

    /** Nama berkas unduhan baku (bertanggal). */
    public function filename(bool $onlyCloud = false): string
    {
        return 'xcode-ai-dataset-'.($onlyCloud ? 'cloud-' : '').now()->format('Ymd-His').'.jsonl';
    }

    /** @return \Illuminate\Database\Eloquent\Builder<AiTrainingExample> */
    private function query(bool $onlyCloud): \Illuminate\Database\Eloquent\Builder
    {
        $query = AiTrainingExample::query();

        if ($onlyCloud) {
            $query->where('provider', 'like', '%Cloud%');
        }

        return $query;
    }

    /** Satu contoh -> satu baris JSON. */
    private function encode(AiTrainingExample $example): string
    {
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

        return (string) json_encode([
            'messages' => [
                ['role' => 'user', 'content' => (string) $example->input],
                $assistant,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
