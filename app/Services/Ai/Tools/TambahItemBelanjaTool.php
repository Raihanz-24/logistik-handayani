<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiSupplierBarangStatsService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Menambah/memperbarui item pada DRAFT transaksi belanja yang sedang berjalan.
 *
 * Berbeda dengan catat_transaksi_belanja (yang menimpa seluruh draft), tool ini
 * MENGGABUNG: item dengan barang_id sama akan diperbarui, item baru ditambahkan.
 * Ini mencegah AI "lupa" item sebelumnya saat pengguna menambah barang.
 */
class TambahItemBelanjaTool extends AiTool
{
    public function name(): string
    {
        return 'tambah_item_belanja';
    }

    public function description(): string
    {
        return 'Menambah/memperbarui satu atau beberapa item pada draft transaksi belanja yang SEDANG berjalan '
            .'pada percakapan ini. Pakai ini saat pengguna menambah barang atau mengoreksi harga/jumlah barang '
            .'yang sudah ada di draft, sehingga item lama tidak hilang. Bila belum ada draft belanja, gunakan '
            .'catat_transaksi_belanja lebih dulu. Tiap baris: barang_id, jumlah, harga_satuan (boleh 0), satuan.';
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'description' => 'Item yang ingin ditambah/diperbarui pada draft berjalan.',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'barang_id' => ['type' => 'integer'],
                            'jumlah' => ['type' => 'number', 'description' => 'Jumlah/banyaknya.'],
                            'harga_satuan' => ['type' => 'integer', 'description' => 'Harga per satuan (Rp). Boleh 0.'],
                            'satuan' => ['type' => 'string', 'description' => 'Satuan. Untuk barang pasar pilih salah satu: gr/kg/biji/buah/ekor/ikt/ppn/btr/bks. Bila pengguna menyebut harga TANPA satuan, isi "-" (strip) dan jumlah=1.'],
                            'keterangan' => ['type' => 'string'],
                        ],
                        'required' => ['barang_id', 'jumlah'],
                    ],
                ],
                'ikuti_urutan_pengguna' => [
                    'type' => 'boolean',
                    'description' => 'Set true HANYA bila pengguna secara eksplisit menentukan urutan. Default false: draft diurutkan otomatis mengikuti pola urutan supplier (item yang tidak dibeli dilompati).',
                ],
            ],
            'required' => ['items'],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        if (! $conversation) {
            throw new ToolException('Tidak ada percakapan aktif untuk menambah item belanja.');
        }

        $draft = AiPendingAction::query()
            ->where('ai_conversation_id', $conversation->getKey())
            ->where('type', AiPendingAction::TYPE_PENGELUARAN_BELANJA)
            ->where('status', AiPendingAction::STATUS_PENDING)
            ->orderByDesc('id')
            ->first();

        if (! $draft) {
            throw new ToolException('Belum ada draft belanja. Panggil catat_transaksi_belanja lebih dulu.');
        }

        $incoming = is_array($arguments['items'] ?? null) ? $arguments['items'] : [];

        try {
            Validator::make(['items' => $incoming], [
                'items' => ['required', 'array', 'min:1', 'max:100'],
                'items.*.barang_id' => ['required', 'integer', 'exists:barangs,id'],
                'items.*.jumlah' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
                'items.*.harga_satuan' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
                'items.*.satuan' => ['nullable', 'string', 'max:50'],
                'items.*.keterangan' => ['nullable', 'string', 'max:500'],
            ], [
                'items.*.jumlah.gt' => 'Jumlah barang harus lebih dari nol.',
            ])->validate();
        } catch (ValidationException $exception) {
            throw new ToolException('Item tambahan tidak valid: '.implode(' ', $exception->validator->errors()->all()));
        }

        $payload = $draft->payload ?? [];
        $existing = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        // Index baris lama berdasarkan barang_id agar bisa merge.
        $merged = collect($existing)->keyBy(fn (array $item): int => (int) ($item['barang_id'] ?? 0))->all();

        foreach ($incoming as $item) {
            $barangId = (int) ($item['barang_id'] ?? 0);

            $merged[$barangId] = [
                'barang_id' => $barangId,
                'jumlah' => (float) ($item['jumlah'] ?? 0),
                'harga_satuan' => (int) ($item['harga_satuan'] ?? 0),
                'satuan' => filled($item['satuan'] ?? null) ? trim((string) $item['satuan']) : null,
                'keterangan' => filled($item['keterangan'] ?? null) ? trim((string) $item['keterangan']) : null,
            ];
        }

        $payload['items'] = array_values($merged);

        // Urutkan mengikuti pola urutan supplier, kecuali pengguna menentukan urutan.
        $followUserOrder = (bool) ($arguments['ikuti_urutan_pengguna'] ?? false);

        if (! $followUserOrder && count($payload['items']) > 1) {
            $payload['items'] = app(AiSupplierBarangStatsService::class)
                ->orderItemsByPattern((int) ($payload['supplier_id'] ?? 0), $payload['items']);
        }

        $summary = $this->summary($payload);

        $draft->update([
            'payload' => $payload,
            'summary' => $summary,
        ]);

        return [
            'pending_action_id' => (int) $draft->getKey(),
            'ringkasan' => $summary,
            'total_item' => count($payload['items']),
            'instruksi' => 'Draft diperbarui. Minta pengguna menekan tombol "Catat Transaksi Belanja" pada kartu konfirmasi.',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function summary(array $payload): string
    {
        $supplier = Supplier::query()
            ->whereKey((int) ($payload['supplier_id'] ?? 0))
            ->value('nama_supplier') ?? ('#'.($payload['supplier_id'] ?? 0));

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

        $names = Barang::query()
            ->whereKey(collect($items)->pluck('barang_id')->all())
            ->pluck('nama_barang', 'id');

        $lines = collect($items)->map(function (array $item) use ($names): string {
            $nama = $names[(int) ($item['barang_id'] ?? 0)] ?? ('barang #'.($item['barang_id'] ?? 0));
            $unit = filled($item['satuan'] ?? null) ? ' '.$item['satuan'] : '';
            $harga = (int) ($item['harga_satuan'] ?? 0) > 0
                ? ' @ Rp'.number_format((int) $item['harga_satuan'], 0, ',', '.')
                : '';

            return $nama.' × '.$this->formatNumber((float) ($item['jumlah'] ?? 0)).$unit.$harga;
        })->implode('; ');

        return "Transaksi belanja ke {$supplier}: {$lines}";
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }
}
