<?php

namespace Tests\Feature\Ai;

use App\Filament\Pages\AsistenAi;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiChatFlowReproTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('view_any_barang', 'web');
        $user->givePermissionTo('view_any_barang');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function enableAi(): void
    {
        config([
            'ai.enabled' => true,
            'ai.api_key' => 'test-key',
            'ai.base_url' => 'https://api.example.test/v1',
            'ai.model' => 'deepseek-v4.1-flash',
        ]);
    }

    public function test_dua_turn_dengan_tool_di_tengah(): void
    {
        $this->enableAi();

        Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);
        Supplier::query()->create(['nama_supplier' => 'Indomart', 'aktif' => true]);
        Barang::query()->create(['nama_barang' => 'susu', 'satuan' => 'klg']);

        // Turn 1: model balas teks langsung. Turn 2: model panggil tool, lalu jawab.
        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push([
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Halo! Balasan pertama.']]],
                ])
                ->push([
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Saya cari dulu.',
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => ['name' => 'cari_barang', 'arguments' => '{"kata_kunci":"susu"}'],
                            ]],
                        ],
                    ]],
                ])
                ->push([
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Balasan kedua setelah tool.']]],
                ]),
        ]);

        $user = $this->user();
        $this->actingAs($user);

        $component = Livewire::test(AsistenAi::class)
            ->set('message', 'halo')
            ->call('sendMessage')
            ->assertSee('Balasan pertama.');

        $conversationId = $component->get('conversationId');
        $this->assertNotNull($conversationId, 'conversationId harus terisi setelah kirim pertama');

        $component
            ->set('message', 'cari barang susu')
            ->call('sendMessage')
            ->assertSee('Balasan kedua setelah tool.');

        // Urutan pesan harus: user, assistant, user, assistant(+tool), tool, assistant
        $roles = AiMessage::query()
            ->where('ai_conversation_id', $conversationId)
            ->orderBy('id')
            ->pluck('role')
            ->all();

        $this->assertSame(
            ['user', 'assistant', 'user', 'assistant', 'tool', 'assistant'],
            $roles,
        );

        // Tidak ada error yang tersimpan
        $this->assertDatabaseMissing('ai_usages', ['status' => 'error']);
    }

    public function test_kirim_kedua_tetap_menyimpan_balasan(): void
    {
        $this->enableAi();

        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Jawaban A']]]])
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Jawaban B']]]]),
        ]);

        $user = $this->user();
        $this->actingAs($user);

        Livewire::test(AsistenAi::class)
            ->set('message', 'tanya A')
            ->call('sendMessage')
            ->assertSee('Jawaban A')
            ->set('message', 'tanya B')
            ->call('sendMessage')
            ->assertSee('Jawaban B')
            ->assertSee('tanya A')
            ->assertSee('tanya B');
    }

    public function test_urutan_bubble_dom(): void
    {
        $this->enableAi();

        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'JAWABAN_SATU']]]])
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'JAWABAN_DUA']]]]),
        ]);

        $user = $this->user();
        $this->actingAs($user);

        $html = Livewire::test(AsistenAi::class)
            ->set('message', 'PROMPT_SATU')
            ->call('sendMessage')
            ->set('message', 'PROMPT_DUA')
            ->call('sendMessage')
            ->html();

        $posPrompt = strpos($html, 'PROMPT_SATU');
        $posJawab = strpos($html, 'JAWABAN_SATU');
        $posPromptDua = strpos($html, 'PROMPT_DUA');
        $posJawabDua = strpos($html, 'JAWABAN_DUA');

        fwrite(STDERR, PHP_EOL.'POS prompt1='.var_export($posPrompt, true)
            .' jawab1='.var_export($posJawab, true)
            .' prompt2='.var_export($posPromptDua, true)
            .' jawab2='.var_export($posJawabDua, true).PHP_EOL);

        $this->assertTrue($posPrompt < $posJawab, 'bubble prompt harus sebelum jawaban (turn 1)');
        $this->assertTrue($posJawab < $posPromptDua, 'jawaban turn 1 sebelum prompt turn 2');
        $this->assertTrue($posPromptDua < $posJawabDua, 'bubble prompt harus sebelum jawaban (turn 2)');
    }
}
