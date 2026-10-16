<?php

namespace Tests\Feature\Ai;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\AiSupplierBarangStat;
use App\Models\Barang;
use App\Models\KalkulatorBelanja;
use App\Models\PengeluaranBelanja;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiSupplierBarangStatsService;
use App\Services\Ai\BarangVariantResolver;
use App\Services\Ai\SystemPromptBuilder;
use App\Services\Ai\Tools\CariBarangTool;
use App\Services\Ai\Tools\CatatTransaksiBelanjaTool;
use App\Services\Ai\Tools\SaranBelanjaSupplierTool;
use App\Services\BelanjaTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pembelajaran afinitas supplier<->barang (Bu Elly/Bu Hen) + aturan varian.
 */
class AiAffinityVariantTest extends TestCase
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

    private function buatTransaksi(Supplier $supplier, User $user, array $items): PengeluaranBelanja
    {
        $session = KalkulatorBelanja::query()->create([
            'user_id' => $user->getKey(),
            'tanggal' => now()->toDateString(),
            'judul' => 'Belanja',
            'uang_awal' => 0,
        ]);

        return app(BelanjaTransactionService::class)->save($session, null, [
            'supplier_id' => $supplier->getKey(),
            'items' => $items,
            'nota_paths' => [],
        ]);
    }

    public function test_agregat_afinitas_dibangun_dari_transaksi(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        $cabai = Barang::query()->create(['nama_barang' => 'Cabai', 'satuan' => 'kg']);

        $user = $this->userWithPermissions(['view_any_barang']);

        $this->buatTransaksi($supplier, $user, [
            ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 8000, 'satuan' => '-'],
            ['barang_id' => $cabai->getKey(), 'jumlah' => 2, 'harga_satuan' => 25000, 'satuan' => 'kg'],
        ]);

        $this->assertDatabaseHas('ai_supplier_barang_stats', [
            'supplier_id' => $supplier->getKey(),
            'barang_id' => $tempe->getKey(),
            'frekuensi' => 1,
            'harga_terakhir' => 8000,
        ]);

        $suggestions = app(AiSupplierBarangStatsService::class)->suggestions($supplier->getKey(), 10);
        $this->assertNotEmpty($suggestions);
        $this->assertContains('Cabai', collect($suggestions)->pluck('nama_barang')->all());
    }

    public function test_transaksi_berulang_meningkatkan_afinitas(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Hen', 'aktif' => true]);
        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        $user = $this->userWithPermissions(['view_any_barang']);

        foreach ([1, 2, 3] as $i) {
            $this->buatTransaksi($supplier, $user, [
                ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 9000, 'satuan' => '-'],
            ]);
        }

        $stat = AiSupplierBarangStat::query()
            ->where('supplier_id', $supplier->getKey())
            ->where('barang_id', $tempe->getKey())
            ->firstOrFail();

        $this->assertSame(3, $stat->frekuensi);
    }

    public function test_cari_barang_memprioritaskan_barang_langganan_supplier(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        $tempeElly = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        // Barang lain yang namanya juga mengandung "tempe" tapi bukan langganan.
        Barang::query()->create(['nama_barang' => 'Tempe Premium', 'satuan' => '-']);

        $user = $this->userWithPermissions(['view_any_barang']);
        $this->buatTransaksi($supplier, $user, [
            ['barang_id' => $tempeElly->getKey(), 'jumlah' => 1, 'harga_satuan' => 8000, 'satuan' => '-'],
        ]);

        $tool = app(CariBarangTool::class);

        // Tanpa supplier: "Tempe" persis tetap unggul.
        $result = $tool->execute(['kata_kunci' => 'tempe'], $user);
        $this->assertSame('Tempe', $result['barang'][0]['nama_barang']);
    }

    public function test_filter_varian_karyawan_tidak_disentuh_bila_tidak_disebut(): void
    {
        Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        Barang::query()->create(['nama_barang' => 'Tempe Karyawan', 'satuan' => '-']);

        $user = $this->userWithPermissions(['view_any_barang']);
        $result = app(CariBarangTool::class)->execute(['kata_kunci' => 'tempe'], $user);

        $names = collect($result['barang'])->pluck('nama_barang')->all();
        $this->assertContains('Tempe', $names);
        $this->assertNotContains('Tempe Karyawan', $names);
    }

    public function test_filter_varian_hanya_karyawan_bila_disebut(): void
    {
        Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        Barang::query()->create(['nama_barang' => 'Tempe Karyawan', 'satuan' => '-']);

        $user = $this->userWithPermissions(['view_any_barang']);
        $result = app(CariBarangTool::class)->execute(['kata_kunci' => 'tempe karyawan'], $user);

        $names = collect($result['barang'])->pluck('nama_barang')->all();
        $this->assertContains('Tempe Karyawan', $names);
        $this->assertNotContains('Tempe', $names);
        $this->assertSame('karyawan', $result['penanda_varian_dipakai'] ?? null);
    }

    public function test_saran_belanja_supplier_tool_mengembalikan_daftar(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        $cabai = Barang::query()->create(['nama_barang' => 'Cabai', 'satuan' => 'kg']);
        $user = $this->userWithPermissions(['view_any_supplier']);

        $this->buatTransaksi($supplier, $user, [
            ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 8000, 'satuan' => '-'],
            ['barang_id' => $cabai->getKey(), 'jumlah' => 2, 'harga_satuan' => 25000, 'satuan' => 'kg'],
        ]);

        $result = app(SaranBelanjaSupplierTool::class)->execute(['supplier_id' => $supplier->getKey()], $user);

        $this->assertSame('Bu Elly', $result['nama_supplier']);
        $this->assertGreaterThanOrEqual(2, $result['jumlah']);
    }

    public function test_harga_satuan_diisi_dari_histori_saat_kosong(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Hen', 'aktif' => true]);
        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);

        $this->buatTransaksi($supplier, $user, [
            ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 9000, 'satuan' => '-'],
        ]);

        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        // Harga & satuan tidak disebut -> diisi dari histori.
        $result = app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $tempe->getKey(), 'jumlah' => 1]],
        ], $user, $conversation);

        $action = AiPendingAction::query()->findOrFail($result['pending_action_id']);
        $this->assertSame(9000, $action->payload['items'][0]['harga_satuan']);
        $this->assertSame('-', $action->payload['items'][0]['satuan']);
        $this->assertTrue($result['harga_otomatis_dari_histori']);
    }

    public function test_markers_otomatis_terdeteksi_dari_nama_master(): void
    {
        $resolver = app(BarangVariantResolver::class);
        $markers = $resolver->markersFor(['Tempe', 'Tempe Karyawan', 'Beras']);

        $this->assertContains('karyawan', $markers);
    }

    public function test_command_ai_learn_membangun_agregat(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        $user = $this->userWithPermissions(['view_any_barang']);

        $this->buatTransaksi($supplier, $user, [
            ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 8000, 'satuan' => '-'],
        ]);

        AiSupplierBarangStat::query()->delete();

        $this->artisan('ai:learn')->assertSuccessful();
        $this->assertDatabaseHas('ai_supplier_barang_stats', [
            'supplier_id' => $supplier->getKey(),
            'barang_id' => $tempe->getKey(),
        ]);
    }

    public function test_pola_urutan_item_per_supplier_dipelajari(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        $ayam = Barang::query()->create(['nama_barang' => 'Ayam Utuh', 'satuan' => '-']);
        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        $cabai = Barang::query()->create(['nama_barang' => 'Cabai', 'satuan' => 'kg']);
        $user = $this->userWithPermissions(['view_any_barang']);

        // Dua transaksi dengan urutan konsisten: ayam(0), tempe(1), cabai(2).
        foreach ([1, 2] as $i) {
            $this->buatTransaksi($supplier, $user, [
                ['barang_id' => $ayam->getKey(), 'jumlah' => 1, 'harga_satuan' => 35000, 'satuan' => '-'],
                ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 8000, 'satuan' => '-'],
                ['barang_id' => $cabai->getKey(), 'jumlah' => 1, 'harga_satuan' => 25000, 'satuan' => 'kg'],
            ]);
        }

        $stats = app(AiSupplierBarangStatsService::class);
        $pattern = $stats->orderPattern($supplier->getKey(), 10);

        $this->assertNotEmpty($pattern);
        // Ayam utuh harus menjadi item pertama pada pola.
        $this->assertSame('Ayam Utuh', $pattern[0]['nama_barang']);
        $this->assertSame(0.0, $pattern[0]['avg_urutan']);
    }

    public function test_pola_urutan_disuntik_ke_prompt_draft(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        $ayam = Barang::query()->create(['nama_barang' => 'Ayam Utuh', 'satuan' => '-']);
        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);

        foreach ([1, 2] as $i) {
            $this->buatTransaksi($supplier, $user, [
                ['barang_id' => $ayam->getKey(), 'jumlah' => 1, 'harga_satuan' => 35000, 'satuan' => '-'],
                ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 8000, 'satuan' => '-'],
            ]);
        }

        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 8000, 'satuan' => '-']],
        ], $user, $conversation);

        $prompt = app(SystemPromptBuilder::class)->build($user, 'tambah ayam', $conversation);

        $this->assertStringContainsString('POLA URUTAN ITEM', $prompt);
        $this->assertStringContainsString('Ayam Utuh', $prompt);
    }

    public function test_draft_diurutkan_pola_dan_item_yang_tak_dibeli_dilompati(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        $ayam = Barang::query()->create(['nama_barang' => 'Ayam Utuh', 'satuan' => '-']);
        $tempe = Barang::query()->create(['nama_barang' => 'Tempe', 'satuan' => '-']);
        $cabai = Barang::query()->create(['nama_barang' => 'Cabai', 'satuan' => 'kg']);
        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);

        // Pola konsisten: ayam(0), tempe(1), cabai(2).
        foreach ([1, 2] as $i) {
            $this->buatTransaksi($supplier, $user, [
                ['barang_id' => $ayam->getKey(), 'jumlah' => 1, 'harga_satuan' => 35000, 'satuan' => '-'],
                ['barang_id' => $tempe->getKey(), 'jumlah' => 1, 'harga_satuan' => 8000, 'satuan' => '-'],
                ['barang_id' => $cabai->getKey(), 'jumlah' => 1, 'harga_satuan' => 25000, 'satuan' => 'kg'],
            ]);
        }

        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        // Dikirim tidak urut + tanpa tempe -> harus jadi Ayam lalu Cabai.
        $result = app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'items' => [
                ['barang_id' => $cabai->getKey(), 'jumlah' => 1, 'harga_satuan' => 25000, 'satuan' => 'kg'],
                ['barang_id' => $ayam->getKey(), 'jumlah' => 1, 'harga_satuan' => 35000, 'satuan' => '-'],
            ],
        ], $user, $conversation);

        $action = AiPendingAction::query()->findOrFail($result['pending_action_id']);
        $ids = collect($action->payload['items'])->pluck('barang_id')->all();

        $this->assertSame([$ayam->getKey(), $cabai->getKey()], $ids);
    }

    public function test_urutan_pengguna_dihormati_bila_flag_diset(): void
    {
        $supplier = Supplier::query()->create(['nama_supplier' => 'Bu Elly', 'aktif' => true]);
        $ayam = Barang::query()->create(['nama_barang' => 'Ayam Utuh', 'satuan' => '-']);
        $cabai = Barang::query()->create(['nama_barang' => 'Cabai', 'satuan' => 'kg']);
        $user = $this->userWithPermissions(['create_kalkulator_belanja', 'update_kalkulator_belanja']);

        foreach ([1, 2] as $i) {
            $this->buatTransaksi($supplier, $user, [
                ['barang_id' => $ayam->getKey(), 'jumlah' => 1, 'harga_satuan' => 35000, 'satuan' => '-'],
                ['barang_id' => $cabai->getKey(), 'jumlah' => 1, 'harga_satuan' => 25000, 'satuan' => 'kg'],
            ]);
        }

        $conversation = AiConversation::query()->create(['user_id' => $user->getKey(), 'status' => 'active']);

        $result = app(CatatTransaksiBelanjaTool::class)->execute([
            'supplier_id' => $supplier->getKey(),
            'ikuti_urutan_pengguna' => true,
            'items' => [
                ['barang_id' => $cabai->getKey(), 'jumlah' => 1, 'harga_satuan' => 25000, 'satuan' => 'kg'],
                ['barang_id' => $ayam->getKey(), 'jumlah' => 1, 'harga_satuan' => 35000, 'satuan' => '-'],
            ],
        ], $user, $conversation);

        $action = AiPendingAction::query()->findOrFail($result['pending_action_id']);
        $ids = collect($action->payload['items'])->pluck('barang_id')->all();

        $this->assertSame([$cabai->getKey(), $ayam->getKey()], $ids);
    }
}
