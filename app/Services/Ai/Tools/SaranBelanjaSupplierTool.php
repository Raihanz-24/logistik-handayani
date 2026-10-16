<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiSupplierBarangStatsService;

/**
 * Menyarankan barang yang BIASA diorder dari satu supplier, berdasarkan histori
 * transaksi belanja (afinitas supplier<->barang). READ-ONLY.
 *
 * Contoh: "biasanya dari Bu Elly order apa?" → daftar tempe, cabai, dsb.
 */
class SaranBelanjaSupplierTool extends AiTool
{
    public function name(): string
    {
        return 'saran_belanja_supplier';
    }

    public function description(): string
    {
        return 'Menampilkan barang yang BIASA diorder dari satu supplier, berdasarkan histori '
            .'transaksi belanja (termasuk harga & satuan terakhir). '
            .'Pakai ini saat pengguna menyebut nama supplier dan minta "seperti biasa" / '
            .'"biasanya order apa", atau saat ingin mempercepat pembuatan draft belanja. '
            .'Hasilnya adalah SARAN — tetap boleh menambah barang lain bila diminta.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'supplier_id' => ['type' => 'integer', 'description' => 'ID supplier (dari cari_supplier).'],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maksimum saran (1-30). Default 15.',
                    'minimum' => 1,
                    'maximum' => 30,
                ],
            ],
            'required' => ['supplier_id'],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $supplierId = (int) ($arguments['supplier_id'] ?? 0);
        $limit = (int) ($arguments['limit'] ?? config('ai.affinity.suggestion_limit', 15));

        if ($supplierId < 1 || ! Supplier::query()->whereKey($supplierId)->exists()) {
            throw new ToolException('Supplier tidak ditemukan.');
        }

        $items = app(AiSupplierBarangStatsService::class)->suggestions($supplierId, $limit);

        // Tanpa saran yang bermakna, katakan jelas agar model tidak mengarang.
        if ($items === [] || collect($items)->every(fn (array $item): bool => (int) $item['frekuensi'] < 2)) {
            return [
                'supplier_id' => $supplierId,
                'nama_supplier' => Supplier::query()->whereKey($supplierId)->value('nama_supplier'),
                'jumlah' => count($items),
                'barang' => $items,
                'catatan' => 'Riwayat masih sedikit; belum ada pola kuat. Tetap boleh menyusun draft manual.',
            ];
        }

        return $this->limitPayload([
            'supplier_id' => $supplierId,
            'nama_supplier' => Supplier::query()->whereKey($supplierId)->value('nama_supplier'),
            'jumlah' => count($items),
            'barang' => $items,
        ]);
    }
}
