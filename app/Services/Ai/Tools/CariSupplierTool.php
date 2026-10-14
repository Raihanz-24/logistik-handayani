<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\Supplier;
use App\Models\User;

/**
 * Mencari supplier aktif.
 * READ-ONLY.
 */
class CariSupplierTool extends AiTool
{
    public function name(): string
    {
        return 'cari_supplier';
    }

    public function description(): string
    {
        return 'Mencari supplier. Gunakan untuk mendapatkan supplier_id. '
            .'Supplier WAJIB dipilih untuk mutasi masuk dan untuk transaksi belanja.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kata_kunci' => [
                    'type' => 'string',
                    'description' => 'Sebagian nama supplier. Kosongkan untuk melihat semua supplier aktif.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 25,
                    'description' => 'Maksimum hasil. Default 10.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $keyword = trim((string) ($arguments['kata_kunci'] ?? ''));
        $limit = max(1, min((int) ($arguments['limit'] ?? 10), 25));

        $query = Supplier::query()->aktif()->select(['id', 'nama_supplier', 'kontak_person']);

        if ($keyword !== '') {
            $query->where('nama_supplier', 'like', '%'.$keyword.'%');
        }

        $items = $query->orderBy('nama_supplier')->limit($limit)->get()
            ->map(fn (Supplier $supplier): array => [
                'supplier_id' => (int) $supplier->getKey(),
                'nama_supplier' => (string) $supplier->nama_supplier,
                'kontak_person' => (string) ($supplier->kontak_person ?? ''),
            ])
            ->all();

        return $this->limitPayload([
            'jumlah' => count($items),
            'supplier' => $items,
        ]);
    }
}
