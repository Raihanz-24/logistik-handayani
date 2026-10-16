<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\PengeluaranBelanja;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Membaca RIWAYAT transaksi belanja (semua item) untuk satu supplier pada
 * rentang/tanggal tertentu, atau transaksi terakhir.
 *
 * READ-ONLY. Inilah sumber utama agar Asisten bisa menjawab "orderan ke Bu Elly
 * tanggal sekian" secara lengkap, dan menjadi patokan keputusan saat perintah
 * ambigu (memakai transaksi nyata, bukan tebakan).
 */
class RiwayatTransaksiBelanjaTool extends AiTool
{
    public function name(): string
    {
        return 'riwayat_transaksi_belanja';
    }

    public function description(): string
    {
        return 'Menampilkan RIWAYAT transaksi belanja beserta SEMUA item di dalamnya, '
            .'untuk satu supplier pada tanggal/rentang tertentu (atau transaksi terakhir bila tanggal tidak diisi). '
            .'Pakai ini saat pengguna bertanya "orderan ke supplier X tanggal Y", "belanja apa saja kemarin", '
            .'atau minta dibandingkan dengan pola/langganan. '
            .'Hasil memuat tiap item: nama, jumlah, satuan, harga satuan, subtotal, plus total transaksi. '
            .'Gunakan data ini sebagai patokan nyata sebelum mengambil keputusan, jangan mengarang.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => [
                    'type' => 'integer',
                    'description' => 'Opsional. ID supplier (dari cari_supplier). Bila kosong, semua supplier.',
                ],
                'tanggal' => [
                    'type' => 'string',
                    'description' => 'Tanggal spesifik (format YYYY-MM-DD). Bila diisi, hanya transaksi pada tanggal itu.',
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
                    'description' => 'Maksimum transaksi yang dikembalikan (1-25). Default 5.',
                    'minimum' => 1,
                    'maximum' => 25,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $supplierId = (int) ($arguments['supplier_id'] ?? 0);
        $limit = max(1, min((int) ($arguments['limit'] ?? 5), 25));

        $dari = $this->parseDate($arguments['tanggal_dari'] ?? null);
        $sampai = $this->parseDate($arguments['tanggal_sampai'] ?? null);
        $tanggal = $this->parseDate($arguments['tanggal'] ?? null);

        if ($tanggal) {
            $dari = $sampai = $tanggal;
        }

        $query = PengeluaranBelanja::query()
            ->with(['supplier:id,nama_supplier', 'kalkulatorBelanja:id,tanggal,judul', 'items.barang:id,nama_barang'])
            ->orderByDesc('id');

        if ($supplierId > 0) {
            $query->where('supplier_id', $supplierId);
        }

        if ($dari) {
            $query->whereHas('kalkulatorBelanja', fn ($q) => $q->whereDate('tanggal', '>=', $dari));
        }

        if ($sampai) {
            $query->whereHas('kalkulatorBelanja', fn ($q) => $q->whereDate('tanggal', '<=', $sampai));
        }

        $expenses = $query->limit($limit)->get();

        if ($expenses->isEmpty()) {
            return [
                'jumlah_transaksi' => 0,
                'transaksi' => [],
                'catatan' => 'Tidak ada transaksi belanja yang cocok dengan filter tersebut.',
            ];
        }

        $transactions = $expenses->map(function (PengeluaranBelanja $expense): array {
            $items = $expense->items->map(fn ($item): array => [
                'barang_id' => (int) $item->barang_id,
                'nama_barang' => (string) ($item->nama_barang_snapshot ?: ($item->barang?->nama_barang ?? 'Barang terhapus')),
                'jumlah' => (float) $item->jumlah,
                'satuan' => trim((string) $item->satuan_snapshot) !== '' ? (string) $item->satuan_snapshot : '-',
                'harga_satuan' => (int) $item->harga_satuan,
                'subtotal' => (int) $item->subtotal,
            ])->all();

            return [
                'pengeluaran_id' => (int) $expense->getKey(),
                'tanggal' => $expense->kalkulatorBelanja?->tanggal?->toDateString(),
                'supplier' => (string) ($expense->supplier?->nama_supplier ?? $expense->nama_supplier_snapshot),
                'keterangan' => $expense->keterangan,
                'jumlah_item' => count($items),
                'total' => (int) $expense->nominal,
                'items' => $items,
            ];
        })->values()->all();

        return $this->limitPayload([
            'jumlah_transaksi' => count($transactions),
            'transaksi' => $transactions,
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
