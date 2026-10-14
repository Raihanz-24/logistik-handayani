<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Barang;
use App\Models\KalkulatorBelanja;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiActionService;
use App\Services\Ai\Tools\BuatDraftMutasiTool;
use App\Services\Ai\Tools\CatatTransaksiBelanjaTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AiWriteFlowTest extends TestCase
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

    public function test_tool_mutasi_membuat_pending_tanpa_mengubah_draft_mutasi(): void
    {
        $gudang = Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);
        $supplier = Supplier::query()->create(['nama_supplier' => 'PT Sumber', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);

        $user = $this->userWithPermissions(['create_mutasi']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        $result = app(BuatDraftMutasiTool::class)->execute([
            'jenis_mutasi' => 'masuk',
            'barang_id' => $barang->getKey(),
            'jumlah' => 5,
            'lokasi_id' => $gudang->getKey(),
            'supplier_id' => $supplier->getKey(),
        ], $user, $conversation);

        // Pending dibuat, tapi belum ada Mutasi.
        $this->assertDatabaseHas('ai_pending_actions', [
            'type' => AiPendingAction::TYPE_MUTASI_DRAFT,
            'status' => AiPendingAction::STATUS_PENDING,
        ]);
        $this->assertSame(0, Mutasi::query()->count());
        $this->assertArrayHasKey('pending_action_id', $result);
    }

    public function test_konfirmasi_mutasi_membuat_draft_status_pending(): void
    {
        $gudang = Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);
        $supplier = Supplier::query()->create(['nama_supplier' => 'PT Sumber', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);

        $user = $this->userWithPermissions(['create_mutasi']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(BuatDraftMutasiTool::class)->execute([
            'jenis_mutasi' => 'masuk',
            'barang_id' => $barang->getKey(),
            'jumlah' => 5,
            'lokasi_id' => $gudang->getKey(),
            'supplier_id' => $supplier->getKey(),
        ], $user, $conversation);

        $action = AiPendingAction::query()->firstOrFail();
        $result = app(AiActionService::class)->confirm($action, $user);

        $this->assertDatabaseHas('mutasis', [
            'barang_id' => $barang->getKey(),
            'lokasi_id' => $gudang->getKey(),
            'jumlah' => 5,
            'status' => 'pending',
        ]);

        // Aksi tidak meng-approve; stok tetap belum berubah.
        $this->assertDatabaseMissing('barang_lokasi', ['barang_id' => $barang->getKey()]);
        $this->assertSame(AiPendingAction::STATUS_CONFIRMED, $action->fresh()->status);
        $this->assertSame('mutasi', $result['type']);
    }

    public function test_konfirmasi_mutasi_tidak_dapat_dijalankan_dua_kali(): void
    {
        $gudang = Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);
        $supplier = Supplier::query()->create(['nama_supplier' => 'PT Sumber', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);

        $user = $this->userWithPermissions(['create_mutasi']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(BuatDraftMutasiTool::class)->execute([
            'jenis_mutasi' => 'masuk',
            'barang_id' => $barang->getKey(),
            'jumlah' => 5,
            'lokasi_id' => $gudang->getKey(),
            'supplier_id' => $supplier->getKey(),
        ], $user, $conversation);

        $action = AiPendingAction::query()->firstOrFail();
        $service = app(AiActionService::class);
        $service->confirm($action, $user);

        $this->expectExceptionMessage('Aksi ini sudah diproses sebelumnya.');
        $service->confirm($action->fresh(), $user);
    }

    public function test_konfirmasi_belanja_membuat_sesi_kalkulator_dan_transaksi(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);

        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [
                ['barang_id' => $barang->getKey(), 'jumlah' => 2, 'harga_satuan' => 65000],
            ],
        ], $user, $conversation);

        $action = AiPendingAction::query()->firstOrFail();
        $result = app(AiActionService::class)->confirm($action, $user);

        $this->assertSame(1, KalkulatorBelanja::query()->count());
        $this->assertDatabaseHas('pengeluaran_belanjas', ['supplier_id' => $supplier->getKey(), 'nominal' => 130000]);
        $this->assertSame('belanja', $result['type']);
    }

    public function test_batal_aksi_menandai_dibatalkan(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);

        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $barang->getKey(), 'jumlah' => 1, 'harga_satuan' => 1000]],
        ], $user, $conversation);

        $action = AiPendingAction::query()->firstOrFail();
        app(AiActionService::class)->cancel($action, $user);

        $this->assertSame(AiPendingAction::STATUS_CANCELLED, $action->fresh()->status);
        $this->assertSame(0, KalkulatorBelanja::query()->count());
    }

    public function test_aksi_user_lain_tidak_dapat_dikonfirmasi(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);

        $owner = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);
        $other = User::factory()->create();

        $conversation = AiConversation::query()->create(['user_id' => $owner->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $barang->getKey(), 'jumlah' => 1, 'harga_satuan' => 1000]],
        ], $owner, $conversation);

        $action = AiPendingAction::query()->firstOrFail();

        $this->expectExceptionMessage('Aksi ini bukan milik Anda.');
        app(AiActionService::class)->confirm($action, $other);
    }
}
