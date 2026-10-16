<?php

namespace Tests\Feature\Ai;

use App\Filament\Pages\AiSettings;
use App\Models\AiSetting;
use App\Models\AiTrainingExample;
use App\Services\Ai\AiProviderConfig;
use App\Services\Ai\AiTrainingExporter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pengaturan provider & pelatihan dari UI + unduh dataset.
 */
class AiProviderSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function superAdmin(): User
    {
        Role::findOrCreate('super_admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user->fresh();
    }

    public function test_halaman_pengaturan_menyimpan_provider_dan_pelatihan(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(AiSettings::class)
            ->fillForm([
                'provider_label' => 'Xcode AI Kantor',
                'provider_local_enabled' => true,
                'provider_local_base_url' => 'http://127.0.0.1:11434/v1',
                'provider_local_model' => 'qwen2.5:7b',
                'provider_local_api_key' => 'local',
                'training_capture' => false,
                'training_local_enabled' => true,
                'uploaded_model_path' => 'xcode-ai-ft:latest',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = AiSetting::current();

        $this->assertSame('Xcode AI Kantor', $setting->provider_label);
        $this->assertSame('qwen2.5:7b', $setting->provider_local_model);
        $this->assertFalse((bool) $setting->training_capture);
        $this->assertTrue((bool) $setting->training_local_enabled);
        $this->assertSame('xcode-ai-ft:latest', $setting->uploaded_model_path);
    }

    public function test_profil_lokal_memakai_override_dari_database(): void
    {
        config(['ai.profiles.local.base_url' => 'http://default.test/v1']);
        config(['ai.profiles.local.model' => 'default-model']);

        AiSetting::current()->update([
            'provider_local_base_url' => 'http://db.test/v1',
            'provider_local_model' => 'db-model',
        ]);

        $profile = app(AiProviderConfig::class)->profile('local');

        $this->assertSame('http://db.test/v1', $profile['base_url']);
        $this->assertSame('db-model', $profile['model']);
    }

    public function test_auto_tidak_memakai_lokal_bila_dinonaktifkan_dari_ui(): void
    {
        config(['ai.driver' => 'auto']);
        AiSetting::current()->update(['provider_local_enabled' => false]);

        $config = app(AiProviderConfig::class);

        $this->assertSame('cloud', $config->primary()['name']);
        $this->assertNull($config->fallback());
    }

    public function test_eksportir_menyusun_jsonl(): void
    {
        AiTrainingExample::query()->create([
            'provider' => 'Cloud (KryptonLab)',
            'input' => 'catat 2 kg cabai',
            'actions' => [['name' => 'cari_barang', 'arguments' => ['kata_kunci' => 'cabai']]],
            'output' => 'Oke.',
        ]);

        $jsonl = app(AiTrainingExporter::class)->toJsonl();

        $lines = array_filter(explode("\n", $jsonl));
        $this->assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        $this->assertSame('catat 2 kg cabai', $decoded['messages'][0]['content']);
        $this->assertSame('cari_barang', $decoded['messages'][1]['tool_calls'][0]['function']['name']);
    }

    public function test_unduh_dataset_hanya_super_admin(): void
    {
        AiTrainingExample::query()->create([
            'provider' => 'Cloud (KryptonLab)',
            'input' => 'halo',
            'actions' => [],
            'output' => 'Hai.',
        ]);

        $this->actingAs($this->superAdmin());

        $response = $this->get(route('ai-training.download'));

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    public function test_unduh_dataset_ditolak_untuk_user_biasa(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('ai-training.download'))->assertForbidden();
    }

    public function test_halaman_status_provider_dapat_dirender(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(\App\Filament\Pages\AiProviderStatus::class)
            ->assertOk()
            ->assertSee('Status Provider AI');
    }
}
