<?php

namespace App\Support;

/**
 * Sumber tunggal untuk daftar satuan barang & sinonimnya.
 *
 * Sebelumnya daftar satuan pasar diduplikasi di beberapa tempat (service
 * belanja, tool AI, seeder). Kelas ini menyatukannya agar perubahan cukup
 * di satu titik. Nilai lama tetap tersedia lewat const di
 * BelanjaTransactionService (delegasi) supaya kompatibel.
 */
final class BarangSatuan
{
    /** Satuan khusus: barang dicatat TANPA satuan (1 barang seharga nominal). */
    public const NO_UNIT = '-';

    /**
     * Satuan fleksibel yang boleh dipilih untuk barang pasar / belanja.
     *
     * @var array<int, string>
     */
    public const FLEXIBLE_MARKET_UNITS = ['gr', 'kg', 'biji', 'buah', 'ekor', 'ikt', 'ppn', 'btr', 'bks', self::NO_UNIT];

    /**
     * Sinonim kata satuan -> satuan kanonik. Dipakai normalisasi teks AI agar
     * ucapan seperti "setengah kilo" / "3 bungkus" menjadi "0,5 kg" / "3 bks".
     *
     * Nilai selalu berupa satuan yang ada di FLEXIBLE_MARKET_UNITS.
     *
     * @var array<string, string>
     */
    public const SINONYMS = [
        'kilo' => 'kg',
        'kilos' => 'kg',
        'kilogram' => 'kg',
        'kilograms' => 'kg',
        'kgs' => 'kg',
        'kiloan' => 'kg',
        'gram' => 'gr',
        'grams' => 'gr',
        'bungkus' => 'bks',
        'bungkusan' => 'bks',
        'bungkusnya' => 'bks',
        'bks' => 'bks',
        'pack' => 'bks',
        'packs' => 'bks',
        'ikat' => 'ikt',
        'ikatan' => 'ikt',
        'iket' => 'ikt',
        'ikt' => 'ikt',
        'butir' => 'btr',
        'butiran' => 'btr',
        'btr' => 'btr',
        'papan' => 'ppn',
        'ppn' => 'ppn',
        'biji' => 'biji',
        'buah' => 'buah',
        'ekor' => 'ekor',
        'gr' => 'gr',
        'kg' => 'kg',
    ];

    /**
     * Normalkan satu token satuan menjadi bentuk kanonik. Bila token bukan
     * sinonim yang dikenal, kembalikan apa adanya (huruf kecil aslinya).
     */
    public static function canonical(string $token): string
    {
        $lower = mb_strtolower(trim($token));

        return self::SINONYMS[$lower] ?? $token;
    }

    public static function isFlexibleUnit(string $unit): bool
    {
        return in_array(mb_strtolower(trim($unit)), self::FLEXIBLE_MARKET_UNITS, true);
    }
}
