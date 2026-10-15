<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\AiUserAlias;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiUserMemoryService;
use App\Services\Ai\SystemPromptBuilder;
use App\Services\Ai\Tools\CariBarangTool;
use App\Services\Ai\Tools\CatatTransaksiBelanjaTool;
use App\Services\Ai\Tools\TambahItemBelanjaTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Perbaikan pemahaman AI: pencocokan deterministik, memori draft berjalan,
 * alias pribadi per user, dan persona.
 */
class AiPemahamanTest extends TestCase
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

    public function test_cari_barang_mendahulukan_cocok_persis(): void
    {
        Barang::query()->create(['nama_barang' => 'Wortel', 'satuan' => 'kg']);
        Barang::query()->create(['nama_barang' => 'Wortel Karyawan', 'satuan' => 'kg']);
        Barang::query()->create(['nama_barang' => 'Bawang Merah', 'satuan' => 'kg']);

        $user = $this->userWithPermissions(['view_any_barang']);
        $result = app(CariBarangTool::class)->execute(['kata_kunci' => 'wortel'], $user);

        // "Wortel" persis menang; belum tentu ambigu karena skor beda jauh.
        $this->assertSame('Wortel', $result['barang'][0]['nama_barang']);
        $this->assertNotNull($result['pilihan_terbaik']);
        $this->assertSame('Wortel', $result['pilihan_terbaik']['nama_barang']);
    }

    public function test_cari_barang_ambigu_tidak_memilih_paksa(): void
    {
        Barang::query()->create(['nama_barang' => 'Wortel Brastagi', 'satuan' => 'kg']);
        Barang::query()->create(['nama_barang' => 'Wortel Lokal', 'satuan' => 'kg']);

        $user = $this->userWithPermissions(['view_any_barang']);
        $result = app(CariBarangTool::class)->execute(['kata_kunci' => 'wortel'], $user);

        // Dua kandidat setara -> tidak ada pilihan_terbaik.
        $this->assertNull($result['pilihan_terbaik']);
    }

    public function test_cari_barang_menyembunyikan_skor_internal(): void
    {
        Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);

        $user = $this->userWithPermissions(['view_any_barang']);
        $result = app(CariBarangTool::class)->execute(['kata_kunci' => 'beras'], $user);

        $this->assertArrayNotHasKey('_score', $result['barang'][0]);
    }

    public function test_tambah_item_belanja_menggabungkan_item_lama(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $beras = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);
        $minyak = Barang::query()->create(['nama_barang' => 'Minyak', 'satuan' => 'liter']);

        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $beras->getKey(), 'jumlah' => 1, 'harga_satuan' => 1000]],
        ], $user, $conversation);

        $result = app(TambahItemBelanjaTool::class)->execute([
            'items' => [['barang_id' => $minyak->getKey(), 'jumlah' => 2, 'harga_satuan' => 2000]],
        ], $user, $conversation);

        $action = AiPendingAction::query()->findOrFail($result['pending_action_id']);
        $this->assertCount(2, $action->payload['items']);
        $this->assertSame(2, $result['total_item']);

        // Tetap satu pending (draft sama diperbarui, bukan draft baru).
        $this->assertSame(1, AiPendingAction::query()
            ->where('status', AiPendingAction::STATUS_PENDING)
            ->count());
    }

    public function test_tambah_item_belanja_memperbarui_item_yang_sama(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $beras = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);

        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $beras->getKey(), 'jumlah' => 1, 'harga_satuan' => 1000]],
        ], $user, $conversation);

        $result = app(TambahItemBelanjaTool::class)->execute([
            'items' => [['barang_id' => $beras->getKey(), 'jumlah' => 5, 'harga_satuan' => 1100]],
        ], $user, $conversation);

        $action = AiPendingAction::query()->findOrFail($result['pending_action_id']);
        $this->assertCount(1, $action->payload['items']);
        $this->assertSame(5, $action->payload['items'][0]['jumlah']);
        $this->assertSame(1100, $action->payload['items'][0]['harga_satuan']);
    }

    public function test_tambah_item_tanpa_draft_mengembalikan_error(): void
    {
        $beras = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);
        $user = $this->userWithPermissions(['update_kalkulator_belanja']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        $this->expectException(\App\Services\Ai\Tools\ToolException::class);
        $this->expectExceptionMessage('Belum ada draft belanja');

        app(TambahItemBelanjaTool::class)->execute([
            'items' => [['barang_id' => $beras->getKey(), 'jumlah' => 1]],
        ], $user, $conversation);
    }

    public function test_prompt_menyuntik_draft_berjalan(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $beras = Barang::query()->create(['nama_barang' => 'Beras Pandan', 'satuan' => 'karung']);

        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $beras->getKey(), 'jumlah' => 1, 'harga_satuan' => 1000]],
        ], $user, $conversation);

        $prompt = app(SystemPromptBuilder::class)->build($user, 'tambah minyak', $conversation);

        $this->assertStringContainsString('DRAFT BERJALAN', $prompt);
        $this->assertStringContainsString('Beras Pandan', $prompt);
        $this->assertStringContainsString('barang_id='.$beras->getKey(), $prompt);
    }

    public function test_persona_disuntik_ke_prompt(): void
    {
        \App\Models\AiSetting::current()->update([
            'persona' => 'Sangat ringkas dan ramah seperti petugas pasar.',
        ]);

        $user = $this->userWithPermissions(['view_any_barang']);
        $prompt = app(SystemPromptBuilder::class)->build($user, 'halo');

        $this->assertStringContainsString('PERSONA', $prompt);
        $this->assertStringContainsString('seperti petugas pasar', $prompt);
    }

    public function test_memori_pribadi_dipelajari_dan_disuntik(): void
    {
        $wortel = Barang::query()->create(['nama_barang' => 'Wortel Brastagi', 'satuan' => 'kg']);
        $user = $this->userWithPermissions(['view_any_barang']);

        // Pesan hanya menyebut "wortel" (cocok substring nama barang) -> dipelajari.
        app(AiUserMemoryService::class)->learnFromBelanja($user, 'catat wortel 25 ribu', [$wortel->getKey()]);

        $this->assertDatabaseHas('ai_user_aliases', [
            'user_id' => $user->getKey(),
            'keyword' => 'wortel',
            'barang_id' => $wortel->getKey(),
        ]);

        $prompt = app(SystemPromptBuilder::class)->build($user, 'wortel lagi');
        $this->assertStringContainsString('PREFERENSI PENGGUNA', $prompt);
        $this->assertStringContainsString('Wortel Brastagi', $prompt);
    }

    public function test_memori_pribadi_tidak_dipelajari_bila_banyak_item(): void
    {
        $wortel = Barang::query()->create(['nama_barang' => 'Wortel', 'satuan' => 'kg']);
        $sawi = Barang::query()->create(['nama_barang' => 'Sawi', 'satuan' => 'ikt']);
        $user = $this->userWithPermissions(['view_any_barang']);

        // Dua barang -> tidak ada pemetaan (ambigu).
        app(AiUserMemoryService::class)->learnFromBelanja($user, 'catat wortel dan sawi', [
            $wortel->getKey(),
            $sawi->getKey(),
        ]);

        $this->assertSame(0, AiUserAlias::query()->count());
    }
}
