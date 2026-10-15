<?php

namespace Tests\Feature;

use App\Filament\Pages\FotoBarangFolder;
use App\Filament\Pages\FotoBarangMaps;
use App\Models\FotoBarangItem;
use App\Models\FotoBarangSession;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regresi folder Foto Maps:
 *  - Fitur ubah nama folder (rename) yang dapat dipakai tanpa mengubah foto.
 *  - Label barang dirender di server sehingga tidak tertukar/tertinggal setelah
 *    refresh (bug lama: label baru benar setelah hard refresh).
 */
class FotoBarangRenameTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(bool $canManage = true): User
    {
        $permissions = ['view_foto_barang_maps', 'manage_foto_barang_maps'];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo($canManage ? $permissions : ['view_foto_barang_maps']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function folderFor(User $user, string $judul = 'Barang Datang - Contoh'): FotoBarangSession
    {
        return FotoBarangSession::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->getKey(),
            'judul' => $judul,
            'nama_lokasi' => 'Paiton',
            'alamat' => 'Jl. Raya Paiton',
            'status' => FotoBarangSession::STATUS_SELESAI,
            'dimulai_at' => now('Asia/Jakarta'),
            'selesai_at' => now('Asia/Jakarta'),
        ]);
    }

    public function test_folder_name_can_be_renamed(): void
    {
        $user = $this->actingUser();
        $session = $this->folderFor($user);

        $component = Livewire::test(FotoBarangMaps::class);
        $component->call('renameSession', $session->getKey(), '  Barang Datang Supplier Dapur  ');

        $this->assertSame('Barang Datang Supplier Dapur', $session->fresh()->judul);
    }

    public function test_rename_rejects_empty_and_too_long_names(): void
    {
        $user = $this->actingUser();
        $session = $this->folderFor($user, 'Nama Awal');

        Livewire::test(FotoBarangMaps::class)
            ->call('renameSession', $session->getKey(), '   ')
            ->assertReturned(fn ($result): bool => $result['renamed'] === false);

        $this->assertSame('Nama Awal', $session->fresh()->judul);

        Livewire::test(FotoBarangMaps::class)
            ->call('renameSession', $session->getKey(), str_repeat('x', 151))
            ->assertReturned(fn ($result): bool => $result['renamed'] === false);

        $this->assertSame('Nama Awal', $session->fresh()->judul);
    }

    public function test_rename_requires_manage_permission(): void
    {
        $user = $this->actingUser(canManage: false);
        $session = $this->folderFor($user, 'Nama Awal');

        // Tanpa izin manage_foto_barang_maps, rename harus ditolak (403) dan
        // nama folder tidak boleh berubah.
        Livewire::test(FotoBarangMaps::class)
            ->call('renameSession', $session->getKey(), 'Nama Baru')
            ->assertStatus(403);

        $this->assertSame('Nama Awal', $session->fresh()->judul);
    }

    public function test_folder_page_renders_purchase_label_on_the_correct_photo(): void
    {
        $user = $this->actingUser();
        $session = $this->folderFor($user);

        $photo = FotoBarangItem::query()->create([
            'foto_barang_session_id' => $session->getKey(),
            'urutan' => 1,
            'path' => 'foto-barang/contoh/foto.jpg',
            'processing_status' => FotoBarangItem::PROCESSING_COMPLETED,
            'diambil_at' => now('Asia/Jakarta'),
            'ukuran_hasil' => 1024,
            'lebar' => 100,
            'tinggi' => 120,
        ]);

        $component = Livewire::test(FotoBarangFolder::class, ['session' => $session->uuid]);
        $html = $component->html();

        // Label dirender server tepat di dalam slot foto, bukan lewat Alpine yang
        // bisa basi. Slot selalu ada untuk setiap foto sehingga label tidak mungkin
        // tertukar dengan foto lain.
        $this->assertStringContainsString('data-photo-label="'.$photo->getKey().'"', $html);

        // Tanpa transaksi belanja terkait, tidak ada label barang yang ditampilkan.
        $this->assertStringNotContainsString('class="ff-product-label"', $html);
    }
}
