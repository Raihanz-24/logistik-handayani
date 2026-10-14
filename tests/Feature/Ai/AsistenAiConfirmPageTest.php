<?php

namespace Tests\Feature\Ai;

use App\Filament\Pages\AsistenAi;
use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Barang;
use App\Models\KalkulatorBelanja;
use App\Models\Mutasi;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\Tools\BuatDraftMutasiTool;
use App\Services\Ai\Tools\CatatTransaksiBelanjaTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regresi: konfirmasi aksi dari halaman chat harus benar-benar berjalan,
 * kartu konfirmasi tampil ringkas (bukan ikon raksasa), dan percakapan
 * dipulihkan saat halaman dibuka kembali setelah berpindah menu.
 */
class AsistenAiConfirmPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(array $permissions): User
    {
        config(['ai.enabled' => true, 'ai.api_key' => 'x', 'ai.base_url' => 'https://x.test/v1']);

        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $user->fresh();
        $this->actingAs($user);

        return $user;
    }

    public function test_konfirmasi_belanja_via_halaman_membuat_transaksi(): void
    {
        $user = $this->actingUser(['view_any_barang', 'create_kalkulator_belanja', 'update_kalkulator_belanja']);

        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $barang->getKey(), 'jumlah' => 2, 'harga_satuan' => 65000]],
        ], $user, $conversation);

        $action = AiPendingAction::query()->firstOrFail();

        Livewire::test(AsistenAi::class)
            ->call('openConversation', $conversation->getKey())
            ->call('confirmAction', $action->getKey())
            ->assertHasNoErrors();

        $this->assertSame(1, KalkulatorBelanja::query()->count());
        $this->assertDatabaseHas('pengeluaran_belanjas', ['supplier_id' => $supplier->getKey(), 'nominal' => 130000]);
        $this->assertSame(AiPendingAction::STATUS_CONFIRMED, $action->fresh()->status);
    }

    public function test_konfirmasi_mutasi_via_halaman_membuat_draft(): void
    {
        $user = $this->actingUser(['view_any_barang', 'create_mutasi']);

        $gudang = \App\Models\Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => \App\Models\Lokasi::JENIS_GUDANG,
        ]);
        $supplier = Supplier::query()->create(['nama_supplier' => 'PT Sumber', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(BuatDraftMutasiTool::class)->execute([
            'jenis_mutasi' => 'masuk',
            'barang_id' => $barang->getKey(),
            'jumlah' => 3,
            'lokasi_id' => $gudang->getKey(),
            'supplier_id' => $supplier->getKey(),
        ], $user, $conversation);

        $action = AiPendingAction::query()->firstOrFail();

        Livewire::test(AsistenAi::class)
            ->call('openConversation', $conversation->getKey())
            ->call('confirmAction', $action->getKey())
            ->assertHasNoErrors();

        $this->assertSame(1, Mutasi::query()->count());
        $this->assertSame(AiPendingAction::STATUS_CONFIRMED, $action->fresh()->status);
    }

    public function test_kartu_konfirmasi_tampil_ringkas_dengan_ikon_berukuran(): void
    {
        $user = $this->actingUser(['view_any_barang', 'create_kalkulator_belanja', 'update_kalkulator_belanja']);

        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $barang->getKey(), 'jumlah' => 1, 'harga_satuan' => 1000]],
        ], $user, $conversation);

        $html = Livewire::test(AsistenAi::class)
            ->call('openConversation', $conversation->getKey())
            ->html();

        $this->assertStringContainsString('ai-confirm', $html);
        $this->assertStringContainsString('ai-confirm__icon', $html);
        $this->assertStringContainsString('ai-confirm__btn', $html);

        // Ikon diberi ukuran eksplisit agar tidak membesar memenuhi kartu.
        $styles = (string) file_get_contents(resource_path('views/filament/pages/asisten-ai.blade.php'));
        $this->assertStringContainsString('.ai-confirm__icon svg', $styles);
    }

    public function test_percakapan_dipulihkan_setelah_pindah_halaman(): void
    {
        $user = $this->actingUser(['view_any_barang', 'create_kalkulator_belanja', 'update_kalkulator_belanja']);

        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $barang->getKey(), 'jumlah' => 1, 'harga_satuan' => 1000]],
        ], $user, $conversation);

        // Simulasikan user membuka percakapan (menyimpan ke session) lalu buka ulang halaman.
        Livewire::test(AsistenAi::class)->call('openConversation', $conversation->getKey());

        Livewire::test(AsistenAi::class)
            ->assertSet('conversationId', (int) $conversation->getKey())
            ->assertSee('ai-confirm');
    }

    public function test_percakapan_baru_menghapus_session(): void
    {
        $user = $this->actingUser(['view_any_barang']);

        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        Livewire::test(AsistenAi::class)
            ->call('openConversation', $conversation->getKey())
            ->call('startNewChat')
            ->assertSet('conversationId', null);

        $this->assertNull(session('ai_active_conversation_id'));
    }

    public function test_drawer_riwayat_dapat_dibuka_dan_ditutup(): void
    {
        $this->actingUser(['view_any_barang']);

        Livewire::test(AsistenAi::class)
            ->assertSet('historyOpen', false)
            ->assertSee('class="ai-history is-hidden"', false)
            ->call('toggleHistory')
            ->assertSet('historyOpen', true)
            ->assertSee('ai-history__panel')
            ->assertSee('wire:click="closeHistory"', false)
            ->assertDontSee('class="ai-history is-hidden"', false)
            ->call('closeHistory')
            ->assertSet('historyOpen', false)
            ->assertSee('class="ai-history is-hidden"', false);
    }

    public function test_hapus_satu_percakapan_menghapus_pesan(): void
    {
        $user = $this->actingUser(['view_any_barang']);

        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);
        \App\Models\AiMessage::query()->create([
            'ai_conversation_id' => $conversation->getKey(),
            'role' => \App\Models\AiMessage::ROLE_USER,
            'content' => 'halo',
        ]);

        $this->assertSame(1, \App\Models\AiMessage::query()->count());

        Livewire::test(AsistenAi::class)
            ->call('deleteConversation', $conversation->getKey())
            ->assertHasNoErrors();

        $this->assertSame(0, AiConversation::query()->count());
        $this->assertSame(0, \App\Models\AiMessage::query()->count());
    }

    public function test_hapus_semua_percakapan(): void
    {
        $user = $this->actingUser(['view_any_barang']);

        AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);
        AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        $this->assertSame(2, AiConversation::query()->count());

        Livewire::test(AsistenAi::class)->call('clearConversations');

        $this->assertSame(0, AiConversation::query()->count());
    }

    public function test_modal_konfirmasi_dipakai_bukan_wire_confirm_browser(): void
    {
        $user = $this->actingUser(['view_any_barang']);

        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        $html = Livewire::test(AsistenAi::class)->html();

        // Modal internal selalu ada di DOM dan tersembunyi lewat is-hidden,
        // serta tidak lagi memakai pop-up bawaan browser (wire:confirm).
        $this->assertStringContainsString('ai-modal', $html);
        $this->assertStringContainsString('ai-modal__backdrop', $html);
        $this->assertStringContainsString('class="ai-modal is-hidden"', $html);
        $this->assertStringNotContainsString('wire:confirm', $html);

        // Tombol hapus membuka modal (state), bukan langsung menghapus.
        $component = Livewire::test(AsistenAi::class)
            ->call('confirmDeleteConversation', $conversation->getKey())
            ->assertSet('deleteConfirmScope', 'single')
            ->assertSet('deleteConfirmConversationId', (int) $conversation->getKey())
            ->assertSee('ai-modal__dialog')
            ->assertSeeHtml('wire:click="confirmDeleteConfirmed"');

        $this->assertDatabaseHas('ai_conversations', ['id' => $conversation->getKey()]);

        // Menutup modal tidak menghapus apa pun.
        $component->call('closeDeleteConfirm')
            ->assertSet('deleteConfirmScope', null);
        $this->assertDatabaseHas('ai_conversations', ['id' => $conversation->getKey()]);

        // Modal "hapus semua" memakai mode 'all'.
        Livewire::test(AsistenAi::class)
            ->call('confirmClearConversations')
            ->assertSet('deleteConfirmScope', 'all')
            ->assertSet('deleteConfirmConversationId', null);
    }

    public function test_konfirmasi_modal_benar_menghapus_percakapan(): void
    {
        $user = $this->actingUser(['view_any_barang']);

        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        Livewire::test(AsistenAi::class)
            ->call('confirmDeleteConversation', $conversation->getKey())
            ->call('confirmDeleteConfirmed')
            ->assertSet('deleteConfirmScope', null)
            ->assertHasNoErrors();

        $this->assertSame(0, AiConversation::query()->count());
    }

    public function test_konfirmasi_modal_hapus_semua(): void
    {
        $user = $this->actingUser(['view_any_barang']);

        AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);
        AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        Livewire::test(AsistenAi::class)
            ->call('confirmClearConversations')
            ->call('confirmDeleteConfirmed');

        $this->assertSame(0, AiConversation::query()->count());
    }

    public function test_percakapan_dengan_aksi_pending_tidak_dihapus(): void
    {
        $user = $this->actingUser(['view_any_barang', 'create_kalkulator_belanja', 'update_kalkulator_belanja']);

        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $barang->getKey(), 'jumlah' => 1, 'harga_satuan' => 1000]],
        ], $user, $conversation);

        Livewire::test(AsistenAi::class)->call('deleteConversation', $conversation->getKey());

        // Masih ada aksi pending → percakapan tidak boleh terhapus.
        $this->assertDatabaseHas('ai_conversations', ['id' => $conversation->getKey()]);
    }

    public function test_user_lain_tidak_dapat_menghapus_percakapan(): void
    {
        $owner = $this->actingUser(['view_any_barang']);
        $conversation = AiConversation::query()->create(['user_id' => $owner->getKey(), 'status' => 'active']);

        $other = User::factory()->create();
        Permission::findOrCreate('view_any_barang', 'web');
        $other->givePermissionTo('view_any_barang');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($other->fresh());

        Livewire::test(AsistenAi::class)->call('deleteConversation', $conversation->getKey());

        $this->assertDatabaseHas('ai_conversations', ['id' => $conversation->getKey()]);
    }
}
