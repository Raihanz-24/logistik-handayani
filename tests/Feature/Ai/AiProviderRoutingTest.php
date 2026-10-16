<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiProviderConfig;
use App\Services\Ai\DeepSeekClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Router provider: lokal (Xcode AI) vs cloud, termasuk fallback otomatis.
 */
class AiProviderRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function configureLocal(): void
    {
        config([
            'ai.enabled' => true,
            'ai.retry_times' => 1,
            'ai.retry_sleep_ms' => 0,
            'ai.profiles.local.base_url' => 'https://local.test/v1',
            'ai.profiles.local.api_key' => 'local',
            'ai.profiles.local.model' => 'local-model',
            'ai.profiles.local.chat_path' => '/chat/completions',
            'ai.profiles.local.models_path' => '/models',
            'ai.base_url' => 'https://cloud.test/v1',
            'ai.api_key' => 'cloud-key',
            'ai.model' => 'cloud-model',
        ]);
    }

    public function test_driver_menentukan_profil_utama_dan_backup(): void
    {
        $config = app(AiProviderConfig::class);

        config(['ai.driver' => 'cloud']);
        $this->assertSame('cloud', $config->primary()['name']);
        $this->assertNull($config->fallback());

        config(['ai.driver' => 'local']);
        $this->assertSame('local', $config->primary()['name']);
        $this->assertNull($config->fallback());

        config(['ai.driver' => 'auto']);
        $this->assertSame('local', $config->primary()['name']);
        $this->assertSame('cloud', $config->fallback()['name']);
    }

    public function test_chat_menakai_profil_lokal_saat_driver_local(): void
    {
        $this->configureLocal();
        config(['ai.driver' => 'local']);

        Http::fake([
            'https://local.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'dari lokal']]],
            ]),
        ]);

        $result = app(DeepSeekClient::class)->chat([
            ['role' => 'user', 'content' => 'halo'],
        ]);

        $this->assertSame('dari lokal', $result['message']['content']);
        $this->assertSame('local', $result['provider_name']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cloud.test'));
    }

    public function test_auto_jatuh_ke_cloud_bila_lokal_gagal(): void
    {
        $this->configureLocal();
        config(['ai.driver' => 'auto']);

        Http::fake([
            'https://local.test/v1/chat/completions' => Http::response(['error' => 'down'], 500),
            'https://cloud.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'dari cloud']]],
            ]),
        ]);

        $result = app(DeepSeekClient::class)->chatRouted([
            ['role' => 'user', 'content' => 'halo'],
        ]);

        $this->assertSame('dari cloud', $result['message']['content']);
        $this->assertSame('cloud', $result['provider_name']);
    }

    public function test_auto_memakai_lokal_bila_berhasil(): void
    {
        $this->configureLocal();
        config(['ai.driver' => 'auto']);

        Http::fake([
            'https://local.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'dari lokal']]],
            ]),
        ]);

        $result = app(DeepSeekClient::class)->chatRouted([
            ['role' => 'user', 'content' => 'halo'],
        ]);

        $this->assertSame('local', $result['provider_name']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cloud.test'));
    }
}
