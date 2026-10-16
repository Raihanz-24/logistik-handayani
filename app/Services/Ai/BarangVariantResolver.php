<?php

namespace App\Services\Ai;

use App\Models\Barang;
use Illuminate\Support\Collection;

/**
 * Mendeteksi & memisahkan "varian" pada nama barang (mis. "Tempe" vs
 * "Tempe Karyawan").
 *
 * Penanda varian berasal dari dua sumber:
 * 1. Konfigurasi: config('ai.variant_markers') (mis. "karyawan").
 * 2. Otomatis: kata yang membedakan dua nama barang serupa. Bila ada "Tempe"
 *    dan "Tempe X", maka "X" dianggap penanda varian dari "Tempe".
 *
 * Aturan pemakaian (diterapkan di tool & prompt):
 * - User TIDAK menyebut penanda  -> jangan sentuh barang ber-penanda.
 * - User MENYEBUT penanda         -> jangan sentuh barang tanpa penanda.
 * Tujuannya mempersempit kandidat, meningkatkan akurasi, dan hemat token.
 */
class BarangVariantResolver
{
    /** Penanda dari konfigurasi, huruf kecil. @return array<int, string> */
    public function configuredMarkers(): array
    {
        $markers = collect(config('ai.variant_markers', []))
            ->map(fn (mixed $marker): string => mb_strtolower(trim((string) $marker)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $markers;
    }

    /**
     * Markers untuk himpunan nama barang tertentu (config + auto-deteksi).
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public function markersFor(array $names): array
    {
        $markers = $this->configuredMarkers();

        foreach ($this->autoMarkers($names) as $marker) {
            if (! in_array($marker, $markers, true)) {
                $markers[] = $marker;
            }
        }

        return $markers;
    }

    /**
     * Pecah satu nama menjadi [subjek, penanda] bila dia adalah varian yang
     * dikenali. Mengembalikan null bila nama berdiri sendiri (bukan varian).
     *
     * @param  array<int, string>  $markers
     * @return array{subject: string, marker: string}|null
     */
    public function resolve(string $name, array $markers): ?array
    {
        $lower = mb_strtolower(trim($name));

        // Penanda (config) lebih diutamakan karena bisa berupa frasa.
        foreach ($markers as $marker) {
            if ($marker === '') {
                continue;
            }

            if (str_contains($lower, $marker)) {
                $subject = trim(preg_replace('/\b'.preg_quote($marker, '/').'\b/u', ' ', $lower) ?? $lower);

                return ['subject' => $subject, 'marker' => $marker];
            }
        }

        // Fallback: kata terakhir yang berdiri sendiri sebagai kandidat penanda.
        if (preg_match('/^(.+?)\s+([a-z]{3,})$/u', $lower, $matches)) {
            return ['subject' => trim($matches[1]), 'marker' => trim($matches[2])];
        }

        return null;
    }

    /**
     * Apakah nama mengandung salah satu penanda yang diberikan.
     *
     * @param  array<int, string>  $markers
     */
    public function hasMarker(string $name, array $markers): bool
    {
        $lower = mb_strtolower(trim($name));

        foreach ($markers as $marker) {
            if ($marker !== '' && str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Penanda yang disebut user di dalam pesannya (dari daftar penanda yang
     * diberikan).
     *
     * @param  array<int, string>  $markers
     */
    public function mentionedMarker(string $userMessage, array $markers): ?string
    {
        $needle = mb_strtolower(trim($userMessage));

        if ($needle === '') {
            return null;
        }

        foreach ($markers as $marker) {
            if (mb_strlen($marker) < 3) {
                continue;
            }

            if (preg_match('/\b'.preg_quote($marker, '/').'\b/u', $needle)) {
                return $marker;
            }
        }

        return null;
    }

    /**
     * Apakah salah satu nama mengandung salah satu penanda yang diberikan.
     *
     * @param  array<int, string>  $names
     * @param  array<int, string>  $markers
     */
    public function hasAnyMarker(array $names, array $markers): bool
    {
        foreach ($names as $name) {
            if ($this->hasMarker($name, $markers)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Penanda otomatis (publik untuk awareness): kata yang membedakan dua nama
     * barang yang berbagi subjek (mis. "Tempe" & "Tempe Karyawan" -> "karyawan").
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public function detectedAutoMarkers(array $names): array
    {
        return $this->autoMarkers($names);
    }

    /**
     * Penanda otomatis: kata yang membedakan dua nama barang yang berbagi
     * subjek (mis. "Tempe" & "Tempe Karyawan" -> "karyawan").
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    private function autoMarkers(array $names): array
    {
        $names = collect($names)
            ->map(fn (string $name): string => mb_strtolower(trim($name)))
            ->filter()
            ->unique()
            ->values();

        $set = $names->flip();
        $markers = [];

        foreach ($names as $name) {
            $parts = preg_split('/\s+/u', $name) ?: [];

            if (count($parts) < 2) {
                continue;
            }

            // Kandidat penanda = kata TERAKHIR; subjek = sisanya.
            $marker = (string) end($parts);

            if (mb_strlen($marker) < 3) {
                continue;
            }

            $subject = implode(' ', array_slice($parts, 0, -1));

            // Hanya dianggap penanda bila subjeknya juga ada sebagai barang.
            if ($set->has($subject)) {
                $markers[$marker] = true;
            }
        }

        return array_keys($markers);
    }
}
