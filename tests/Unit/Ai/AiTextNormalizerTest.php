<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiTextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiTextNormalizerTest extends TestCase
{
    #[DataProvider('angkaProvider')]
    public function test_menormalkan_kata_angka_menjadi_digit(string $input, string $expected): void
    {
        $this->assertSame($expected, AiTextNormalizer::normalize($input));
    }

    public static function angkaProvider(): array
    {
        return [
            'satuan' => ['beli tiga sabun', 'beli 3 sabun'],
            'belasan' => ['dua belas galon', '12 galon'],
            'puluhan' => ['dua puluh lima', '25'],
            'puluhan nempel' => ['dua puluh lima galon', '25 galon'],
            'ratusan' => ['seratus dua puluh', '120'],
            'ribuan' => ['seribu lima ratus', '1500'],
            'ribuan kata' => ['dua ribu', '2000'],
            'seribu' => ['seribu', '1000'],
            'seratus' => ['seratus', '100'],
            'juta' => ['satu juta lima ratus ribu', '1500000'],
            'campur beberapa' => ['tiga sabun, dua galon', '3 sabun, 2 galon'],
            'setengah' => ['setengah kilo', '0,5 kg'],
            'seperempat' => ['seperempat kg', '0,25 kg'],
            'satu setengah' => ['satu setengah liter', '1,5 liter'],
            'tanpa angka' => ['beli sabun', 'beli sabun'],
            'kata_berawalan angka tidak diubah' => ['satuan barang', 'satuan barang'],
            'nol' => ['nol', '0'],
        ];
    }

    #[DataProvider('satuanProvider')]
    public function test_menormalkan_sinonim_satuan(string $input, string $expected): void
    {
        $this->assertSame($expected, AiTextNormalizer::normalize($input));
    }

    public static function satuanProvider(): array
    {
        return [
            'kilo ke kg' => ['setengah kilo cabai', '0,5 kg cabai'],
            'kilogram ke kg' => ['2 kilogram beras', '2 kg beras'],
            'bungkus ke bks' => ['3 bungkus mie', '3 bks mie'],
            'pack ke bks' => ['1 pack gula', '1 bks gula'],
            'ikat ke ikt' => ['3 ikat kangkung', '3 ikt kangkung'],
            'butir ke btr' => ['2 butir telur', '2 btr telur'],
            'papan ke ppn' => ['1 papan telur', '1 ppn telur'],
            'gram tetap gr' => ['500 gram gula', '500 gr gula'],
            'satuan kanonik tidak berubah' => ['2 kg beras', '2 kg beras'],
            'tanpa satuan dibiarkan' => ['wortel 25 ribu', 'wortel 25000'],
        ];
    }

    public function test_kata_angka_di_dalam_kata_tidak_diubah(): void
    {
        // "satu" ada di dalam "satuan" — jangan diubah karena bukan token utuh.
        $this->assertSame('satuan', AiTextNormalizer::normalize('satuan'));
        $this->assertSame('kesatuan', AiTextNormalizer::normalize('kesatuan'));
    }

    public function test_teks_kosong_tidak_berubah(): void
    {
        $this->assertSame('', AiTextNormalizer::normalize(''));
        $this->assertSame('   ', AiTextNormalizer::normalize('   '));
    }
}
