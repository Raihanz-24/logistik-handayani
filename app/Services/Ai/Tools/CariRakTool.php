<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\Lokasi;
use App\Models\PosisiRak;
use App\Models\User;

/**
 * Menampilkan daftar posisi rak (yang aktif) pada sebuah gudang.
 * READ-ONLY.
 *
 * Dibutuhkan karena mutasi masuk / keluar ke gudang yang memakai rak
 * mensyaratkan posisi_rak_tujuan_id. Tanpa tool ini AI hanya tahu
 * "gudang memakai rak" tetapi tidak tahu ID rak mana yang harus dipilih.
 */
class CariRakTool extends AiTool
{
    public function name(): string
    {
        return 'cari_rak';
    }

    public function description(): string
    {
        return 'Mendaftar posisi rak AKTIF pada sebuah gudang (beserta posisi_rak_id). '
            .'WAJIB dipakai bila cari_lokasi menunjukkan gudang memakai rak (pakai_rak=true) '
            .'dan Anda akan menyiapkan mutasi masuk/keluar ke gudang tersebut, supaya '
            .'posisi_rak_tujuan_id diisi dengan benar. Butuh lokasi_id (dari cari_lokasi).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lokasi_id' => [
                    'type' => 'integer',
                    'description' => 'ID gudang (dari cari_lokasi).',
                ],
                'kata_kunci' => [
                    'type' => 'string',
                    'description' => 'Sebagian kode rak. Kosongkan untuk melihat semua rak aktif.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'description' => 'Maksimum hasil. Default 40.',
                ],
            ],
            'required' => ['lokasi_id'],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $lokasiId = (int) ($arguments['lokasi_id'] ?? 0);

        if ($lokasiId <= 0) {
            throw new ToolException('lokasi_id wajib berupa angka positif.');
        }

        $lokasi = Lokasi::query()->select(['id', 'nama_lokasi', 'kode_lokasi', 'menggunakan_rak'])->find($lokasiId);

        if (! $lokasi) {
            return $this->limitPayload([
                'ditemukan' => false,
                'pesan' => 'Lokasi tidak ditemukan. Coba cari ulang dengan cari_lokasi.',
            ]);
        }

        if (! (bool) $lokasi->menggunakan_rak) {
            return $this->limitPayload([
                'ditemukan' => true,
                'lokasi_id' => (int) $lokasi->getKey(),
                'nama_lokasi' => (string) $lokasi->nama_lokasi,
                'pakai_rak' => false,
                'pesan' => 'Gudang ini tidak memakai rak, jadi posisi_rak_tujuan_id tidak diperlukan.',
                'rak' => [],
            ]);
        }

        $keyword = trim((string) ($arguments['kata_kunci'] ?? ''));
        $limit = max(1, min((int) ($arguments['limit'] ?? 40), 100));

        $query = PosisiRak::query()->aktif()
            ->where('lokasi_id', $lokasi->getKey())
            ->with('rakGudang:id,nomor_rak');

        if ($keyword !== '') {
            $query->where('kode', 'like', '%'.$keyword.'%');
        }

        $items = $query->orderBy('rak_gudang_id')->orderBy('nomor_tingkat')->orderBy('kode')
            ->limit($limit)
            ->get()
            ->map(fn (PosisiRak $position): array => [
                'posisi_rak_id' => (int) $position->getKey(),
                'kode' => (string) $position->kode,
                'nomor_tingkat' => (int) $position->nomor_tingkat,
                'nomor_rak' => $position->rakGudang?->nomor_rak,
            ])
            ->all();

        return $this->limitPayload([
            'ditemukan' => true,
            'lokasi_id' => (int) $lokasi->getKey(),
            'nama_lokasi' => (string) $lokasi->nama_lokasi,
            'pakai_rak' => true,
            'jumlah_rak_aktif' => count($items),
            'rak' => $items,
        ]);
    }
}
