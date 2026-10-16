<?php

namespace App\Services\Ai;

use App\Models\AiSetting;

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

            $override = $this->databaseOverride('local');

            return [
                'name' => 'local',
                'label' => (string) ($override['label'] ?? $local['label'] ?? 'Xcode AI (Lokal)'),
                'base_url' => rtrim((string) ($override['base_url'] ?? $local['base_url'] ?? ''), '/'),
                'api_key' => (string) ($override['api_key'] ?? $local['api_key'] ?? 'local'),
                'model' => (string) ($override['model'] ?? $local['model'] ?? ''),
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
     * Override profil lokal dari tabel ai_settings (bila diisi). Kosong = pakai
     * config. Aman: bila tabel/kolom belum ada, kembalikan array kosong.
     *
     * @return array{label?: string, base_url?: string, api_key?: string, model?: string}
     */
    private function databaseOverride(string $name): array
    {
        if ($name !== 'local' || ! (bool) config('ai.provider_settings_from_db', true)) {
            return [];
        }

        try {
            $setting = AiSetting::current();

            if (! $setting) {
                return [];
            }

            return array_filter([
                'base_url' => (string) ($setting->provider_local_base_url ?? ''),
                'model' => (string) ($setting->provider_local_model ?? ''),
                'api_key' => (string) ($setting->provider_local_api_key ?? ''),
            ], fn (string $value): bool => $value !== '');
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Profil utama sesuai AI_DRIVER.
     *
     * @return array<string, mixed>
     */
    public function primary(): array
    {
        $preferLocal = $this->driver() === 'local'
            || ($this->driver() === 'auto' && ! $this->localDisabled());

        return $this->profile($preferLocal ? 'local' : 'cloud');
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
        if ($this->driver() !== 'auto') {
            return null;
        }

        // Bila pengguna mematikan profil lokal dari UI, jangan coba lokal sama sekali.
        if ($this->localDisabled()) {
            return null;
        }

        return $this->profile('cloud');
    }

    /**
     * Apakah profil lokal dinonaktifkan lewat UI (ai_settings.provider_local_enabled).
     * Bila kolom belum ada / belum diisi, anggap aktif (default aman).
     */
    public function localDisabled(): bool
    {
        if (! (bool) config('ai.provider_settings_from_db', true)) {
            return false;
        }

        try {
            $setting = AiSetting::current();

            return $setting !== null && ! (bool) ($setting->provider_local_enabled ?? true);
        } catch (\Throwable) {
            return false;
        }
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
