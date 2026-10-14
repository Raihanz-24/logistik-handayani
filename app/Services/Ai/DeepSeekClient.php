<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP ke gateway model AI (asumsi OpenAI-compatible).
 *
 * - Kredensial diambil dari config('ai.*') (berasal dari .env).
 * - TIDAK pernah men-log api_key atau body respons mentah.
 * - Bila gateway mengembalikan format berbeda, endpoint/path bisa disesuaikan
 *   lewat AI_CHAT_PATH / AI_MODELS_PATH tanpa mengubah kode ini.
 */
class DeepSeekClient
{
    /**
     * Kirim chat completion.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array{temperature?: float|null, max_tokens?: int|null}  $overrides
     * @return array{message: array<string, mixed>, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}, model: string}
     *
     * @throws AiClientException
     */
    public function chat(array $messages, array $tools = [], array $overrides = []): array
    {
        $this->assertConfigured();

        $temperature = $overrides['temperature'] ?? (float) config('ai.temperature', 0.2);
        $maxTokens = $overrides['max_tokens'] ?? (int) config('ai.max_tokens', 1024);

        $payload = [
            'model' => (string) config('ai.model'),
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = $this->request()->post($this->url('ai.chat_path'), $payload);
        } catch (ConnectionException $exception) {
            throw new AiClientException('Tidak dapat menghubungi layanan AI (koneksi). Coba lagi nanti.');
        } catch (RequestException $exception) {
            $status = $exception->response?->status();

            if ($status === 401 || $status === 403) {
                throw new AiClientException('Kunci API AI ditolak. Periksa AI_API_KEY.');
            }

            if ($status === 429) {
                throw new AiClientException('Layanan AI sedang sibuk (rate limit). Coba lagi sebentar.');
            }

            throw new AiClientException('Panggilan ke layanan AI gagal (HTTP '.($status ?? '?').').');
        }

        $data = $response->json();
        $message = $data['choices'][0]['message'] ?? null;

        if (! is_array($message)) {
            throw new AiClientException('Respons AI tidak berisi message yang valid.');
        }

        $usage = $data['usage'] ?? [];

        return [
            'message' => $message,
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
            'model' => (string) ($data['model'] ?? config('ai.model')),
        ];
    }

    /**
     * Daftar model yang tersedia (untuk verifikasi endpoint saat setup).
     *
     * @return array<int, string>
     */
    public function models(): array
    {
        $this->assertConfigured();

        try {
            $response = $this->request()->get($this->url('ai.models_path'));
        } catch (ConnectionException|RequestException $exception) {
            throw new AiClientException('Gagal mengambil daftar model dari layanan AI.');
        }

        return collect($response->json('data') ?? [])
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();
    }

    /**
     * Apakah kredensial & konfigurasi dasar sudah terisi.
     */
    public function isConfigured(): bool
    {
        return (bool) config('ai.enabled')
            && filled(config('ai.api_key'))
            && filled(config('ai.base_url'))
            && filled(config('ai.model'));
    }

    private function assertConfigured(): void
    {
        if (! config('ai.enabled')) {
            throw new AiClientException('Fitur Asisten AI sedang nonaktif (AI_ENABLED=false).');
        }

        if (blank(config('ai.api_key'))) {
            throw new AiClientException('AI_API_KEY belum diisi.');
        }

        if (blank(config('ai.base_url'))) {
            throw new AiClientException('AI_BASE_URL belum diisi.');
        }
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('ai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('ai.timeout', 60))
            ->connectTimeout((int) config('ai.connect_timeout', 10))
            ->retry(
                (int) config('ai.retry_times', 2),
                (int) config('ai.retry_sleep_ms', 500),
                throw: true,
            );
    }

    private function url(string $configKey): string
    {
        $path = (string) config($configKey, '');

        return rtrim((string) config('ai.base_url'), '/').'/'.ltrim($path, '/');
    }
}
