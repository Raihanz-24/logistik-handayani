<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\PosisiRak;
use App\Models\RakGudang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiActionService;
use App\Services\Ai\Tools\BuatDraftMutasiTool;
use App\Services\Ai\Tools\CariLokasiTool;
use App\Services\Ai\Tools\CariRakTool;
use App\Services\Ai\Tools\ToolException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regresi: AI harus bisa menemukan posisi rak pada gudang ber-rak, supaya
 * draft mutasi masuk/keluar tidak buntu dengan "Posisi rak tujuan wajib dipilih".
 */
class CariRakToolTest extends TestCase
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

    /** @return array{gudang: Lokasi, rak: PosisiRak} */
    private function gudangBerRak(): array
    {
        $gudang = Lokasi::query()->create([
            'nama_lokasi' => 'Gudang Utama',
            'kode_lokasi' => 'GU01',
            'jenis_lokasi' => Lokasi::JENIS_GUDANG,
            'menggunakan_rak' => true,
        ]);

        $rakGudang = RakGudang::query()->create([
            'lokasi_id' => $gudang->getKey(),
            'nomor_rak' => 1,
            'jumlah_tingkat' => 2,
            'aktif' => true,
        ]);

        $posisi = PosisiRak::query()->create([
            'rak_gudang_id' => $rakGudang->getKey(),
            'lokasi_id' => $gudang->getKey(),
            'nomor_tingkat' => 1,
            'kode' => 'RK1-01',
            'aktif' => true,
        ]);

        return ['gudang' => $gudang, 'rak' => $posisi];
    }

    public function test_cari_lokasi_menandai_pakai_rak_dan_jumlah_rak(): void
    {
        ['gudang' => $gudang] = $this->gudangBerRak();

        $user = $this->userWithPermissions(['view_any_lokasi']);

        $result = app(CariLokasiTool::class)->execute(['kata_kunci' => 'Gudang'], $user);

        $this->assertSame(1, $result['jumlah']);
        $lokasi = $result['lokasi'][0];
        $this->assertTrue($lokasi['pakai_rak']);
        $this->assertSame(1, $lokasi['jumlah_rak_aktif']);
        $this->assertArrayHasKey('catatan', $result);
    }

    public function test_cari_rak_mengembalikan_posisi_rak_aktif(): void
    {
        ['gudang' => $gudang, 'rak' => $posisi] = $this->gudangBerRak();

        $user = $this->userWithPermissions(['view_any_lokasi']);

        $result = app(CariRakTool::class)->execute(['lokasi_id' => $gudang->getKey()], $user);

        $this->assertTrue($result['ditemukan']);
        $this->assertTrue($result['pakai_rak']);
        $this->assertSame(1, $result['jumlah_rak_aktif']);
        $this->assertSame($posisi->getKey(), $result['rak'][0]['posisi_rak_id']);
        $this->assertSame('RK1-01', $result['rak'][0]['kode']);
    }

    public function test_cari_rak_menolak_tanpa_izin(): void
    {
        ['gudang' => $gudang] = $this->gudangBerRak();

        $user = $this->userWithPermissions([]);

        $registry = new \App\Services\Ai\Tools\ToolRegistry($user);

        $this->assertFalse($registry->has('cari_rak'));

        $this->expectException(ToolException::class);
        $registry->get('cari_rak');
    }

    public function test_cari_rak_tersedia_dengan_izin(): void
    {
        ['gudang' => $gudang] = $this->gudangBerRak();

        $user = $this->userWithPermissions(['view_any_lokasi']);

        $registry = new \App\Services\Ai\Tools\ToolRegistry($user);

        $this->assertTrue($registry->has('cari_rak'));

        $result = $registry->get('cari_rak')->execute(['lokasi_id' => $gudang->getKey()], $user);

        $this->assertSame(1, $result['jumlah_rak_aktif']);
    }

    public function test_draft_mutasi_masuk_berhasil_dengan_posisi_rak(): void
    {
        ['gudang' => $gudang, 'rak' => $posisi] = $this->gudangBerRak();
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Bu Ciko', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Air Galon', 'satuan' => 'galon']);

        $user = $this->userWithPermissions(['create_mutasi']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        $result = app(BuatDraftMutasiTool::class)->execute([
            'jenis_mutasi' => 'masuk',
            'barang_id' => $barang->getKey(),
            'jumlah' => 20,
            'lokasi_id' => $gudang->getKey(),
            'posisi_rak_tujuan_id' => $posisi->getKey(),
            'supplier_id' => $supplier->getKey(),
        ], $user, $conversation);

        $this->assertArrayHasKey('pending_action_id', $result);

        $action = AiPendingAction::query()->firstOrFail();
        $confirm = app(AiActionService::class)->confirm($action, $user);

        $this->assertSame('mutasi', $confirm['type']);
        $this->assertDatabaseHas('mutasis', [
            'barang_id' => $barang->getKey(),
            'lokasi_id' => $gudang->getKey(),
            'posisi_rak_tujuan_id' => $posisi->getKey(),
            'jumlah' => 20,
            'status' => 'pending',
        ]);
        // Belum mengubah stok.
        $this->assertSame(0, DB::table('barang_lokasi')->count());
    }

    public function test_error_draft_mutasi_tanpa_rak_menyertakan_daftar_rak(): void
    {
        ['gudang' => $gudang] = $this->gudangBerRak();
        $supplier = Supplier::query()->create(['nama_supplier' => 'Toko Bu Ciko', 'aktif' => true]);
        $barang = Barang::query()->create(['nama_barang' => 'Air Galon', 'satuan' => 'galon']);

        $user = $this->userWithPermissions(['create_mutasi']);
        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        try {
            app(BuatDraftMutasiTool::class)->execute([
                'jenis_mutasi' => 'masuk',
                'barang_id' => $barang->getKey(),
                'jumlah' => 20,
                'lokasi_id' => $gudang->getKey(),
                'supplier_id' => $supplier->getKey(),
            ], $user, $conversation);

            $this->fail('Seharusnya gagal karena posisi rak belum dipilih.');
        } catch (ToolException $exception) {
            $message = $exception->getMessage();
            $this->assertStringContainsString('Rak aktif di', $message);
            $this->assertStringContainsString('RK1-01', $message);
            $this->assertStringContainsString('posisi_rak_id='.$this->firstRakId(), $message);
        }
    }

    private function firstRakId(): int
    {
        return (int) PosisiRak::query()->value('id');
    }
}
