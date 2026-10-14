<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Barang;
use App\Models\BarangLokasi;
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

    public function test_draft_belanja_tanpa_satuan_minimal_satu_barang(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Ayam Broiler', 'satuan' => '-']);

        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        // "Ayam broiler 100 ribu" → jumlah 1, satuan "-", harga 100000.
        $result = app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [
                ['barang_id' => $barang->getKey(), 'jumlah' => 1, 'harga_satuan' => 100000, 'satuan' => '-'],
            ],
        ], $user, $conversation);

        $action = AiPendingAction::query()->findOrFail($result['pending_action_id']);
        $this->assertSame('-', $action->payload['items'][0]['satuan']);
        $this->assertStringContainsString('Ayam Broiler × 1 -', $action->summary);

        // Konfirmasi menyimpan satuan_snapshot "-" pada item.
        app(AiActionService::class)->confirm($action, $user);

        $this->assertDatabaseHas('pengeluaran_belanja_items', [
            'barang_id' => $barang->getKey(),
            'satuan_snapshot' => '-',
            'harga_satuan' => 100000,
            'subtotal' => 100000,
        ]);
    }

    public function test_barang_gudang_dapat_dicatat_tanpa_satuan_strip(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        // Barang gudang (bukan pasar) pun boleh dicatat tanpa satuan bila diminta.
        $barang = Barang::query()->create(['nama_barang' => 'Tenda', 'satuan' => 'unit']);

        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        $result = app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [
                ['barang_id' => $barang->getKey(), 'jumlah' => 1, 'harga_satuan' => 250000, 'satuan' => '-'],
            ],
        ], $user, $conversation);

        app(AiActionService::class)->confirm(AiPendingAction::query()->findOrFail($result['pending_action_id']), $user);

        $this->assertDatabaseHas('pengeluaran_belanja_items', [
            'barang_id' => $barang->getKey(),
            'satuan_snapshot' => '-',
        ]);
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

    public function test_draft_belanja_baru_menggantikan_pending_lama_pada_percakapan_sama(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Berkah', 'aktif' => true]);
        $beras = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);
        $minyak = Barang::query()->create(['nama_barang' => 'Minyak', 'satuan' => 'liter']);

        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        $tool = app(CatatTransaksiBelanjaTool::class);

        // Draft pertama: hanya beras.
        $tool->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $beras->getKey(), 'jumlah' => 1, 'harga_satuan' => 1000]],
        ], $user, $conversation);

        // Draft kedua (user "menambah item"): beras + minyak.
        $tool->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [
                ['barang_id' => $beras->getKey(), 'jumlah' => 1, 'harga_satuan' => 1000],
                ['barang_id' => $minyak->getKey(), 'jumlah' => 2, 'harga_satuan' => 2000],
            ],
        ], $user, $conversation);

        // Hanya satu pending yang tersisa (draft terbaru); draft lama superseded.
        $this->assertSame(1, AiPendingAction::query()
            ->where('type', AiPendingAction::TYPE_PENGELUARAN_BELANJA)
            ->where('status', AiPendingAction::STATUS_PENDING)
            ->count());
        $this->assertSame(1, AiPendingAction::query()
            ->where('status', AiPendingAction::STATUS_SUPERSEDED)
            ->count());

        // Draft lama tidak lagi bisa dikonfirmasi.
        $superseded = AiPendingAction::query()->where('status', AiPendingAction::STATUS_SUPERSEDED)->firstOrFail();
        $this->expectExceptionMessage('Aksi ini sudah diproses sebelumnya.');
        app(AiActionService::class)->confirm($superseded, $user);
    }

    public function test_draft_mutasi_baru_menggantikan_pending_lama(): void
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

        $tool = app(BuatDraftMutasiTool::class);

        $tool->execute([
            'jenis_mutasi' => 'masuk',
            'barang_id' => $barang->getKey(),
            'jumlah' => 5,
            'lokasi_id' => $gudang->getKey(),
            'supplier_id' => $supplier->getKey(),
        ], $user, $conversation);

        $tool->execute([
            'jenis_mutasi' => 'masuk',
            'barang_id' => $barang->getKey(),
            'jumlah' => 8,
            'lokasi_id' => $gudang->getKey(),
            'supplier_id' => $supplier->getKey(),
        ], $user, $conversation);

        $this->assertSame(1, AiPendingAction::query()
            ->where('type', AiPendingAction::TYPE_MUTASI_DRAFT)
            ->where('status', AiPendingAction::STATUS_PENDING)
            ->count());
        $this->assertSame(1, AiPendingAction::query()
            ->where('status', AiPendingAction::STATUS_SUPERSEDED)
            ->count());
    }

    public function test_mutasi_keluar_melebihi_stok_ditolak_sebelum_pending_dibuat(): void
    {
        $gudang = Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);
        $tujuan = Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Cabang',
            'kode_lokasi' => 'GC01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);
        $barang = Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);

        BarangLokasi::query()->create([
            'barang_id' => $barang->getKey(),
            'lokasi_id' => $gudang->getKey(),
            'stok' => 3,
            'stok_baik' => 3,
            'stok_rusak' => 0,
            'stok_hilang' => 0,
        ]);

        $user = $this->userWithPermissions(['create_mutasi']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        try {
            app(BuatDraftMutasiTool::class)->execute([
                'jenis_mutasi' => 'keluar',
                'barang_id' => $barang->getKey(),
                'jumlah' => 10, // melebihi stok 3
                'lokasi_id' => $gudang->getKey(),
                'lokasi_tujuan_id' => $tujuan->getKey(),
                'kondisi_asal' => 'baik',
            ], $user, $conversation);

            $this->fail('Draft mutasi melebihi stok seharusnya ditolak.');
        } catch (\App\Services\Ai\Tools\ToolException $exception) {
            $this->assertStringContainsString('tidak mencukupi', $exception->getMessage());
        }

        // Tidak ada pending dibuat & tidak ada mutasi.
        $this->assertSame(0, AiPendingAction::query()->count());
        $this->assertSame(0, Mutasi::query()->count());
    }

    public function test_mutasi_keluar_dalam_batas_stok_dibuat_pending(): void
    {
        $gudang = Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);
        $tujuan = Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Cabang',
            'kode_lokasi' => 'GC01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
        ]);
        $barang = Barang::query()->create(['nama_barang' => 'Galon Aqua', 'satuan' => 'buah']);

        BarangLokasi::query()->create([
            'barang_id' => $barang->getKey(),
            'lokasi_id' => $gudang->getKey(),
            'stok' => 10,
            'stok_baik' => 10,
            'stok_rusak' => 0,
            'stok_hilang' => 0,
        ]);

        $user = $this->userWithPermissions(['create_mutasi']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        $result = app(BuatDraftMutasiTool::class)->execute([
            'jenis_mutasi' => 'keluar',
            'barang_id' => $barang->getKey(),
            'jumlah' => 4,
            'lokasi_id' => $gudang->getKey(),
            'lokasi_tujuan_id' => $tujuan->getKey(),
            'kondisi_asal' => 'baik',
        ], $user, $conversation);

        $this->assertArrayHasKey('pending_action_id', $result);
        $this->assertSame(1, AiPendingAction::query()
            ->where('type', AiPendingAction::TYPE_MUTASI_DRAFT)
            ->where('status', AiPendingAction::STATUS_PENDING)
            ->count());
    }
}
