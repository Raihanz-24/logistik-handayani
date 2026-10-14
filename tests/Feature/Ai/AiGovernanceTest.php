<?php

namespace Tests\Feature\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\AiSetting;
use App\Models\AiUsage;
use App\Models\User;
use App\Services\Ai\AiChatService;
use App\Services\Ai\SystemPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiGovernanceTest extends TestCase
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
            'ai.api_key' => 'test-key',
            'ai.base_url' => 'https://api.example.test/v1',
            'ai.model' => 'deepseek-v4-flash',
        ]);
    }

    public function test_chat_mencatat_pemakaian_token(): void
    {
        $this->enableAi();

        Http::fake([
            '*/chat/completions' => Http::response([
                'model' => 'deepseek-v4-flash',
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Siap.'],
                ]],
            ]),
        ]);

        $user = $this->userWithPermissions(['view_any_barang']);
        $service = app(AiChatService::class);
        $conversation = $service->startConversation($user);

        $service->reply($user, $conversation, 'Halo');

        $this->assertDatabaseHas('ai_usages', [
            'user_id' => $user->getKey(),
            'total_tokens' => 120,
            'prompt_tokens' => 100,
            'completion_tokens' => 20,
            'status' => AiUsage::STATUS_SUCCESS,
        ]);
    }

    public function test_pemakaian_token_dijumlahkan_lintas_iterasi_tool(): void
    {
        $this->enableAi();

        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push([
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => ['name' => 'cari_barang', 'arguments' => '{"kata_kunci":"x"}'],
                            ]],
                        ],
                    ]],
                ])
                ->push([
                    'usage' => ['prompt_tokens' => 70, 'completion_tokens' => 5, 'total_tokens' => 75],
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => 'Selesai.'],
                    ]],
                ]),
        ]);

        $user = $this->userWithPermissions(['view_any_barang']);
        $service = app(AiChatService::class);
        $conversation = $service->startConversation($user);

        $service->reply($user, $conversation, 'Cari x');

        $this->assertDatabaseHas('ai_usages', [
            'ai_conversation_id' => $conversation->getKey(),
            'total_tokens' => 135,
            'tool_calls' => 1,
            'iterations' => 2,
        ]);
    }

    public function test_pengetahuan_disuntik_hanya_saat_kata_kunci_cocok(): void
    {
        AiKnowledgeEntry::query()->create([
            'type' => AiKnowledgeEntry::TYPE_GLOSSARY,
            'title' => 'Satuan galon',
            'keywords' => ['galon'],
            'content' => 'Galon dicatat dengan satuan buah.',
            'active' => true,
        ]);

        $builder = app(SystemPromptBuilder::class);
        $user = $this->userWithPermissions(['view_any_barang']);

        $promptWithKeyword = $builder->build($user, 'berapa stok galon');
        $promptWithoutKeyword = $builder->build($user, 'berapa stok beras');

        $this->assertStringContainsString('Galon dicatat dengan satuan buah.', $promptWithKeyword);
        $this->assertStringNotContainsString('Galon dicatat dengan satuan buah.', $promptWithoutKeyword);
    }

    public function test_entri_selalu_sertakan_selalu_masuk_prompt(): void
    {
        AiKnowledgeEntry::query()->create([
            'type' => AiKnowledgeEntry::TYPE_RESTRICTION,
            'title' => 'Larangan',
            'content' => 'Jangan pernah menghapus data tanpa izin.',
            'always_include' => true,
            'active' => true,
        ]);

        $prompt = app(SystemPromptBuilder::class)->build(
            $this->userWithPermissions(['view_any_barang']),
            'halo',
        );

        $this->assertStringContainsString('Jangan pernah menghapus data tanpa izin.', $prompt);
    }

    public function test_scope_dan_persona_masuk_ke_prompt(): void
    {
        $setting = AiSetting::current();
        $setting->update([
            'assistant_name' => 'Asisten Gudang',
            'scope_description' => 'Hanya menjawab seputar stok dan mutasi.',
        ]);

        $prompt = app(SystemPromptBuilder::class)->build(
            $this->userWithPermissions(['view_any_barang']),
            'halo',
        );

        $this->assertStringContainsString('Asisten Gudang', $prompt);
        $this->assertStringContainsString('Hanya menjawab seputar stok dan mutasi.', $prompt);
    }

    public function test_migrasi_governance_hanya_membuat_tabel_baru(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 3).'/database/migrations/2026_10_11_010000_create_ai_governance_tables.php',
        );

        $this->assertStringContainsString("Schema::create('ai_usages'", $migration);
        $this->assertStringContainsString("Schema::create('ai_settings'", $migration);
        $this->assertStringContainsString("Schema::create('ai_knowledge_entries'", $migration);
        $this->assertStringNotContainsString('Schema::table(', $migration);
    }
}
