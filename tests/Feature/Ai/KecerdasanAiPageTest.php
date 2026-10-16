<?php

namespace Tests\Feature\Ai;

use App\Filament\Pages\KecerdasanAi;
use App\Models\AiSearchLog;
use App\Models\Barang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Halaman penilaian "Kecerdasan AI": metrik pengenalan + pola urutan.
 */
class KecerdasanAiPageTest extends TestCase
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

    public function test_halaman_dapat_dirender_dan_menampilkan_metrik(): void
    {
        $user = $this->userWithPermissions(['view_any_barang']);

        $barang = Barang::query()->create(['nama_barang' => 'Cabai', 'satuan' => 'kg']);

        AiSearchLog::query()->create([
            'user_id' => $user->getKey(),
            'kata_kunci' => 'cabai',
            'jumlah_kandidat' => 3,
            'pilihan_terbaik_barang_id' => $barang->getKey(),
            'skor_teratas' => 900,
            'ambigu' => false,
        ]);

        AiSearchLog::query()->create([
            'user_id' => $user->getKey(),
            'kata_kunci' => 'gula',
            'jumlah_kandidat' => 2,
            'pilihan_terbaik_barang_id' => null,
            'skor_teratas' => 150,
            'ambigu' => true,
        ]);

        $this->actingAs($user);

        $response = $this->get('/admin/kecerdasan-ai');

        $response->assertOk();
        $response->assertSee('Kecerdasan AI', false);
        $response->assertSee('Pengenalan barang');
        $response->assertSee('gula'); // kata ambigu muncul
    }

    public function test_metrik_pengenalan_dihitung_benar(): void
    {
        $user = $this->userWithPermissions(['view_any_barang']);

        $page = new KecerdasanAi;

        // Tanpa log -> 0.
        $awal = $page->recognitionStats();
        $this->assertSame(0, $awal['total']);
        $this->assertSame(0.0, $awal['akurasi']);

        $barang = Barang::query()->create(['nama_barang' => 'Cabai', 'satuan' => 'kg']);

        AiSearchLog::query()->create(['kata_kunci' => 'a', 'pilihan_terbaik_barang_id' => $barang->getKey(), 'ambigu' => false, 'jumlah_kandidat' => 1, 'skor_teratas' => 900]);
        AiSearchLog::query()->create(['kata_kunci' => 'b', 'pilihan_terbaik_barang_id' => null, 'ambigu' => true, 'jumlah_kandidat' => 2, 'skor_teratas' => 100]);

        $stats = $page->recognitionStats();

        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['tepat']);
        $this->assertSame(1, $stats['ambigu']);
        $this->assertSame(50.0, $stats['akurasi']);
    }
}
