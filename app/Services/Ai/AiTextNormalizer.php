<?php

namespace App\Services\Ai;

use App\Support\BarangSatuan;

/**
 * Normalisasi teks perintah suara SEBELUM dikirim ke model.
 *
 * Fokus: mengubah kata-angka Bahasa Indonesia menjadi digit, agar model tidak
 * salah menafsirkan jumlah (mis. "seribu lima ratus" → 1500, "dua puluh lima"
 * → 25). Hanya token yang berdiri sendiri yang diubah, sehingga nama barang
 * seperti "satuan" atau "Sepuluh" (bagian kata lain) tetap aman.
 *
 * Selain kata-angka, beberapa pecahan umum juga dinormalkan:
 * - "setengah"    → 0,5
 * - "seperempat"  → 0,25
 * - "tiga perempat" → 0,75
 *
 * Terakhir, sinonim satuan dinormalkan ke bentuk kanonik (lihat BarangSatuan):
 * - "kilo"/"kilogram" → kg
 * - "bungkus"/"pack"  → bks
 * - "ikat"            → ikt
 * sehingga "setengah kilo" menjadi "0,5 kg" dan "3 bungkus" menjadi "3 bks".
 */
class AiTextNormalizer
{
    /** Nilai numeral dasar (kata tunggal). */
    private const UNITS = [
        'nol' => 0,
        'kosong' => 0,
        'satu' => 1,
        'dua' => 2,
        'tiga' => 3,
        'empat' => 4,
        'lima' => 5,
        'enam' => 6,
        'tujuh' => 7,
        'delapan' => 8,
        'sembilan' => 9,
        'sepuluh' => 10,
        'sebelas' => 11,
    ];

    /** Kata pengali yang menempel pada numeral di kirinya. */
    private const MULTIPLIERS = [
        'puluh' => 10,
        'belas' => 10,
        'ratus' => 100,
        'ribu' => 1000,
        'juta' => 1000000,
        'miliar' => 1000000000,
        'milyar' => 1000000000,
        'triliun' => 1000000000000,
    ];

    /** Bentuk "se-" yang berarti satu pengali penuh. */
    private const SE_FORMS = [
        'seratus' => 100,
        'seribu' => 1000,
        'sejuta' => 1000000,
    ];

    /** Pecahan khusus yang tidak dicakup pola kata-angka biasa. */
    private const FRACTIONS = [
        // Frasa multi-kata lebih dulu agar tidak "terpotong" oleh bentuk pendek.
        'satu setengah' => '1,5',
        'dua setengah' => '2,5',
        'tiga perempat' => '0,75',
        'setengah' => '0,5',
        'seperempat' => '0,25',
    ];

    /** Apakah sebuah token (huruf kecil) termasuk kata-angka yang dikenali. */
    public static function isNumberWord(string $token): bool
    {
        return isset(self::UNITS[$token])
            || isset(self::MULTIPLIERS[$token])
            || isset(self::SE_FORMS[$token]);
    }

    /**
     * Normalkan seluruh teks. Bila tidak ada yang berubah, kembalikan apa adanya.
     */
    public static function normalize(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        // Gabungkan digit + kata pengali mata uang ("25 ribu" → 25000).
        $text = self::normalizeDigitScales($text);

        // Pecahan multi-kata lebih dulu (sebelum tokenisasi).
        $text = self::normalizeFractions($text);

        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false || $parts === []) {
            return $text;
        }

        $out = '';
        $i = 0;
        $count = count($parts);

        while ($i < $count) {
            $token = $parts[$i];
            $lower = mb_strtolower(trim($token));

            if ($lower !== '' && self::isNumberWord($lower)) {
                [$words, $next] = self::collectRun($parts, $i);

                $value = self::wordsToNumber($words);

                if ($value !== null) {
                    $out .= (string) $value;
                    $i = $next;

                    continue;
                }
            }

            $out .= $token;
            $i++;
        }

        // Normalisasi sinonim satuan ("kilo"→kg, "bungkus"→bks, "ikat"→ikt).
        $out = self::normalizeUnits($out);

        return $out;
    }

    /**
     * Ganti sinonim satuan menjadi bentuk kanonik tanpa mengubah tanda baca
     * maupun kata lain. Hanya token utuh (dipisah spasi/tanda baca) yang diubah
     * sehingga "kiloan" pada nama barang tetap aman bila bukan satuan.
     */
    public static function normalizeUnits(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $changed = preg_replace_callback(
            '/\b([A-Za-z]+)\b/u',
            static function (array $matches): string {
                $canonical = BarangSatuan::canonical($matches[1]);

                return $canonical === $matches[1] ? $matches[1] : $canonical;
            },
            $text,
        );

        return $changed ?? $text;
    }

    /** Ganti pecahan khusus dengan bentuk desimalnya. */
    private static function normalizeFractions(string $text): string
    {
        return str_ireplace(
            array_keys(self::FRACTIONS),
            array_values(self::FRACTIONS),
            $text,
        );
    }

    /**
     * Gabungkan digit yang diikuti kata pengali menjadi satu bilangan.
     * Contoh: "25 ribu" → "25000", "1,5 juta" → "1500000".
     *
     * Hanya berlaku bila sebelum pengali ada digit (bukan kata-angka, yang
     * sudah ditangani wordsToNumber).
     */
    private static function normalizeDigitScales(string $text): string
    {
        $scales = [
            'ratus' => 100,
            'ribu' => 1000,
            'juta' => 1000000,
            'miliar' => 1000000000,
            'milyar' => 1000000000,
            'triliun' => 1000000000000,
        ];

        $pattern = '/(\d+(?:[.,]\d+)?)\s*('.implode('|', array_keys($scales)).')\b/ui';

        $changed = preg_replace_callback($pattern, static function (array $matches) use ($scales): string {
            $number = (float) str_replace(',', '.', $matches[1]);
            $scale = $scales[mb_strtolower($matches[2])];
            $value = $number * $scale;

            return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
        }, $text);

        return $changed ?? $text;
    }

    /**
     * Kumpulkan deret kata-angka mulai dari indeks $start (termasuk spasi yang
     * berada di antara kata-angka). Mengembalikan daftar kata + indeks berikutnya.
     *
     * @param  array<int, string>  $parts
     * @return array{0: array<int, string>, 1: int}
     */
    private static function collectRun(array $parts, int $start): array
    {
        $words = [];
        $i = $start;
        $count = count($parts);

        while ($i < $count) {
            $token = $parts[$i];
            $lower = mb_strtolower(trim($token));

            if ($lower !== '' && self::isNumberWord($lower)) {
                $words[] = $lower;
                $i++;

                continue;
            }

            // Spasi diizinkan di tengah deret bila token berikutnya kata-angka.
            if ($lower === '' && preg_match('/^\s+$/u', $token)) {
                $next = $i + 1;

                if ($next < $count && self::isNumberWord(mb_strtolower(trim($parts[$next])))) {
                    $i = $next;

                    continue;
                }
            }

            break;
        }

        return [$words, $i];
    }

    /**
     * Ubah deret kata-angka menjadi bilangan bulat. Mengembalikan null bila
     * deret kosong.
     *
     * @param  array<int, string>  $words
     */
    public static function wordsToNumber(array $words): ?int
    {
        if ($words === []) {
            return null;
        }

        $result = 0;
        $current = 0;
        $pending = null; // numeral yang menunggu kata pengali

        $flushPending = function () use (&$current, &$pending): void {
            if ($pending !== null) {
                $current += $pending;
                $pending = null;
            }
        };

        foreach ($words as $word) {
            if (isset(self::UNITS[$word])) {
                $flushPending();
                $pending = self::UNITS[$word];

                continue;
            }

            if (isset(self::SE_FORMS[$word])) {
                $flushPending();
                $current += self::SE_FORMS[$word];

                continue;
            }

            // Kata pengali.
            $base = self::MULTIPLIERS[$word];

            if ($base === 10 || $base === 100) {
                // puluh/belas/ratus hanya mengalikan numeral terakhir.
                $digit = $pending ?? 1;
                $pending = null;

                if ($word === 'belas') {
                    $current += $digit + 10;
                } else {
                    $current += $digit * $base;
                }

                continue;
            }

            // ribu/juta/... mengalikan seluruh blok saat ini.
            $flushPending();
            $block = $current === 0 ? 1 : $current;
            $result += $block * $base;
            $current = 0;
        }

        $flushPending();

        return $result + $current;
    }
}
