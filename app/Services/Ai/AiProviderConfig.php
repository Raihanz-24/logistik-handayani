<?php

namespace App\Services\Ai;

/**
 * Resolusi PROFIL provider AI (lokal vs cloud) + urutan percobaan.
 *
 * Semua nilai berasal dari config('ai.*'). Kelas ini murni (tanpa I/O) agar
 * mudah diuji dan tidak pernah menyentuh DB.
 *
 * Konvensi:
 * - 'cloud' memakai kunci legacy (base_url/api_key/model) — tetap kompatibel
 *   dengan .env yang sudah ada.
 * - 'local' memakai ai.profiles.local.* (server model OpenAI-compatible lokal).
 * - driver: 'cloud' (default), 'local', atau 'auto' (local dulu → cloud).
 */
class AiProviderConfig
{
    /** @return array<string, mixed> */
    public function profile(string $name): array
    {
        if ($name === 'local') {
            $local = (array) config('ai.profiles.local', []);

            return [
                'name' => 'local',
                'label' => (string) ($local['label'] ?? 'Xcode AI (Lokal)'),
                'base_url' => rtrim((string) ($local['base_url'] ?? ''), '/'),
                'api_key' => (string) ($local['api_key'] ?? 'local'),
                'model' => (string) ($local['model'] ?? ''),
                'chat_path' => (string) ($local['chat_path'] ?? '/chat/completions'),
                'models_path' => (string) ($local['models_path'] ?? '/models'),
                'local' => true,
            ];
        }

        return [
            'name' => 'cloud',
            'label' => (string) config('ai.profiles.cloud.label', 'Cloud (KryptonLab)'),
            'base_url' => rtrim((string) config('ai.base_url', ''), '/'),
            'api_key' => (string) config('ai.api_key', ''),
            'model' => (string) config('ai.model', ''),
            'chat_path' => (string) config('ai.chat_path', '/chat/completions'),
            'models_path' => (string) config('ai.models_path', '/models'),
            'local' => false,
        ];
    }

    /**
     * Profil utama sesuai AI_DRIVER.
     *
     * @return array<string, mixed>
     */
    public function primary(): array
    {
        return $this->profile(in_array($this->driver(), ['local', 'auto'], true) ? 'local' : 'cloud');
    }

    /**
     * Backup: untuk driver 'auto', backup = cloud (bila lokal jadi primary).
     * Untuk driver 'local', backup = null (sengaja tanpa cadangan cloud).
     * Untuk driver 'cloud', backup = null.
     *
     * @return array<string, mixed>|null
     */
    public function fallback(): ?array
    {
        if ($this->driver() === 'auto') {
            return $this->profile('cloud');
        }

        return null;
    }

    public function driver(): string
    {
        $driver = strtolower(trim((string) config('ai.driver', 'cloud')));

        return in_array($driver, ['cloud', 'local', 'auto'], true) ? $driver : 'cloud';
    }

    /** Apakah profil punya kredensial/alamat minimum untuk dipanggil. */
    public function isConfigured(string $name): bool
    {
        $profile = $this->profile($name);

        if ($profile['base_url'] === '' || $profile['model'] === '') {
            return false;
        }

        // Profil cloud WAJIB punya api_key; profil lokal tidak (server lokal
        // biasanya menerima token dummy apa pun).
        if (! $profile['local'] && $profile['api_key'] === '') {
            return false;
        }

        return true;
    }
}
