<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\Lokasi;
use App\Models\PosisiRak;
use App\Models\User;

/**
 * Mencari lokasi (gudang / lokasi pemakaian).
 * READ-ONLY.
 */
class CariLokasiTool extends AiTool
{
    public function name(): string
    {
        return 'cari_lokasi';
    }

    public function description(): string
    {
        return 'Mencari lokasi/gudang dan lokasi pemakaian. '
            .'Gunakan untuk mendapatkan lokasi_id (gudang asal/tujuan) sebelum mutasi.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kata_kunci' => [
                    'type' => 'string',
                    'description' => 'Sebagian nama atau kode lokasi. Kosongkan untuk melihat semua.',
                ],
                'jenis' => [
                    'type' => 'string',
                    'enum' => ['gudang', 'lokasi_pemakaian'],
                    'description' => 'Filter jenis lokasi. Opsional.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'Maksimum hasil. Default 20.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $keyword = trim((string) ($arguments['kata_kunci'] ?? ''));
        $jenis = (string) ($arguments['jenis'] ?? '');
        $limit = max(1, min((int) ($arguments['limit'] ?? 20), 50));

        $query = Lokasi::query()->select([
            'id', 'nama_lokasi', 'kode_lokasi', 'jenis_lokasi', 'menggunakan_rak',
        ]);

        if ($keyword !== '') {
            $like = '%'.$keyword.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('nama_lokasi', 'like', $like)
                    ->orWhere('kode_lokasi', 'like', $like);
            });
        }

        if (in_array($jenis, [Lokasi::JENIS_GUDANG, Lokasi::JENIS_PEMAKAIAN], true)) {
            $query->where('jenis_lokasi', $jenis);
        }

        $items = $query->orderBy('nama_lokasi')->limit($limit)->get()
            ->map(fn (Lokasi $lokasi): array => [
                'lokasi_id' => (int) $lokasi->getKey(),
                'nama_lokasi' => (string) $lokasi->nama_lokasi,
                'kode_lokasi' => (string) $lokasi->kode_lokasi,
                'jenis' => (string) $lokasi->jenis_lokasi,
                'pakai_rak' => (bool) $lokasi->menggunakan_rak,
                'jumlah_rak_aktif' => (bool) $lokasi->menggunakan_rak
                    ? PosisiRak::query()->aktif()->where('lokasi_id', $lokasi->getKey())->count()
                    : 0,
            ])
            ->all();

        return $this->limitPayload([
            'jumlah' => count($items),
            'catatan' => 'Bila sebuah gudang punya pakai_rak=true, panggil cari_rak dengan '
                .'lokasi_id gudang itu untuk mendapatkan posisi_rak_id yang wajib diisi saat mutasi.',
            'lokasi' => $items,
        ]);
    }
}
