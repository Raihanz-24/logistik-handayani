<?php

namespace Tests\Feature;

use App\Filament\Resources\KalkulatorBelanjaResource\Pages\ListKalkulatorBelanjas;
use App\Filament\Resources\KalkulatorBelanjaResource\RelationManagers\PengeluaranRelationManager;
use App\Models\Barang;
use App\Models\KalkulatorBelanja;
use App\Models\PengeluaranBelanja;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regresi halaman Transaksi Belanja:
 *  - Judul daftar memakai "Transaksi Belanja" (selaras sidebar), bukan
 *    "List Kalkulator Belanjas" yang berasal dari nama kelas halaman.
 *  - Setiap transaksi per supplier (toko) dapat diubah per itemnya:
 *    menambah barang, mengubah harga/jumlah, dan menghapus transaksi.
 */
class BelanjaTransaksiEditTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $permissions = [
            'view_any_barang',
            'view_any_kalkulator_belanja',
            'view_kalkulator_belanja',
            'create_kalkulator_belanja',
            'update_kalkulator_belanja',
            'delete_kalkulator_belanja',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    private function sesi(User $user): KalkulatorBelanja
    {
        return KalkulatorBelanja::query()->create([
            'user_id' => $user->getKey(),
            'judul' => 'Belanja Mingguan',
            'tanggal' => today(),
            'uang_awal' => 0,
        ]);
    }

    public function test_judul_daftar_adalah_transaksi_belanja(): void
    {
        $this->actingUser();

        $component = Livewire::test(ListKalkulatorBelanjas::class);
        $html = $component->html();

        $this->assertSame('Transaksi Belanja', $component->instance()->getTitle());
        $this->assertStringContainsString('Transaksi Belanja', $html);
        $this->assertStringNotContainsString('List Kalkulator Belanja', $html);
    }

    public function test_transaksi_toko_dapat_diubah_per_item(): void
    {
        $user = $this->actingUser();
        $session = $this->sesi($user);
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Sumber', 'aktif' => true]);

        $sabun = Barang::query()->create([
            'nama_barang' => 'Sabun',
            'kode_barang' => 'BRG-001',
            'satuan' => 'pcs',
        ]);
        $sampo = Barang::query()->create([
            'nama_barang' => 'Sampo',
            'kode_barang' => 'BRG-002',
            'satuan' => 'pcs',
        ]);

        $expense = PengeluaranBelanja::query()->create([
            'kalkulator_belanja_id' => $session->getKey(),
            'supplier_id' => $supplier->getKey(),
            'nominal' => 1000,
            'urutan' => 1,
        ]);

        $component = Livewire::test(PengeluaranRelationManager::class, [
            'ownerRecord' => $session,
            'pageClass' => \App\Filament\Resources\KalkulatorBelanjaResource\Pages\ViewKalkulatorBelanja::class,
        ]);

        $component->callTableAction('ubah-transaksi', $expense, data: [
            'supplier_id' => $supplier->getKey(),
            'keterangan' => 'Harga diperbarui',
            'items' => [
                ['barang_id' => $sabun->getKey(), 'jumlah' => 3, 'harga_satuan' => 2000, 'satuan' => null, 'keterangan' => null],
                ['barang_id' => $sampo->getKey(), 'jumlah' => 1, 'harga_satuan' => 5000, 'satuan' => null, 'keterangan' => null],
            ],
            'nota_paths' => [],
        ]);

        $expense->refresh();

        $this->assertSame(2, $expense->items()->count());
        $this->assertSame(11_000, (int) $expense->nominal);
        $this->assertSame('Harga diperbarui', $expense->keterangan);
    }

    public function test_aksi_ubah_dan_hapus_transaksi_dirender(): void
    {
        $user = $this->actingUser();
        $session = $this->sesi($user);
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Sumber', 'aktif' => true]);

        $expense = PengeluaranBelanja::query()->create([
            'kalkulator_belanja_id' => $session->getKey(),
            'supplier_id' => $supplier->getKey(),
            'nominal' => 1000,
            'urutan' => 1,
        ]);

        $component = Livewire::test(PengeluaranRelationManager::class, [
            'ownerRecord' => $session,
            'pageClass' => \App\Filament\Resources\KalkulatorBelanjaResource\Pages\ViewKalkulatorBelanja::class,
        ]);

        $table = $component->instance()->getTable();

        foreach (['ubah-transaksi', 'hapus-transaksi'] as $name) {
            $action = $table->getAction($name);
            $this->assertNotNull($action, "Aksi {$name} tidak terdaftar.");
            $this->assertTrue($action->record($expense)->isVisible(), "Aksi {$name} tidak tampil.");
        }

        $html = $component->call('loadTable')->html();

        $this->assertStringContainsString('fi-ta-content-grid', $html);
        $this->assertStringContainsString('Ubah', $html);
        $this->assertStringContainsString('Hapus', $html);
        // Tombol folder sudah ada di footer kartu, jadi disembunyikan visual di baris aksi.
        $this->assertStringContainsString('display:none', $html);
    }

    public function test_tombol_folder_di_kartu_tetap_dapat_dibuka(): void
    {
        $user = $this->actingUser();
        $session = $this->sesi($user);
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Sumber', 'aktif' => true]);

        $expense = PengeluaranBelanja::query()->create([
            'kalkulator_belanja_id' => $session->getKey(),
            'supplier_id' => $supplier->getKey(),
            'nominal' => 1000,
            'urutan' => 1,
        ]);

        // Aksi disembunyikan dari baris tabel, tetapi mountTableAction dari
        // tombol "Hubungkan Folder" di kartu harus tetap membuka modalnya.
        Livewire::test(PengeluaranRelationManager::class, [
            'ownerRecord' => $session,
            'pageClass' => \App\Filament\Resources\KalkulatorBelanjaResource\Pages\ViewKalkulatorBelanja::class,
        ])
            ->mountTableAction('atur-foto-maps', $expense)
            ->assertTableActionMounted('atur-foto-maps');
    }
}
