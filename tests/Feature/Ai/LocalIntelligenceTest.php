<?php

namespace Tests\Feature\Ai;

use App\Models\AiPendingAction;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiChatService;
use App\Services\Ai\Local\BelanjaPerintahParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Kecerdasan lokal" (deterministik): perintah belanja jelas ditangani tanpa
 * memanggil API model; yang ambigu tetap diteruskan ke model.
 */
class LocalIntelligenceTest extends TestCase
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
            'ai.local_intelligence.enabled' => true,
        ]);
    }

    public function test_parser_mengenali_perintah_jelas(): void
    {
        $suppliers = [['id' => 7, 'nama' => 'Bu Elly']];
        $parser = app(BelanjaPerintahParser::class);

        $hasil = $parser->parse('catat belanja ke Bu Elly tempe 8000, cabai 2 kg 25000', $suppliers);

        $this->assertNotNull($hasil);
        $this->assertSame(7, $hasil['supplier_id']);
        $this->assertCount(2, $hasil['items']);

        $this->assertSame('tempe', $hasil['items'][0]['nama']);
        $this->assertSame(1.0, $hasil['items'][0]['jumlah']);
        $this->assertSame('-', $hasil['items'][0]['satuan']);
        $this->assertSame(8000, $hasil['items'][0]['harga_satuan']);

        $this->assertSame('cabai', $hasil['items'][1]['nama']);
        $this->assertSame(2.0, $hasil['items'][1]['jumlah']);
        $this->assertSame('kg', $hasil['items'][1]['satuan']);
        $this->assertSame(25000, $hasil['items'][1]['harga_satuan']);
    }

    public function test_parser_menangani_singkatan_ribu(): void
    {
        $suppliers = [['id' => 1, 'nama' => 'Bu Hen']];
        $hasil = app(BelanjaPerintahParser::class)->parse('order ayam broiler 100rb dari Bu Hen', $suppliers);

        $this->assertNotNull($hasil);
        $this->assertSame(100000, $hasil['items'][0]['harga_satuan']);
    }

    public function test_parser_mengembalikan_null_tanpa_supplier(): void
    {
        $hasil = app(BelanjaPerintahParser::class)->parse('catat tempe 8000', [['id' => 1, 'nama' => 'Bu Elly']]);

        $this->assertNull($hasil);
    }

    public function test_perintah_jelas_ditangani_lokal_tanpa_panggil_api(): void
    {
        $this->enableAi();
        Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);

        Http::fake();

        $user = $this->userWithPermissions(['view_any_barang', 'update_kalkulator_belanja']);
        $service = app(AiChatService::class);
        $conversation = $service->startConversation($user);

        $reply = $service->reply($user, $conversation, 'catat belanja ke Bu Elly tempe 8000');

        $this->assertStringContainsString('draft belanja', $reply->content);
        $this->assertSame(1, AiPendingAction::query()
            ->where('ai_conversation_id', $conversation->getKey())
            ->where('type', AiPendingAction::TYPE_PENGELUARAN_BELANJA)
            ->count());

        // Bukti hemat token: tidak ada panggilan ke API model.
        Http::assertNothingSent();
    }

    public function test_perintah_ambigu_diteruskan_ke_api(): void
    {
        $this->enableAi();
        Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        // Dua kandidat mirip -> tak ada pilihan terbaik -> harus lewat model.
        Barang::query()->create(['nama_barang' => 'Cabai Merah', 'satuan' => 'kg']);
        Barang::query()->create(['nama_barang' => 'Cabai Hijau', 'satuan' => 'kg']);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Cabai yang mana?']]],
            ]),
        ]);

        $user = $this->userWithPermissions(['view_any_barang', 'update_kalkulator_belanja']);
        $service = app(AiChatService::class);
        $conversation = $service->startConversation($user);

        $reply = $service->reply($user, $conversation, 'catat belanja ke Bu Elly cabai 25000');

        $this->assertSame('Cabai yang mana?', $reply->content);
        Http::assertSentCount(1);
    }

    public function test_kecerdasan_lokal_bisa_dinonaktifkan(): void
    {
        $this->enableAi();
        config(['ai.local_intelligence.enabled' => false]);

        Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'dari model']]],
            ]),
        ]);

        $user = $this->userWithPermissions(['view_any_barang', 'update_kalkulator_belanja']);
        $service = app(AiChatService::class);
        $conversation = $service->startConversation($user);

        $reply = $service->reply($user, $conversation, 'catat belanja ke Bu Elly tempe 8000');

        $this->assertSame('dari model', $reply->content);
        Http::assertSentCount(1);
        $this->assertSame(0, AiPendingAction::query()->count());
    }
}
