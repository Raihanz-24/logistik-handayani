<?php

namespace Tests\Feature\Ai;

use App\Models\Barang;
use App\Models\KalkulatorBelanja;
use App\Models\PengeluaranBelanja;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\Tools\RiwayatTransaksiBelanjaTool;
use App\Services\BelanjaTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Riwayat transaksi belanja: AI harus bisa membaca SEMUA item tiap transaksi
 * pada supplier+tanggal tertentu (landasan keputusan saat perintah ambigu).
 */
class AiRiwayatBelanjaTest extends TestCase
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

    private function transaksi(User $user, Supplier $supplier, string $tanggal, array $items): PengeluaranBelanja
    {
        $session = KalkulatorBelanja::query()->create([
            'user_id' => $user->getKey(),
            'tanggal' => $tanggal,
            'judul' => 'Belanja '.$tanggal,
            'uang_awal' => 0,
        ]);

        return app(BelanjaTransactionService::class)->save($session, null, [
            'supplier_id' => $supplier->getKey(),
            'items' => $items,
            'nota_paths' => [],
        ]);
    }

    public function test_menampilkan_semua_item_pada_supplier_dan_tanggal_tertentu(): void
    {
        $user = $this->userWithPermissions(['view_any_kalkulator_belanja']);
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        $supplierLain = Supplier::query()->create(['nama_supplier' => 'Bu Hen', 'aktif' => true]);

        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        $cabai = Barang::query()->create(['nama_barang' => 'Cabai', 'satuan' => 'kg']);
        $kangkung = Barang::query()->create(['nama_barang' => 'Kangkung', 'satuan' => 'ikt']);

        $this->transaksi($user, $supplier, '2026-10-12', [
            ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 8000, 'satuan' => '-'],
            ['barang_id' => $cabai->getKey(), 'jumlah' => 2, 'harga_satuan' => 25000, 'satuan' => 'kg'],
            ['barang_id' => $kangkung->getKey(), 'jumlah' => 3, 'harga_satuan' => 5000, 'satuan' => 'ikt'],
        ]);

        // Transaksi tanggal berbeda & supplier berbeda -> tidak boleh ikut.
        $this->transaksi($user, $supplier, '2026-10-13', [
            ['barang_id' => $cabai->getKey(), 'jumlah' => 1, 'harga_satuan' => 26000, 'satuan' => 'kg'],
        ]);
        $this->transaksi($user, $supplierLain, '2026-10-12', [
            ['barang_id' => $tempe->getKey(), 'jumlah' => 5, 'harga_satuan' => 8500, 'satuan' => '-'],
        ]);

        $result = app(RiwayatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'tanggal' => '2026-10-12',
        ], $user);

        $this->assertSame(1, $result['jumlah_transaksi']);
        $tx = $result['transaksi'][0];
        $this->assertSame('Bu Elly', $tx['supplier']);
        $this->assertSame('2026-10-12', $tx['tanggal']);
        $this->assertSame(3, $tx['jumlah_item']);

        $names = collect($tx['items'])->pluck('nama_barang')->all();
        $this->assertContains('Tempe', $names);
        $this->assertContains('Cabai', $names);
        $this->assertContains('Kangkung', $names);
    }

    public function test_filter_rentang_tanggal(): void
    {
        $user = $this->userWithPermissions(['view_any_kalkulator_belanja']);
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Hen', 'aktif' => true]);
        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);

        foreach (['2026-10-10', '2026-10-12', '2026-10-20'] as $tgl) {
            $this->transaksi($user, $supplier, $tgl, [
                ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 9000, 'satuan' => '-'],
            ]);
        }

        $result = app(RiwayatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'tanggal_dari' => '2026-10-11',
            'tanggal_sampai' => '2026-10-15',
        ], $user);

        $this->assertSame(1, $result['jumlah_transaksi']);
        $this->assertSame('2026-10-12', $result['transaksi'][0]['tanggal']);
    }

    public function test_tanpa_filter_mengembalikan_transaksi_terbaru(): void
    {
        $user = $this->userWithPermissions(['view_any_kalkulator_belanja']);
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);

        foreach (['2026-10-01', '2026-10-05'] as $tgl) {
            $this->transaksi($user, $supplier, $tgl, [
                ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 8000, 'satuan' => '-'],
            ]);
        }

        $result = app(RiwayatTransaksiBelanjaTool::class)->execute([], $user);

        $this->assertSame(2, $result['jumlah_transaksi']);
        // Terbaru (id lebih besar) lebih dulu.
        $this->assertSame('2026-10-05', $result['transaksi'][0]['tanggal']);
    }

    public function test_tidak_ada_transaksi_mengembalikan_catatan(): void
    {
        $user = $this->userWithPermissions(['view_any_kalkulator_belanja']);

        $result = app(RiwayatTransaksiBelanjaTool::class)->execute([
            'tanggal' => '2030-01-01',
        ], $user);

        $this->assertSame(0, $result['jumlah_transaksi']);
        $this->assertArrayHasKey('catatan', $result);
    }
}
