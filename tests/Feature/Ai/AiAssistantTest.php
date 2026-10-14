<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\User;
use App\Services\Ai\AiChatService;
use App\Services\Ai\Tools\CariBarangTool;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Test Asisten AI memakai DB test (logistik_test via phpunit.mysql.xml).
 * Semua panggilan jaringan di-fake; tidak menyentuh layanan AI nyata.
 */
class AiAssistantTest extends TestCase
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

    public function test_registry_menyembunyikan_tool_tanpa_izin(): void
    {
        $user = $this->userWithPermissions([]);
        $registry = new ToolRegistry($user);

        $this->assertFalse($registry->has('cari_barang'));
        $this->assertSame([], $registry->toOpenAiTools());
    }

    public function test_registry_menampilkan_tool_sesuai_izin(): void
    {
        $user = $this->userWithPermissions(['view_any_barang']);
        $registry = new ToolRegistry($user);

        $this->assertTrue($registry->has('cari_barang'));
        $this->assertFalse($registry->has('cek_stok'));

        $names = array_map(fn ($tool) => $tool->name(), $registry->all());
        $this->assertSame(['cari_barang'], $names);
    }

    public function test_cari_barang_mengembalikan_hasil(): void
    {
        Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);
        Barang::query()->create(['nama_barang' => 'Air Mineral', 'satuan' => 'dus']);

        $user = $this->userWithPermissions(['view_any_barang']);
        $result = app(CariBarangTool::class)->execute(['kata_kunci' => 'galon'], $user);

        $this->assertSame(1, $result['jumlah']);
        $this->assertSame('Galon Aqua', $result['barang'][0]['nama_barang']);
    }

    public function test_cek_stok_membaca_pivot_barang_lokasi(): void
    {
        $barang = Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);
        $gudang = Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);

        DB::table('barang_lokasi')->insert([
            'barang_id' => $barang->getKey(),
            'lokasi_id' => $gudang->getKey(),
            'stok' => 5,
            'stok_baik' => 4,
            'stok_rusak' => 1,
            'stok_hilang' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->userWithPermissions(['view_any_barang::lokasi']);
        $registry = new ToolRegistry($user);

        $result = $registry->get('cek_stok')->execute(['barang_id' => $barang->getKey()], $user);

        $this->assertTrue($result['ditemukan']);
        $this->assertSame(5, $result['total_semua_gudang']);
        $this->assertSame('Gudang Utama', $result['stok_per_gudang'][0]['nama_lokasi']);
    }

    public function test_chat_menyimpan_riwayat_dan_membalas(): void
    {
        $this->enableAi();
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Halo, ada yang bisa dibantu?'],
                ]],
            ]),
        ]);

        $user = $this->userWithPermissions([]);
        $service = app(AiChatService::class);
        $conversation = $service->startConversation($user);

        $reply = $service->reply($user, $conversation, 'Halo asisten');

        $this->assertSame(AiMessage::ROLE_ASSISTANT, $reply->role);
        $this->assertSame('Halo, ada yang bisa dibantu?', $reply->content);
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_chat_menjalankan_tool_lalu_membalas_final(): void
    {
        $this->enableAi();
        Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);

        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'cari_barang',
                                    'arguments' => '{"kata_kunci":"galon"}',
                                ],
                            ]],
                        ],
                    ]],
                ])
                ->push([
                    'choices' => [[
                        'message' => ['role' => 'assistant', 'content' => 'Ditemukan 1 barang.'],
                    ]],
                ]),
        ]);

        $user = $this->userWithPermissions(['view_any_barang']);
        $service = app(AiChatService::class);
        $conversation = $service->startConversation($user);

        $reply = $service->reply($user, $conversation, 'Cari barang galon');

        $this->assertSame('Ditemukan 1 barang.', $reply->content);
        // user, assistant(tool_calls), tool, assistant
        $this->assertSame(4, $conversation->messages()->count());
        $this->assertSame(1, $conversation->messages()->where('role', AiMessage::ROLE_TOOL)->count());
        Http::assertSentCount(2);
    }

    public function test_reply_ditolak_bila_user_bukan_pemilik_percakapan(): void
    {
        $this->enableAi();

        $owner = $this->userWithPermissions([]);
        $other = $this->userWithPermissions([]);

        $service = app(AiChatService::class);
        $conversation = $service->startConversation($owner);

        $this->expectExceptionMessage('Percakapan ini bukan milik Anda.');
        $service->reply($other, $conversation, 'Halo');
    }

    public function test_conversation_scope_visible_to_membatasi_percakapan(): void
    {
        $a = $this->userWithPermissions([]);
        $b = $this->userWithPermissions(['view_any_barang']);

        AiConversation::query()->create(['user_id' => $a->getKey(), 'status' => 'active']);
        AiConversation::query()->create(['user_id' => $b->getKey(), 'status' => 'active']);

        $this->assertSame(1, AiConversation::query()->visibleTo($a)->count());
        $this->assertSame(1, AiConversation::query()->visibleTo($b)->count());
    }
}
