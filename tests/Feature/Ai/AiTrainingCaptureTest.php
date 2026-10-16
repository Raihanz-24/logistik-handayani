<?php

namespace Tests\Feature\Ai;

use App\Models\AiTrainingExample;
use App\Models\Barang;
use App\Models\User;
use App\Services\Ai\AiChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pencatatan contoh pelatihan (distilasi) + ekspor JSONL.
 */
class AiTrainingCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function enableAi(): void
    {
        config([
            'ai.enabled' => true,
            'ai.driver' => 'cloud',
            'ai.api_key' => 'test-key',
            'ai.base_url' => 'https://api.example.test/v1',
            'ai.model' => 'deepseek-v4-flash',
            'ai.training.capture' => true,
            'ai.training.only_with_tools' => true,
        ]);
    }

    public function test_contoh_dengan_tool_direkam(): void
    {
        $this->enableAi();
        Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);

        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'cari_barang', 'arguments' => '{"kata_kunci":"galon"}'],
                    ]],
                ]]]])
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Ditemukan.']]]]),
        ]);

        $user = $this->userWithPermissions(['view_any_barang']);
        $service = app(AiChatService::class);
        $conversation = $service->startConversation($user);

        $service->reply($user, $conversation, 'Cari galon');

        $example = AiTrainingExample::query()->first();
        $this->assertNotNull($example);
        $this->assertSame('Cari galon', $example->input);
        $this->assertSame('Ditemukan.', $example->output);
        $this->assertSame('cari_barang', $example->actions[0]['name']);
    }

    public function test_contoh_tanpa_tool_tidak_direkam_saat_only_with_tools(): void
    {
        $this->enableAi();

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Halo!']]],
            ]),
        ]);

        $user = $this->userWithPermissions([]);
        $service = app(AiChatService::class);
        $conversation = $service->startConversation($user);

        $service->reply($user, $conversation, 'Halo');

        $this->assertSame(0, AiTrainingExample::query()->count());
    }

    public function test_ekspor_jsonl_menghasilkan_baris_valid(): void
    {
        AiTrainingExample::query()->create([
            'provider' => 'Cloud (KryptonLab)',
            'input' => 'catat 2 kg cabai',
            'actions' => [['name' => 'cari_barang', 'arguments' => ['kata_kunci' => 'cabai']]],
            'output' => 'Oke, draft disiapkan.',
        ]);

        $path = storage_path('app/testing/dataset.jsonl');

        if (file_exists($path)) {
            unlink($path);
        }

        $this->artisan('ai:export-training', ['--path' => $path])->assertSuccessful();

        $this->assertFileExists($path);
        $lines = array_filter(explode("\n", (string) file_get_contents($path)));

        $this->assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        $this->assertSame('catat 2 kg cabai', $decoded['messages'][0]['content']);
        $this->assertSame('cari_barang', $decoded['messages'][1]['tool_calls'][0]['function']['name']);

        unlink($path);
    }

    public function test_ekspor_aman_bila_tidak_ada_data(): void
    {
        $this->artisan('ai:export-training')->assertSuccessful();
        $this->assertSame(0, AiTrainingExample::query()->count());
    }
}
