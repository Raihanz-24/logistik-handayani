<?php

namespace Tests\Feature;

use App\Filament\Resources\KalkulatorBelanjaResource\Pages\ListKalkulatorBelanjas;
use App\Filament\Resources\KalkulatorBelanjaResource\RelationManagers\PengeluaranRelationManager;
use App\Models\Barang;
use App\Models\KalkulatorBelanja;
use App\Models\PengeluaranBelanja;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BelanjaTransactionService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Menambah barang pasar TANPA memilih satuan tidak boleh membuat simpan
     * "diam-diam gagal" (modal tetap terbuka tanpa pesan). Form otomatis
     * memakai satuan "-" sehingga transaksi benar-benar tersimpan.
     */
    public function test_tambah_barang_pasar_tanpa_satuan_tetap_tersimpan(): void
    {
        $user = $this->actingUser();
        $session = $this->sesi($user);
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Sumber', 'aktif' => true]);

        $sabun = Barang::query()->create([
            'nama_barang' => 'Sabun',
            'kode_barang' => 'BRG-101',
            'satuan' => 'pcs',
        ]);
        $gado = Barang::query()->create([
            'nama_barang' => 'Gado',
            'kode_barang' => 'BRG-102',
            'satuan' => 'ikt',
            'is_barang_pasar' => true,
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

        // Barang pasar dengan satuan null (user tidak memilih satuan).
        $component->callTableAction('ubah-transaksi', $expense, data: [
            'supplier_id' => $supplier->getKey(),
            'keterangan' => 'Tambah 1 item pasar',
            'items' => [
                ['barang_id' => $sabun->getKey(), 'jumlah' => 2, 'harga_satuan' => 3000, 'satuan' => null, 'keterangan' => null],
                ['barang_id' => $gado->getKey(), 'jumlah' => 1, 'harga_satuan' => 5000, 'satuan' => null, 'keterangan' => null],
            ],
            'nota_paths' => [],
        ]);

        // Tidak ada error validasi yang membuat modal tetap terbuka.
        $component->assertHasNoErrors();

        $expense->refresh();

        $this->assertSame(2, $expense->items()->count());
        $this->assertSame(11_000, (int) $expense->nominal);

        // Barang pasar tanpa satuan tersimpan sebagai "-".
        $this->assertSame(
            '-',
            (string) $expense->items()->where('barang_id', $gado->getKey())->value('satuan_snapshot'),
        );
    }

    /**
     * Context barang dan riwayat harga di-cache per request, sehingga menambah
     * beberapa item tidak mengulang query lookup yang sama.
     */
    public function test_context_barang_di_cache_dalam_satu_request(): void
    {
        $service = app(BelanjaTransactionService::class);

        $barang = Barang::query()->create([
            'nama_barang' => 'Sabun',
            'kode_barang' => 'BRG-103',
            'satuan' => 'pcs',
        ]);

        DB::enableQueryLog();

        $service->purchaseItemContext($barang->getKey());
        $first = count(DB::getQueryLog());
        DB::flushQueryLog();

        $service->purchaseItemContext($barang->getKey());
        $service->purchaseItemContext($barang->getKey());
        $second = count(DB::getQueryLog());

        $this->assertGreaterThan(0, $first);
        $this->assertSame(0, $second, 'Panggilan berulang seharusnya memakai cache, bukan query lagi.');

        $service->flushCaches();

        $service->purchaseItemContext($barang->getKey());
        $this->assertGreaterThan(0, count(DB::getQueryLog()) - $second);
    }

    /**
     * Regresi performa: daftar Barang TIDAK boleh ditanam ke HTML tiap baris
     * repeater. Sebelumnya `->options()` + `->preload()` membuat payload modal
     * membengkak ~67 KB/baris (10 item ~695 KB) sehingga form terasa lemot dan
     * tombol Simpan "muter" tanpa hasil. Sekarang pencarian dilakukan server-side.
     */
    public function test_daftar_barang_tidak_ditanam_ke_tiap_baris_repeater(): void
    {
        $user = $this->actingUser();
        $session = $this->sesi($user);
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Sumber', 'aktif' => true]);

        for ($i = 1; $i <= 50; $i++) {
            Barang::query()->create([
                'nama_barang' => 'Barang Uji '.$i,
                'kode_barang' => 'BRG-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'satuan' => 'pcs',
            ]);
        }

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

        $component->mountTableAction('ubah-transaksi', $expense);
        $html = $component->html();

        // Kode barang master tidak boleh muncul di HTML modal (hanya via search).
        $this->assertSame(0, substr_count($html, 'BRG-'), 'Daftar barang seharusnya tidak ditanam ke HTML modal.');

        // Tambah satu baris agar punya path select barang_id.
        $component->set('mountedTableActionsData.0.items.uji', [
            'barang_id' => null, 'jumlah' => 1, 'harga_satuan' => 1000, 'satuan' => null, 'keterangan' => null,
        ]);
        $barangPath = 'mountedTableActionsData.0.items.uji.barang_id';

        // Pencarian barang tetap berfungsi (diambil dari server saat diketik).
        $results = $component->call('getFormSelectSearchResults', $barangPath, 'Barang Uji 7');
        $this->assertNotEmpty($results->effects['returns'] ?? [], 'Pencarian server-side barang tidak mengembalikan hasil.');

        // Label supplier terpilih tetap dapat ditampilkan tanpa memuat seluruh daftar.
        $label = $component->call('getFormSelectOptionLabel', 'mountedTableActionsData.0.supplier_id');
        $this->assertContains('Toko Sumber', (array) ($label->effects['returns'] ?? []));
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

    /**
     * Barang pasar dengan satuan lama di luar daftar (mis. "pcs") TIDAK boleh
     * membuat edit "diam-diam gagal". Saat modal dibuka, satuan dinormalkan ke
     * "-" sehingga transaksi lama tetap bisa disimpan.
     */
    public function test_satuan_pasar_lama_di_luar_daftar_tidak_memblokir_edit(): void
    {
        $user = $this->actingUser();
        $session = $this->sesi($user);
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Sumber', 'aktif' => true]);

        $gado = Barang::query()->create([
            'nama_barang' => 'Gado-Gado',
            'kode_barang' => 'BRG-202',
            'satuan' => 'ikt',
            'is_barang_pasar' => true,
        ]);

        $expense = PengeluaranBelanja::query()->create([
            'kalkulator_belanja_id' => $session->getKey(),
            'supplier_id' => $supplier->getKey(),
            'nominal' => 5000,
            'urutan' => 1,
        ]);

        // Data lama: barang pasar tersimpan dengan satuan "pcs" (di luar daftar).
        $expense->items()->create([
            'barang_id' => $gado->getKey(),
            'kode_barang_snapshot' => 'BRG-202',
            'nama_barang_snapshot' => 'Gado-Gado',
            'satuan_snapshot' => 'pcs',
            'jumlah' => '1.000',
            'harga_satuan' => 5000,
            'subtotal' => 5000,
            'urutan' => 0,
        ]);

        $component = Livewire::test(PengeluaranRelationManager::class, [
            'ownerRecord' => $session,
            'pageClass' => \App\Filament\Resources\KalkulatorBelanjaResource\Pages\ViewKalkulatorBelanja::class,
        ]);

        $component->mountTableAction('ubah-transaksi', $expense);

        // Satuan lama yang tidak valid sudah dinormalkan ke "-".
        $mounted = $component->instance()->mountedTableActionsData[0];
        $firstItem = array_values($mounted['items'])[0];
        $this->assertSame('-', $firstItem['satuan']);

        $component->callMountedTableAction();

        $component->assertHasNoErrors();
        $this->assertSame(
            '-',
            (string) $expense->fresh()->items()->where('barang_id', $gado->getKey())->value('satuan_snapshot'),
        );
    }

    /**
     * Kegagalan validasi sisi service HARUS terlihat (notifikasi), bukan tombol
     * Simpan yang "muter" tanpa respon. Service memakai key numerik
     * (items.0.barang_id) yang tidak cocok dengan path form UUID, jadi tanpa
     * penanganan khusus pesannya tidak pernah tampil.
     */
    public function test_kegagalan_validasi_service_menampilkan_notifikasi(): void
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

        // barang_id tidak ada di master -> lolos validasi Form (select non-relasi)
        // tetapi ditolak service (exists:barangs,id).
        $component->callTableAction('ubah-transaksi', $expense, data: [
            'supplier_id' => $supplier->getKey(),
            'keterangan' => null,
            'items' => [
                ['barang_id' => 999_999, 'jumlah' => 1, 'harga_satuan' => 1000, 'satuan' => null, 'keterangan' => null],
            ],
            'nota_paths' => [],
        ]);

        $component->assertNotified();
        $component->assertTableActionMounted('ubah-transaksi');

        // Tidak ada perubahan tersimpan.
        $this->assertSame(0, $expense->fresh()->items()->count());
    }
}
