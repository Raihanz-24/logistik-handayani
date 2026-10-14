<?php

namespace Tests\Feature;

use App\Models\Barang;
use Database\Seeders\BarangPasarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeder daftar barang pasar:
 *  - membaca daftar dari database/seeders/data/barang-pasar.php,
 *  - membuat barang dengan toggle "Barang Pasar" aktif dan satuan default "-",
 *  - idempotent: dijalankan ulang tidak menggandakan barang.
 */
class BarangPasarSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seederWith(array $items): BarangPasarSeeder
    {
        $path = tempnam(sys_get_temp_dir(), 'barang-pasar-').'.php';
        file_put_contents($path, '<?php return '.var_export($items, true).';');

        $seeder = new BarangPasarSeeder;
        $seeder->dataFile = $path;

        return $seeder;
    }

    public function test_membuat_barang_pasar_dari_daftar_nama(): void
    {
        $this->seederWith(['Beras', 'Gula Pasir'])->run();

        $beras = Barang::query()->where('nama_barang', 'Beras')->first();

        $this->assertNotNull($beras);
        $this->assertTrue((bool) $beras->is_barang_pasar);
        $this->assertSame('-', $beras->satuan);
        $this->assertTrue($beras->isBarangPasar());
        $this->assertSame(2, Barang::query()->count());
    }

    public function test_mendukung_bentuk_array_dengan_satuan_dan_alias(): void
    {
        $this->seederWith([
            ['nama_barang' => 'Ayam Potong', 'satuan' => 'ekor', 'ai_alias' => 'ayam, daging ayam'],
        ])->run();

        $ayam = Barang::query()->where('nama_barang', 'Ayam Potong')->first();

        $this->assertNotNull($ayam);
        $this->assertSame('ekor', $ayam->satuan);
        $this->assertSame('ayam, daging ayam', $ayam->ai_alias);
        $this->assertTrue((bool) $ayam->is_barang_pasar);
    }

    public function test_idempotent_tidak_menggandakan_barang(): void
    {
        $items = ['Beras', 'Gula Pasir'];

        $this->seederWith($items)->run();
        $this->seederWith($items)->run();

        $this->assertSame(2, Barang::query()->count());
    }

    public function test_menandai_barang_lama_yang_sama_sebagai_barang_pasar(): void
    {
        $existing = Barang::query()->create(['nama_barang' => 'Beras', 'satuan' => 'karung']);

        $this->seederWith(['Beras'])->run();

        $existing->refresh();

        $this->assertSame(1, Barang::query()->count());
        $this->assertTrue((bool) $existing->is_barang_pasar);
        // Satuan yang sudah terisi tidak ditimpa.
        $this->assertSame('karung', $existing->satuan);
    }

    public function test_mengabaikan_entri_kosong(): void
    {
        $this->seederWith(['', '   ', ['nama_barang' => '']])->run();

        $this->assertSame(0, Barang::query()->count());
    }
}
