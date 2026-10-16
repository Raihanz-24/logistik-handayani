<?php

namespace App\Support;

/**
 * Pengurutan barang mengikuti POLA URUTAN yang dipelajari per supplier.
 *
 * Sifat penting (sesuai kebutuhan pengguna):
 * - Hasil selalu berupa SUBSEKUENS dari pola: bila sebagian item tidak dibeli,
 *   item itu cukup "dilompati"; sisanya tetap urut seperti kebiasaan.
 * - Barang yang tidak ada di pola diletakkan di BELAKANG, dengan urutan relatif
 *   tetap seperti masukan semula (agar tidak mengacak item baru).
 * - Fungsi ini murni (pure) sehingga mudah diuji.
 */
class PolaUrutanBarang
{
    /**
     * Urutkan $barangIds mengikuti $polaBarangIds (daftar id terurut).
     *
     * @param  array<int, int|string>  $barangIds  item yang akan diurutkan
     * @param  array<int, int|string>  $polaBarangIds  pola acuan (terurut)
     * @return array<int, int> id terurut (integer)
     */
    public static function urutkan(array $barangIds, array $polaBarangIds): array
    {
        $ids = [];
        foreach ($barangIds as $id) {
            $id = (int) $id;
            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if ($ids === [] || $polaBarangIds === []) {
            return $ids;
        }

        $posisi = [];
        foreach (array_values($polaBarangIds) as $index => $id) {
            $posisi[(int) $id] ??= $index;
        }

        $diPola = [];
        $diLuar = [];

        foreach ($ids as $id) {
            if (array_key_exists($id, $posisi)) {
                $diPola[$id] = $posisi[$id];
            } else {
                $diLuar[] = $id;
            }
        }

        asort($diPola, SORT_NUMERIC);

        return array_merge(array_keys($diPola), $diLuar);
    }

    /**
     * Apakah urutan dua daftar id identik (untuk deteksi "sudah sesuai pola").
     *
     * @param  array<int, int|string>  $a
     * @param  array<int, int|string>  $b
     */
    public static function sama(array $a, array $b): bool
    {
        return array_map('intval', array_values($a)) === array_map('intval', array_values($b));
    }
}
