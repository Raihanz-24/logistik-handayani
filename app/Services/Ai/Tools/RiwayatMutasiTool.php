<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\Mutasi;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Membaca RIWAYAT mutasi barang (masuk / keluar / perubahan kondisi).
 *
 * READ-ONLY. Memberi Asisten dasar nyata untuk menjawab "mutasi apa saja
 * kemarin", "riwayat keluar galon", "mutasi masuk dari supplier X", dsb.,
 * serta menjadi patokan keputusan saat perintah ambigu.
 */
class RiwayatMutasiTool extends AiTool
{
    public function name(): string
    {
        return 'riwayat_mutasi';
    }

    public function description(): string
    {
        return 'Menampilkan RIWAYAT mutasi barang (masuk/keluar/perubahan kondisi) beserta '
            .'status persetujuannya. Bisa disaring per barang, jenis mutasi, gudang, status, '
            .'atau tanggal/rentang tanggal. Pakai ini saat pengguna bertanya "mutasi apa saja '
            .'kemarin", "riwayat keluar galon", "mutasi masuk dari supplier X", atau minta '
            .'dibandingkan dengan data nyata. Jangan mengarang data mutasi.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'barang_id' => [
                    'type' => 'integer',
                    'description' => 'Opsional. Batasi ke satu barang (dari cari_barang).',
                ],
                'jenis_mutasi' => [
                    'type' => 'string',
                    'enum' => ['masuk', 'keluar', 'perubahan_kondisi'],
                    'description' => 'Opsional. Batasi ke satu jenis mutasi.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Opsional. Status persetujuan, mis. pending / approved / cancelled.',
                ],
                'lokasi_id' => [
                    'type' => 'integer',
                    'description' => 'Opsional. Batasi ke satu gudang (lokasi_id).',
                ],
                'tanggal' => [
                    'type' => 'string',
                    'description' => 'Tanggal spesifik (YYYY-MM-DD). Bila diisi, hanya mutasi tanggal itu.',
                ],
                'tanggal_dari' => [
                    'type' => 'string',
                    'description' => 'Awal rentang tanggal (YYYY-MM-DD).',
                ],
                'tanggal_sampai' => [
                    'type' => 'string',
                    'description' => 'Akhir rentang tanggal (YYYY-MM-DD).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maksimum mutasi yang dikembalikan (1-50). Default 15.',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $limit = max(1, min((int) ($arguments['limit'] ?? 15), 50));

        $dari = $this->parseDate($arguments['tanggal_dari'] ?? null);
        $sampai = $this->parseDate($arguments['tanggal_sampai'] ?? null);
        $tanggal = $this->parseDate($arguments['tanggal'] ?? null);

        if ($tanggal) {
            $dari = $sampai = $tanggal;
        }

        $query = Mutasi::query()
            ->with([
                'barang:id,nama_barang,kode_barang',
                'lokasi:id,nama_lokasi',
                'lokasiTujuan:id,nama_lokasi',
                'supplier:id,nama_supplier',
            ])
            ->orderByDesc('tanggal')
            ->orderByDesc('id');

        if ((int) ($arguments['barang_id'] ?? 0) > 0) {
            $query->where('barang_id', (int) $arguments['barang_id']);
        }

        if (filled($arguments['jenis_mutasi'] ?? null)) {
            $query->where('jenis_mutasi', (string) $arguments['jenis_mutasi']);
        }

        if (filled($arguments['status'] ?? null)) {
            $query->where('status', (string) $arguments['status']);
        }

        if ((int) ($arguments['lokasi_id'] ?? 0) > 0) {
            $query->where('lokasi_id', (int) $arguments['lokasi_id']);
        }

        if ($dari) {
            $query->whereDate('tanggal', '>=', $dari);
        }

        if ($sampai) {
            $query->whereDate('tanggal', '<=', $sampai);
        }

        $rows = $query->limit($limit)->get();

        if ($rows->isEmpty()) {
            return [
                'jumlah_mutasi' => 0,
                'mutasi' => [],
                'catatan' => 'Tidak ada mutasi yang cocok dengan filter tersebut.',
            ];
        }

        $jenisLabels = Mutasi::jenisOptions();

        $mutations = $rows->map(function (Mutasi $mutasi) use ($jenisLabels): array {
            return [
                'mutasi_id' => (int) $mutasi->getKey(),
                'tanggal' => $mutasi->tanggal?->toDateString(),
                'jenis_mutasi' => (string) $mutasi->jenis_mutasi,
                'jenis_label' => $jenisLabels[$mutasi->jenis_mutasi] ?? $mutasi->jenis_mutasi,
                'status' => (string) $mutasi->status,
                'barang_id' => (int) $mutasi->barang_id,
                'nama_barang' => (string) ($mutasi->barang?->nama_barang ?? 'Barang terhapus'),
                'jumlah' => (int) $mutasi->jumlah,
                'kondisi_asal' => $mutasi->kondisi_asal,
                'kondisi_tujuan' => $mutasi->kondisi_tujuan,
                'gudang' => (string) ($mutasi->lokasi?->nama_lokasi ?? '-'),
                'gudang_tujuan' => $mutasi->lokasiTujuan?->nama_lokasi,
                'supplier' => $mutasi->supplier?->nama_supplier,
                'keterangan' => $mutasi->keterangan,
                'no_ref' => $mutasi->no_ref,
            ];
        })->values()->all();

        return $this->limitPayload([
            'jumlah_mutasi' => count($mutations),
            'mutasi' => $mutations,
        ]);
    }

    private function parseDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
