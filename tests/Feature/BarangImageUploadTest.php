<?php

namespace Tests\Feature;

use App\Filament\Resources\BarangResource\Pages\CreateBarang;
use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BarangImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Ekstensi pdo_sqlite diperlukan untuk pengujian form Filament.');
        }

        parent::setUp();
    }

    public function test_a_large_product_photo_is_compressed_and_saved_from_the_filament_form(): void
    {
        Storage::fake('public');

        $role = Role::findOrCreate('penguji upload', 'web');
        $permission = Permission::findOrCreate('create_barang', 'web');
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        $upload = UploadedFile::fake()
            ->image('foto-barang-8mb.jpg', 2400, 1800)
            ->size(8000);

        $this->actingAs($user);

        Livewire::test(CreateBarang::class)
            ->fillForm([
                'nama_barang' => 'Barang Foto Besar',
                'satuan' => 'pcs',
                'gambar' => $upload,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $barang = Barang::query()->where('nama_barang', 'Barang Foto Besar')->firstOrFail();

        $this->assertNotNull($barang->gambar);
        $this->assertStringEndsWith('.webp', $barang->gambar);
        Storage::disk('public')->assertExists($barang->gambar);
        $this->assertSame('image/webp', Storage::disk('public')->mimeType($barang->gambar));
    }
}
