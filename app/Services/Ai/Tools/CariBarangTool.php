<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\Barang;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Mencari master barang berdasarkan kata kunci nama atau kode.
 * READ-ONLY.
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
            .'Hasil sudah diurutkan dari yang paling cocok; field "pilihan_terbaik" diisi bila ada '
            .'satu kandidat yang jelas (mis. cocok persis nama/alias) — pakai itu tanpa bertanya. '
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
        $candidates = $query->orderBy('nama_barang')->limit(max($limit * 5, 50))->get();

        $ranked = $candidates
            ->map(fn (Barang $barang): array => $this->presentBarang($barang, $keyword, $hasAlias))
            ->sortBy([
                fn (array $a, array $b): int => $b['_score'] <=> $a['_score'],
                fn (array $a, array $b): int => strcmp($a['nama_barang'], $b['nama_barang']),
            ])
            ->values();

        $bestPick = $this->bestPick($ranked->all());

        $items = $ranked
            ->take($limit)
            ->map(function (array $item): array {
                unset($item['_score']);

                return $item;
            })
            ->all();

        return $this->limitPayload([
            'jumlah' => count($items),
            'pilihan_terbaik' => $bestPick,
            'barang' => $items,
        ]);
    }

    /**
     * Susun payload satu barang + skor internal (dibuang sebelum dikirim).
     *
     * @return array<string, mixed>
     */
    private function presentBarang(Barang $barang, string $keyword, bool $hasAlias): array
    {
        return [
            'barang_id' => (int) $barang->getKey(),
            'nama_barang' => (string) $barang->nama_barang,
            'kode_barang' => (string) $barang->kode_barang,
            'satuan' => (string) $barang->satuan,
            'alias' => $hasAlias ? $barang->aiAliases() : [],
            'kategori' => $barang->kategoriBarangs->pluck('nama')->implode(', '),
            'barang_pasar' => $barang->isBarangPasar(),
            'catatan' => $barang->isBarangPasar()
                ? 'Barang pasar: hanya untuk transaksi belanja, tidak untuk mutasi/stok gudang.'
                : null,
            '_score' => $this->scoreBarang($barang, $keyword, $hasAlias),
        ];
    }

    /**
     * Skor relevansi: cocok persis menang, lalu awalan, lalu mengandung.
     * Nama & alias mendapat prioritas di atas kode barang.
     */
    private function scoreBarang(Barang $barang, string $keyword, bool $hasAlias): int
    {
        if ($keyword === '') {
            return 1;
        }

        $needle = mb_strtolower($keyword);
        $nama = mb_strtolower(trim((string) $barang->nama_barang));
        $kode = mb_strtolower(trim((string) $barang->kode_barang));
        $aliases = $hasAlias
            ? array_map(fn (string $alias): string => mb_strtolower($alias), $barang->aiAliases())
            : [];

        $score = 0;

        // Nama / alias cocok persis = paling kuat.
        if ($nama === $needle) {
            $score = max($score, 1000);
        }

        foreach ($aliases as $alias) {
            if ($alias === $needle) {
                $score = max($score, 950);
            }
        }

        // Awalan nama / alias.
        if (str_starts_with($nama, $needle)) {
            $score = max($score, 700);
        }

        foreach ($aliases as $alias) {
            if (str_starts_with($alias, $needle)) {
                $score = max($score, 650);
            }
        }

        // Mengandung kata (word boundary) di nama / alias.
        if (preg_match('/\b'.preg_quote($needle, '/').'/u', $nama)) {
            $score = max($score, 500);
        }

        foreach ($aliases as $alias) {
            if (preg_match('/\b'.preg_quote($needle, '/').'/u', $alias)) {
                $score = max($score, 450);
            }
        }

        // Kode barang (lebih rendah).
        if ($kode === $needle) {
            $score = max($score, 400);
        } elseif (str_contains($kode, $needle)) {
            $score = max($score, 150);
        }

        // Mengandung substring biasa di nama (fallback terakhir).
        if ($score === 0 && str_contains($nama, $needle)) {
            $score = 100;
        }

        return $score;
    }

    /**
     * Kandidat terbaik bila ada yang benar-benar unggul (skor tinggi & tak
     * tertandingi). Mengembalikan null bila ambigu (beberapa kandidat setara),
     * agar model bisa bertanya. Skor internal dibuang dari hasil.
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

        // Skor rendah = hanya cocok samar; jangan paksa pilih.
        if ($topScore < 400) {
            return null;
        }

        // Bila kandidat kedua setara (selisih kecil), anggap ambigu.
        if (isset($items[1]) && ($topScore - (int) ($items[1]['_score'] ?? 0)) < 100) {
            return null;
        }

        unset($top['_score']);

        return $top;
    }
}
