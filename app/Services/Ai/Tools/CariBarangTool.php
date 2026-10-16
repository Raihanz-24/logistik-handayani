<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\AiSearchLog;
use App\Models\Barang;
use App\Models\User;
use App\Services\Ai\AiSupplierBarangStatsService;
use App\Services\Ai\AiUserMemoryService;
use App\Services\Ai\BarangVariantResolver;
use Illuminate\Support\Facades\Schema;

/**
 * Mencari master barang berdasarkan kata kunci nama atau kode.
 * READ-ONLY.
 *
 * Pencocokan deterministik:
 * - Skor nama/alias/kode (cocok persis menang, lalu awalan, lalu mengandung).
 * - Bonus afinitas supplier (barang yang biasa diorder dari supplier tsb).
 * - Bonus memori pribadi pengguna (frasa yang pernah dipetakan).
 * - Aturan varian: penanda terkonfigurasi (mis. "karyawan") dipakai untuk
 *   menyaring kandidat agar tidak salah pilih.
 */
class CariBarangTool extends AiTool
{
    public function name(): string
    {
        return 'cari_barang';
    }

    public function description(): string
    {
        return 'Mencari barang pada master data berdasarkan sebagian nama atau kode barang. '
            .'Gunakan ini untuk mendapatkan barang_id sebelum melakukan mutasi atau mencatat belanja. '
            .'Bila tahu supplier tujuan (mis. belanja ke Bu Elly), kirim supplier_id agar hasil '
            .'diprioritaskan ke barang yang biasa diorder dari supplier itu. '
            .'Hasil sudah diurutkan dari yang paling cocok; field "pilihan_terbaik" diisi bila ada '
            .'satu kandidat yang jelas — pakai itu tanpa bertanya. '
            .'Hanya tanyakan ke user bila beberapa kandidat benar-benar mirip/setara dan tidak ada pilihan terbaik.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kata_kunci' => [
                    'type' => 'string',
                    'description' => 'Sebagian nama atau kode barang. Kosongkan untuk melihat daftar terbaru.',
                ],
                'supplier_id' => [
                    'type' => 'integer',
                    'description' => 'Opsional. ID supplier tujuan; hasil diprioritaskan ke barang yang biasa diorder dari supplier ini.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maksimum hasil (1-25). Default 10.',
                    'minimum' => 1,
                    'maximum' => 25,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $keyword = trim((string) ($arguments['kata_kunci'] ?? ''));
        $supplierId = (int) ($arguments['supplier_id'] ?? 0);
        $limit = (int) ($arguments['limit'] ?? 10);
        $limit = max(1, min($limit, 25));

        // Kolom ai_alias mungkin belum ada (mis. migrasi belum dijalankan). Jangan
        // sampai pencarian gagal total — turunkan ke pencarian nama/kode saja.
        $hasAlias = Schema::hasColumn('barangs', 'ai_alias');

        $query = Barang::query()
            ->select($hasAlias
                ? ['id', 'nama_barang', 'kode_barang', 'satuan', 'ai_alias', 'is_barang_pasar']
                : ['id', 'nama_barang', 'kode_barang', 'satuan', 'is_barang_pasar'])
            ->with('kategoriBarangs:id,nama');

        if ($keyword !== '') {
            $like = '%'.$keyword.'%';

            // Cari lewat nama, kode, DAN alias AI (nama panggilan) bila kolomnya ada.
            $query->where(function ($q) use ($like, $hasAlias): void {
                $q->where('nama_barang', 'like', $like)
                    ->orWhere('kode_barang', 'like', $like);

                if ($hasAlias) {
                    $q->orWhere('ai_alias', 'like', $like);
                }
            });
        }

        // Ambil kandidat lebih banyak dari limit agar bisa diberi skor lalu diurutkan.
        $candidates = $query->orderBy('nama_barang')->limit(max($limit * 5, 60))->get();

        $resolver = app(BarangVariantResolver::class);
        $allNames = $candidates
            ->map(fn (Barang $barang): string => (string) $barang->nama_barang)
            ->all();

        // Hanya penanda TERKONFIGURASI yang menyaring keras (aman). Penanda
        // otomatis hanya untuk awareness: hindari memilih varian ber-penanda
        // saat user tidak menyebutnya (mis. "tempe" jangan jatuh ke "tempe karyawan").
        $configMarkers = $resolver->configuredMarkers();
        $mentionedMarker = $resolver->mentionedMarker($keyword, $configMarkers);
        $autoMarkers = $resolver->detectedAutoMarkers($allNames);

        $hasVariant = ($configMarkers !== [] || $autoMarkers !== [])
            && $resolver->hasAnyMarker($allNames, array_values(array_unique([...$configMarkers, ...$autoMarkers])));

        $affinity = app(AiSupplierBarangStatsService::class);
        $memoryIds = app(AiUserMemoryService::class)->barangIdsFor($user, $keyword);

        // Filter varian harus dijamin tidak mengosongkan hasil: pilih sisi yang
        // diharapkan; bila sisi itu kosong, biarkan hasil apa adanya (fallback).
        $passesVariant = function (string $nama) use ($resolver, $mentionedMarker, $configMarkers, $hasVariant): bool {
            if (! $hasVariant) {
                return true;
            }

            $hasConfigMarker = $resolver->hasMarker($nama, $configMarkers);

            if ($mentionedMarker !== null) {
                // User menyebut penanda -> hanya barang ber-penanda.
                return $hasConfigMarker;
            }

            // User tidak menyebut -> jangan sentuh barang ber-penanda.
            return ! $hasConfigMarker;
        };

        $ranked = $candidates
            ->map(fn (Barang $barang): array => $this->presentBarang($barang, $keyword, $hasAlias, $supplierId, $affinity, $memoryIds))
            ->sortBy([
                fn (array $a, array $b): int => $b['_score'] <=> $a['_score'],
                fn (array $a, array $b): int => strcmp($a['nama_barang'], $b['nama_barang']),
            ])
            ->values();

        $filtered = $ranked->filter(fn (array $item): bool => $passesVariant($item['nama_barang']));

        // Fallback: jangan pernah mengosongkan hasil karena aturan varian.
        if ($filtered->isNotEmpty()) {
            $ranked = $filtered;
        }

        $bestPick = $this->bestPick($ranked->all());

        $items = $ranked
            ->take($limit)
            ->map(function (array $item): array {
                unset($item['_score']);

                return $item;
            })
            ->values()
            ->all();

        $payload = [
            'jumlah' => count($items),
            'pilihan_terbaik' => $bestPick,
            'barang' => $items,
        ];

        if ($mentionedMarker !== null) {
            $payload['penanda_varian_dipakai'] = $mentionedMarker;
        }

        $this->logSearch($user, $keyword, $ranked->all(), $bestPick);

        return $this->limitPayload($payload);
    }

    /**
     * Catat pencarian untuk penilaian kualitas pengenalan barang (halaman
     * "Kecerdasan AI"). Aman: gagal/absen tabel tidak mengganggu pencarian.
     *
     * @param  array<int, array<string, mixed>>  $ranked  sudah terurut menurun
     * @param  array<string, mixed>|null  $bestPick
     */
    private function logSearch(User $user, string $keyword, array $ranked, ?array $bestPick): void
    {
        try {
            if ($keyword === '' || ! Schema::hasTable('ai_search_logs')) {
                return;
            }

            $topScore = (int) ($ranked[0]['_score'] ?? 0);
            $bestId = $bestPick !== null ? (int) ($bestPick['barang_id'] ?? 0) : null;

            AiSearchLog::query()->create([
                'user_id' => $user->getKey(),
                'kata_kunci' => mb_substr($keyword, 0, 191),
                'jumlah_kandidat' => count($ranked),
                'pilihan_terbaik_barang_id' => $bestId ?: null,
                'skor_teratas' => $topScore,
                'ambigu' => $bestId === null,
            ]);
        } catch (\Throwable) {
            // Diamkan: log penilaian tidak boleh menggagalkan pencarian.
        }
    }

    /**
     * Susun payload satu barang + skor internal (dibuang sebelum dikirim).
     *
     * @param  array<int, int>  $memoryIds
     * @return array<string, mixed>
     */
    private function presentBarang(
        Barang $barang,
        string $keyword,
        bool $hasAlias,
        int $supplierId,
        AiSupplierBarangStatsService $affinity,
        array $memoryIds,
    ): array {
        $barangId = (int) $barang->getKey();

        return [
            'barang_id' => $barangId,
            'nama_barang' => (string) $barang->nama_barang,
            'kode_barang' => (string) $barang->kode_barang,
            'satuan' => (string) $barang->satuan,
            'alias' => $hasAlias ? $barang->aiAliases() : [],
            'kategori' => $barang->kategoriBarangs->pluck('nama')->implode(', '),
            'barang_pasar' => $barang->isBarangPasar(),
            'catatan' => $barang->isBarangPasar()
                ? 'Barang pasar: hanya untuk transaksi belanja, tidak untuk mutasi/stok gudang.'
                : null,
            '_score' => $this->scoreBarang($barang, $keyword, $hasAlias, $supplierId, $affinity, $memoryIds, $barangId),
        ];
    }

    /**
     * Skor relevansi: cocok persis menang, lalu awalan, lalu mengandung.
     * Ditambah bonus afinitas supplier & memori pribadi pengguna.
     *
     * @param  array<int, int>  $memoryIds
     */
    private function scoreBarang(
        Barang $barang,
        string $keyword,
        bool $hasAlias,
        int $supplierId,
        AiSupplierBarangStatsService $affinity,
        array $memoryIds,
        int $barangId,
    ): int {
        $score = $keyword === ''
            ? 1
            : $this->nameScore($barang, $keyword, $hasAlias);

        if ($supplierId > 0) {
            $affinityValue = $affinity->affinityScore($supplierId, $barangId);

            if ($affinityValue > 0) {
                $score += (int) config('ai.search.affinity_bonus', 300)
                    + (int) round($affinityValue * 10);
            }
        }

        if (in_array($barangId, $memoryIds, true)) {
            $score += (int) config('ai.search.memory_bonus', 200);
        }

        return $score;
    }

    /** Skor berbasis nama/alias/kode saja. */
    private function nameScore(Barang $barang, string $keyword, bool $hasAlias): int
    {
        $needle = mb_strtolower($keyword);
        $nama = mb_strtolower(trim((string) $barang->nama_barang));
        $kode = mb_strtolower(trim((string) $barang->kode_barang));
        $aliases = $hasAlias
            ? array_map(fn (string $alias): string => mb_strtolower($alias), $barang->aiAliases())
            : [];

        $score = 0;

        if ($nama === $needle) {
            $score = max($score, 1000);
        }

        foreach ($aliases as $alias) {
            if ($alias === $needle) {
                $score = max($score, 950);
            }
        }

        if (str_starts_with($nama, $needle)) {
            $score = max($score, 700);
        }

        foreach ($aliases as $alias) {
            if (str_starts_with($alias, $needle)) {
                $score = max($score, 650);
            }
        }

        if (preg_match('/\b'.preg_quote($needle, '/').'/u', $nama)) {
            $score = max($score, 500);
        }

        foreach ($aliases as $alias) {
            if (preg_match('/\b'.preg_quote($needle, '/').'/u', $alias)) {
                $score = max($score, 450);
            }
        }

        if ($kode === $needle) {
            $score = max($score, 400);
        } elseif (str_contains($kode, $needle)) {
            $score = max($score, 150);
        }

        if ($score === 0 && str_contains($nama, $needle)) {
            $score = 100;
        }

        return $score;
    }

    /**
     * Kandidat terbaik bila ada yang benar-benar unggul (skor tinggi & tak
     * tertandingi). Mengembalikan null bila ambigu (beberapa kandidat setara).
     *
     * @param  array<int, array<string, mixed>>  $items  sudah terurut skor menurun
     * @return array<string, mixed>|null
     */
    private function bestPick(array $items): ?array
    {
        if ($items === []) {
            return null;
        }

        $top = $items[0];
        $topScore = (int) ($top['_score'] ?? 0);

        if ($topScore < 400) {
            return null;
        }

        if (isset($items[1]) && ($topScore - (int) ($items[1]['_score'] ?? 0)) < 100) {
            return null;
        }

        unset($top['_score']);

        return $top;
    }
}
