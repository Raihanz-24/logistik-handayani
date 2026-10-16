<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP ke gateway model AI (OpenAI-compatible), sadar PROFIL.
 *
 * - Mendukung dua profil: 'local' (Xcode AI sendiri) & 'cloud' (gateway).
 * - Kredensial selalu dari config('ai.*') (berasal dari .env).
 * - TIDAK pernah men-log api_key atau body respons mentah.
 * - `chatRouted()` mencoba profil utama lalu backup (untuk AI_DRIVER=auto).
 */
class DeepSeekClient
{
    /** @param array<string, mixed>|null $profile Profil tetap; null = resolusi dinamis. */
    public function __construct(private ?array $profile = null) {}

    /**
     * Kirim chat completion memakai profil utama.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array{temperature?: float|null, max_tokens?: int|null}  $overrides
     * @return array{message: array<string, mixed>, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int}, model: string, provider_name: string, provider_label: string}
     *
     * @throws AiClientException
     */
    public function chat(array $messages, array $tools = [], array $overrides = []): array
    {
        return $this->chatWithProfile($this->resolveProfile(), $messages, $tools, $overrides);
    }

    /**
     * Seperti chat(), namun mencoba profil UTAMA lalu BACKUP bila tersedia
     * (dipakai saat AI_DRIVER=auto). Mengembalikan profil mana yang akhirnya
     * berhasil lewat field provider_name/provider_label.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array{temperature?: float|null, max_tokens?: int|null}  $overrides
     * @return array<string, mixed>
     *
     * @throws AiClientException
     */
    public function chatRouted(array $messages, array $tools = [], array $overrides = []): array
    {
        $config = $this->config();
        $primary = $config->primary();
        $fallback = $config->fallback();

        try {
            return $this->chatWithProfile($primary, $messages, $tools, $overrides);
        } catch (AiClientException $exception) {
            if ($fallback === null) {
                throw $exception;
            }

            $config->isConfigured($fallback['name'] ?? 'cloud') || throw $exception;

            return $this->chatWithProfile($fallback, $messages, $tools, $overrides);
        }
    }

    /**
     * Uji & daftar model untuk SATU profil (untuk halaman status/uJi koneksi).
     *
     * @return array<int, string>
     *
     * @throws AiClientException
     */
    public function modelsFor(string $profileName): array
    {
        $profile = $this->config()->profile($profileName);

        $this->assertProfileConfigured($profile);

        try {
            $response = $this->request($profile)->get($this->url($profile, 'models_path'));
        } catch (ConnectionException|RequestException) {
            throw new AiClientException('Gagal mengambil daftar model dari '.($profile['label'] ?? $profileName).'.');
        }

        return collect($response->json('data') ?? [])
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->values()
            ->all();
    }

    /**
     * Daftar model profil utama (kompatibel dengan kode lama).
     *
     * @return array<int, string>
     */
    public function models(): array
    {
        return $this->modelsFor($this->resolveProfile()['name'] ?? 'cloud');
    }

    /**
     * Apakah kredensial & konfigurasi dasar profil utama sudah terisi.
     */
    public function isConfigured(): bool
    {
        if (! config('ai.enabled')) {
            return false;
        }

        $profile = $this->resolveProfile();

        return $this->config()->isConfigured($profile['name'] ?? 'cloud');
    }

    /** Profil yang sedang dipakai instance ini. */
    public function profile(): array
    {
        return $this->resolveProfile();
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     *
     * @throws AiClientException
     */
    private function chatWithProfile(array $profile, array $messages, array $tools, array $overrides): array
    {
        $this->assertProfileConfigured($profile);

        $temperature = $overrides['temperature'] ?? (float) config('ai.temperature', 0.2);
        $maxTokens = $overrides['max_tokens'] ?? (int) config('ai.max_tokens', 1024);

        $payload = [
            'model' => (string) ($profile['model'] ?? ''),
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $label = (string) ($profile['label'] ?? $profile['name'] ?? 'AI');

        try {
            $response = $this->request($profile)->post($this->url($profile, 'chat_path'), $payload);
        } catch (ConnectionException) {
            throw new AiClientException('Tidak dapat menghubungi '.$label.' (koneksi). Coba lagi nanti.');
        } catch (RequestException $exception) {
            $status = $exception->response?->status();

            if ($status === 401 || $status === 403) {
                throw new AiClientException('Kunci API '.$label.' ditolak. Periksa kredensialnya.');
            }

            if ($status === 429) {
                throw new AiClientException($label.' sedang sibuk (rate limit). Coba lagi sebentar.');
            }

            throw new AiClientException('Panggilan ke '.$label.' gagal (HTTP '.($status ?? '?').').');
        }

        $data = $response->json();
        $message = $data['choices'][0]['message'] ?? null;

        if (! is_array($message)) {
            throw new AiClientException('Respons '.$label.' tidak berisi message yang valid.');
        }

        $usage = $data['usage'] ?? [];

        return [
            'message' => $message,
            'usage' => [
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            ],
            'model' => (string) ($data['model'] ?? $profile['model'] ?? ''),
            'provider_name' => (string) ($profile['name'] ?? 'cloud'),
            'provider_label' => $label,
        ];
    }

    /** @return array<string, mixed> */
    private function resolveProfile(): array
    {
        if ($this->profile !== null) {
            return $this->profile;
        }

        if (! config('ai.enabled')) {
            throw new AiClientException('Fitur Asisten AI sedang nonaktif (AI_ENABLED=false).');
        }

        return $this->config()->primary();
    }

    /**
     * @param  array<string, mixed>  $profile
     *
     * @throws AiClientException
     */
    private function assertProfileConfigured(array $profile): void
    {
        if (! config('ai.enabled')) {
            throw new AiClientException('Fitur Asisten AI sedang nonaktif (AI_ENABLED=false).');
        }

        $name = (string) ($profile['name'] ?? 'cloud');

        if (! $this->config()->isConfigured($name)) {
            $label = (string) ($profile['label'] ?? $name);

            throw new AiClientException('Profil '.$label.' belum lengkap (periksa base_url / api_key / model).');
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function request(array $profile): PendingRequest
    {
        $token = (string) ($profile['api_key'] ?? '');
        $request = Http::acceptJson()->asJson();

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request
            ->timeout((int) config('ai.timeout', 60))
            ->connectTimeout((int) config('ai.connect_timeout', 10))
            ->retry(
                (int) config('ai.retry_times', 2),
                (int) config('ai.retry_sleep_ms', 500),
                throw: true,
            );
    }

    /** @param array<string, mixed>  $profile */
    private function url(array $profile, string $configKey): string
    {
        $path = (string) ($profile[$configKey] ?? '');

        return rtrim((string) ($profile['base_url'] ?? ''), '/').'/'.ltrim($path, '/');
    }

    private function config(): AiProviderConfig
    {
        return app(AiProviderConfig::class);
    }
}
