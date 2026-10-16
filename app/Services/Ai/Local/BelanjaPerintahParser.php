<?php

namespace App\Services\Ai\Local;

use App\Support\BarangSatuan;

/**
 * Parser DETERMINISTIK perintah belanja (tanpa LLM).
 *
 * Tujuan: mengenali perintah yang JELAS & terstruktur sehingga bisa diproses
 * tanpa memanggil API model (hemat token) dan tanpa risiko "mengarang"
 * (lebih akurat). Bila ragu sedikit pun, kembalikan null agar diteruskan ke
 * model.
 *
 * Pola yang didukung (mirip kebiasaan pengguna):
 *   "catat belanja ke Bu Elly: tempe 5rb, cabai 2 kg 25000"
 *   "order ayam broiler 100000 dan kangkung 3 ikt 5000 dari Bu Hen"
 * Aturan nilai: HARGA = harga satuan; jumlah default 1; tanpa satuan -> "-".
 */
class BelanjaPerintahParser
{
    /** Kata pemicu aksi belanja. */
    private const VERBS = [
        'catat', 'catatkan', 'belanja', 'belanjaan', 'order', 'orderan',
        'pesan', 'pesanan', 'beli', 'dibeli', 'bayar', 'kulakan', 'bought',
        'masukkan', 'input', 'inputkan', 'tambah', 'tambahkan',
    ];

    /** Kata sambung yang memisahkan item. */
    private const CONNECTORS = ['dan', 'lalu', 'terus', 'sama', 'ama', 'serta', 'serta', 'juga'];

    /** Kata pengisi yang dibuang dari nama barang. */
    private const FILLERS = ['harga', 'per', 'rp', 'rupiah', '@', 'x', 'sebanyak', 'sekitar', 'aja', 'saja', 'nya'];

    /** Preposisi menuju supplier. */
    private const PREPOSITIONS = ['ke', 'dari', 'di', 'pada', 'untuk', 'toko', 'warung', 'supplier', 'pedagang', 'kepada'];

    /**
     * @param  array<int, array{id: int, nama: string}>  $suppliers  supplier aktif
     * @return array{supplier_id: int, items: array<int, array{nama: string, jumlah: float, satuan: ?string, harga_satuan: int}>}|null
     */
    public function parse(string $text, array $suppliers): ?array
    {
        $tokens = $this->tokenize($text);

        if ($tokens === []) {
            return null;
        }

        // Cari supplier (nama terpanjang yang cocok) & buang token-nya.
        [$supplierId, $tokens] = $this->extractSupplier($tokens, $suppliers);

        if ($supplierId === null) {
            return null;
        }

        // Buang kata kerja & preposisi (pertahankan pemisah koma).
        $tokens = array_values(array_filter($tokens, function (string $token): bool {
            if ($token === ',') {
                return true;
            }

            $w = $this->wordsOnly($token);

            return $w !== ''
                && ! in_array($w, self::VERBS, true)
                && ! in_array($w, self::PREPOSITIONS, true);
        }));

        $segments = $this->splitSegments($tokens);

        $items = [];

        foreach ($segments as $segment) {
            $item = $this->parseItem($segment);

            if ($item === null) {
                // Ada bagian yang tak bisa diparsing -> jangan menebak.
                return null;
            }

            $items[] = $item;
        }

        if ($items === []) {
            return null;
        }

        return [
            'supplier_id' => $supplierId,
            'items' => $items,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));

        // Pisahkan tanda baca pemisah menjadi token tersendiri.
        $text = preg_replace('/[,;:\n\r]+/u', ' , ', $text) ?? $text;

        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : array_values($parts);
    }

    /**
     * @param  array<int, string>  $tokens
     * @param  array<int, array{id: int, nama: string}>  $suppliers
     * @return array{0: int|null, 1: array<int, string>}
     */
    private function extractSupplier(array $tokens, array $suppliers): array
    {
        $words = array_map(fn (string $t): string => $this->wordsOnly($t), $tokens);

        $best = null;
        $bestLen = 0;

        foreach ($suppliers as $supplier) {
            $nameWords = preg_split('/\s+/u', $this->wordsOnly($supplier['nama']), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($nameWords === []) {
                continue;
            }

            $len = count($nameWords);

            if ($len < $bestLen) {
                continue;
            }

            for ($i = 0; $i + $len <= count($words); $i++) {
                if (array_slice($words, $i, $len) === $nameWords) {
                    $best = $supplier['id'];
                    $bestLen = $len;
                    // Tandai token supplier agar dibuang.
                    for ($j = $i; $j < $i + $len; $j++) {
                        $tokens[$j] = '';
                    }
                    break;
                }
            }
        }

        return [$best, array_values(array_filter($tokens, fn (string $t): bool => $t !== ''))];
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, array<int, string>>
     */
    private function splitSegments(array $tokens): array
    {
        $segments = [];
        $current = [];

        foreach ($tokens as $token) {
            $w = $this->wordsOnly($token);

            if ($token === ',' || in_array($w, self::CONNECTORS, true)) {
                if ($current !== []) {
                    $segments[] = $current;
                    $current = [];
                }

                continue;
            }

            $current[] = $token;
        }

        if ($current !== []) {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array{nama: string, jumlah: float, satuan: ?string, harga_satuan: int}|null
     */
    private function parseItem(array $tokens): ?array
    {
        $numbers = [];
        $satuan = null;
        $nameTokens = [];

        foreach ($tokens as $token) {
            $value = $this->parseNumber($token);

            if ($value !== null) {
                $numbers[] = $value;

                continue;
            }

            $w = $this->wordsOnly($token);

            if ($w === '') {
                continue;
            }

            if (in_array($w, self::FILLERS, true)) {
                continue;
            }

            // Satuan dikenal (sinonim) -> ambil yang pertama.
            if ($satuan === null && isset(BarangSatuan::SINONYMS[$w])) {
                $satuan = BarangSatuan::SINONYMS[$w];

                continue;
            }

            if ($this->hasLetter($token)) {
                $nameTokens[] = $token;
            }
        }

        $nama = trim(implode(' ', $nameTokens));

        if ($nama === '' || $numbers === []) {
            return null;
        }

        // Harga = angka terakhir; jumlah = angka pertama bila ada >= 2 angka.
        $harga = (int) end($numbers);
        $jumlah = count($numbers) >= 2 ? (float) $numbers[0] : 1.0;

        if ($harga <= 0 || $jumlah <= 0) {
            return null;
        }

        // Tanpa satuan -> strip "-" (per aturan aplikasi).
        return [
            'nama' => $nama,
            'jumlah' => $jumlah,
            'satuan' => $satuan ?? BarangSatuan::NO_UNIT,
            'harga_satuan' => $harga,
        ];
    }

    /**
     * Ubah token menjadi angka. Mengembalikan null bila bukan angka.
     * Mendukung "25000", "5rb", "25k", "0,5", "25.000", "1,5jt".
     */
    private function parseNumber(string $token): ?float
    {
        $token = trim($token, " \t./@");

        if ($token === '' || ! preg_match('/^\d/u', $token)) {
            return null;
        }

        $suffix = 1.0;

        if (preg_match('/^(\d+(?:[.,]\d+)?)(rb|ribu|k|jt|juta)$/u', $token, $m)) {
            $token = $m[1];
            $suffix = match ($m[2]) {
                'rb', 'ribu', 'k' => 1000.0,
                'jt', 'juta' => 1000000.0,
                default => 1.0,
            };
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/u', $token)) {
            // "25.000" = dua puluh lima ribu (pemisah ribuan).
            return (float) str_replace('.', '', $token);
        } elseif (! preg_match('/^\d+(?:[.,]\d+)?$/u', $token)) {
            return null;
        }

        return (float) str_replace(',', '.', $token) * $suffix;
    }

    private function wordsOnly(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    private function hasLetter(string $s): bool
    {
        return preg_match('/[a-z]/u', mb_strtolower($s)) === 1;
    }
}
