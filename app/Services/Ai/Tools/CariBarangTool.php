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
            .'Jika hasil lebih dari satu, tanyakan ke user barang yang mana.';
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

        $items = $query->orderBy('nama_barang')->limit($limit)->get()
            ->map(fn (Barang $barang): array => [
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
            ])
            ->all();

        return $this->limitPayload([
            'jumlah' => count($items),
            'barang' => $items,
        ]);
    }
}
