<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Ai\AiClientException;
use App\Services\Ai\AiProviderConfig;
use App\Services\Ai\DeepSeekClient;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * Status Provider AI (read-only + uji koneksi).
 *
 * Menampilkan driver aktif (cloud/local/auto), profil utama & backup, serta
 * status kesiapannya. Berguna untuk memastikan Xcode AI lokal aktif dan API
 * key cloud tetap siap sebagai cadangan.
 */
class AiProviderStatus extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Status Provider AI';

    protected static ?string $title = 'Status Provider AI';

    protected static ?int $navigationSort = 907;

    protected static ?string $slug = 'provider-ai';

    protected static string $view = 'filament.pages.ai-provider-status';

    protected ?string $heading = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole('super_admin');
    }

    /**
     * Ringkasan driver + profil.
     *
     * @return array{enabled: bool, driver: string, primary: array<string, mixed>, fallback: array<string, mixed>|null}
     */
    public function status(): array
    {
        $config = app(AiProviderConfig::class);

        return [
            'enabled' => (bool) config('ai.enabled'),
            'driver' => $config->driver(),
            'primary' => $config->primary(),
            'fallback' => $config->fallback(),
        ];
    }

    /** Apakah sebuah profil siap dipakai (kredensial lengkap). */
    public function ready(string $name): bool
    {
        return app(AiProviderConfig::class)->isConfigured($name);
    }

    public function testLocal(): void
    {
        $this->testProfile('local');
    }

    public function testCloud(): void
    {
        $this->testProfile('cloud');
    }

    /**
     * Uji koneksi satu profil (ambil daftar model).
     */
    public function testProfile(string $name): void
    {
        $label = app(AiProviderConfig::class)->profile($name)['label'] ?? $name;

        try {
            $models = app(DeepSeekClient::class)->modelsFor($name);

            Notification::make()
                ->title($label.' terhubung')
                ->body($models === []
                    ? 'Merespons, tetapi daftar model kosong. Cek nama model.'
                    : 'Model tersedia: '.implode(', ', array_slice($models, 0, 8)))
                ->success()
                ->send();
        } catch (AiClientException $exception) {
            Notification::make()
                ->title('Koneksi '.$label.' gagal')
                ->body($exception->getMessage())
                ->danger()
                ->persistent()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title('Koneksi '.$label.' gagal')
                ->body('Terjadi kesalahan saat menghubungi layanan.')
                ->danger()
                ->send();
        }
    }
}
