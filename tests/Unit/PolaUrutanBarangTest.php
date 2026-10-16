<?php

namespace Tests\Unit;

use App\Support\PolaUrutanBarang;
use PHPUnit\Framework\TestCase;

/**
 * Pengurutan barang mengikuti pola: subsekuens, item tak dibeli dilompati,
 * item di luar pola diletakkan di belakang tanpa mengacak relatifnya.
 */
class PolaUrutanBarangTest extends TestCase
{
    public function test_mengurutkan_sesuai_pola(): void
    {
        $hasil = PolaUrutanBarang::urutkan([3, 1, 2], [1, 2, 3]);

        $this->assertSame([1, 2, 3], $hasil);
    }

    public function test_item_pola_yang_tidak_dibeli_dilompati(): void
    {
        // Pola 1,2,3,4; yang dibeli 1,3,4 -> tetap 1,3,4.
        $hasil = PolaUrutanBarang::urutkan([4, 1, 3], [1, 2, 3, 4]);

        $this->assertSame([1, 3, 4], $hasil);
    }

    public function test_item_di_luar_pola_diletakkan_di_belakang(): void
    {
        $hasil = PolaUrutanBarang::urutkan([9, 2, 1, 8], [1, 2, 3]);

        // 1,2 sesuai pola; 9 lalu 8 (relatif masukan) di belakang.
        $this->assertSame([1, 2, 9, 8], $hasil);
    }

    public function test_tanpa_pola_urutan_dipertahankan(): void
    {
        $this->assertSame([5, 3, 1], PolaUrutanBarang::urutkan([5, 3, 1], []));
    }

    public function test_menghapus_duplikat(): void
    {
        $this->assertSame([1, 2], PolaUrutanBarang::urutkan([2, 1, 2, 1], [1, 2]));
    }

    public function test_sama_mendeteksi_urutan_identik(): void
    {
        $this->assertTrue(PolaUrutanBarang::sama([1, 2, 3], [1, 2, 3]));
        $this->assertFalse(PolaUrutanBarang::sama([2, 1, 3], [1, 2, 3]));
    }
}
