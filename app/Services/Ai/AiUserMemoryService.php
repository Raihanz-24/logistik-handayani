<?php

namespace App\Services\Ai;

use App\Models\AiUserAlias;
use App\Models\Barang;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Memori jangka-panjang (pribadi per pengguna) untuk Asisten AI.
 *
 * Menyimpan pemetaan frasa yang diucapkan pengguna -> barang yang dipilih,
 * sehingga Asisten makin mengerti pola ucapan user dari waktu ke waktu
 * (mis. user selalu menyebut "wortel" untuk barang tertentu).
 *
 * Aman bila tabel belum dimigrasi (fallback senyap).
 */
class AiUserMemoryService
{
    private function available(): bool
    {
        return Schema::hasTable('ai_user_aliases');
    }

    /**
     * Pelajari dari pesan user + barang yang akhirnya dipilih pada draft belanja.
     *
     * Hanya dipelajari bila draft punya SATU barang (agar pemetaan tidak salah
     * pasang). Kata kunci hanya dicatat bila memang cocok (substring) dengan
     * nama/alias barang, sehingga tidak menambah "noise".
     *
     * @param  array<int, int>  $barangIds
     */
    public function learnFromBelanja(User $user, string $userMessage, array $barangIds): void
    {
        if (! $this->available() || $userMessage === '') {
            return;
        }

        $barangIds = array_values(array_unique(array_filter(array_map('intval', $barangIds))));

        if (count($barangIds) !== 1) {
            return;
        }

        $barang = Barang::query()->find($barangIds[0]);

        if (! $barang) {
            return;
        }

        $haystack = mb_strtolower($barang->nama_barang.' '.implode(' ', $barang->aiAliases()));

        foreach ($this->candidateKeywords($userMessage) as $keyword) {
            if (! str_contains($haystack, $keyword)) {
                continue;
            }

            $this->remember($user, $keyword, $barang);
        }
    }

    /** Simpan/perbarui pemetaan keyword -> barang untuk user. */
    public function remember(User $user, string $keyword, Barang $barang): void
    {
        if (! $this->available()) {
            return;
        }

        $key = AiUserAlias::normalizeKeyword($keyword);

        if ($key === '') {
            return;
        }

        $existing = AiUserAlias::query()
            ->where('user_id', $user->getKey())
            ->where('keyword', $key)
            ->first();

        if ($existing) {
            // Bila pilihan berubah, ikuti yang terbaru + naikkan bobot.
            $existing->update([
                'barang_id' => $barang->getKey(),
                'barang_nama' => $barang->nama_barang,
                'hits' => $existing->hits + 1,
            ]);

            return;
        }

        AiUserAlias::query()->create([
            'user_id' => $user->getKey(),
            'barang_id' => $barang->getKey(),
            'barang_nama' => $barang->nama_barang,
            'keyword' => $key,
            'hits' => 1,
        ]);
    }

    /**
     * ID barang yang dipetakan oleh user untuk frasa tertentu (bila ada).
     *
     * @return array<int, int>
     */
    public function barangIdsFor(User $user, string $keyword): array
    {
        if (! $this->available()) {
            return [];
        }

        $key = AiUserAlias::normalizeKeyword($keyword);

        if ($key === '') {
            return [];
        }

        return AiUserAlias::query()
            ->where('user_id', $user->getKey())
            ->where('keyword', $key)
            ->whereNotNull('barang_id')
            ->pluck('barang_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Blok preferensi pribadi untuk disuntik ke prompt (kosong bila belum ada).
     */
    public function preferencesBlock(User $user): string
    {
        if (! $this->available()) {
            return '';
        }

        $aliases = AiUserAlias::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('barang_id')
            ->orderByDesc('hits')
            ->orderByDesc('updated_at')
            ->limit(12)
            ->get();

        if ($aliases->isEmpty()) {
            return '';
        }

        $lines = $aliases->map(fn (AiUserAlias $alias): string => '- "'.$alias->keyword.'" → '
            .($alias->barang_nama ?? ('barang #'.$alias->barang_id))
            .' (barang_id='.$alias->barang_id.')')->implode("\n");

        return <<<PROMPT
PREFERENSI PENGGUNA (dipelajari dari percakapan sebelumnya — utamakan bila relevan):
{$lines}
Bila pengguna menyebut frasa di atas, anggap mengacu ke barang tersebut (kecuali pengguna menyatakan lain).
PROMPT;
    }

    /**
     * Kata kunci kandidat dari pesan user: token alfabet panjang >= 3 yang bukan
     * angka/satuan/kata umum.
     *
     * @return array<int, string>
     */
    private function candidateKeywords(string $userMessage): array
    {
        $stopWords = [
            'beli', 'belanja', 'catat', 'tambah', 'tambahkan', 'dan', 'untuk', 'dari', 'toko',
            'dengan', 'harga', 'harganya', 'total', 'semua', 'semuanya', 'per', 'satu', 'dua',
            'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'juga',
            'itu', 'ini', 'yang', 'ke', 'di', 'rp', 'rupiah', 'ribu', 'juta', 'serta', 'ama',
            'sama', 'barang', 'barangnya', 'yang', 'dong', 'ya', 'tolong', 'mohon', 'bisa',
        ];

        $unitWords = array_keys(\App\Support\BarangSatuan::SINONYMS);
        $unitWords = array_merge($unitWords, ['satuan', 'unit', 'buah', 'biji', 'ekor']);

        $tokens = preg_split('/[^a-zA-Z]+/u', mb_strtolower($userMessage)) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            function (string $token) use ($stopWords, $unitWords): bool {
                if (mb_strlen($token) < 3) {
                    return false;
                }

                if (in_array($token, $stopWords, true) || in_array($token, $unitWords, true)) {
                    return false;
                }

                // Hanya kata yang muncul utuh — bukan potongan kata-angka.
                return ! AiTextNormalizer::isNumberWord($token);
            },
        )));
    }
}
