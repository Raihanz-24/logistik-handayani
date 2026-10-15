<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Barang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\AiUserMemoryService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Menyiapkan DRAFT transaksi belanja (satu nota toko ke satu supplier).
 *
 * TIDAK menulis ke kalkulator belanja. Tool hanya memvalidasi lalu menyimpan
 * aksi pending. Saat user menekan tombol konfirmasi, barulah
 * BelanjaTransactionService::save() dijalankan pada sesi belanja milik user.
 */
class CatatTransaksiBelanjaTool extends AiTool
{
    public function name(): string
    {
        return 'catat_transaksi_belanja';
    }

    public function description(): string
    {
        return 'Menyiapkan draft pencatatan transaksi belanja ke satu supplier (satu nota toko). '
            .'Draft TIDAK langsung tersimpan — pengguna harus menekan tombol konfirmasi. '
            .'Butuh supplier_id (cari_supplier) dan daftar barang (barang_id dari cari_barang). '
            .'Setiap baris: barang_id, jumlah, dan harga_satuan (boleh 0 bila belum diketahui). '
            .'Bila SUDAH ada draft belanja berjalan dan pengguna hanya menambah/mengoreksi item, '
            .'gunakan tambah_item_belanja agar item lama tidak hilang.';
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
                'supplier_id' => ['type' => 'integer', 'description' => 'ID supplier aktif.'],
                'keterangan' => ['type' => 'string', 'description' => 'Catatan singkat transaksi (opsional).'],
                'items' => [
                    'type' => 'array',
                    'description' => 'Daftar barang yang dibeli.',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'barang_id' => ['type' => 'integer'],
                            'jumlah' => ['type' => 'number', 'description' => 'Jumlah/banyaknya.'],
                            'harga_satuan' => ['type' => 'integer', 'description' => 'Harga per satuan (Rp). Boleh 0.'],
                            'satuan' => ['type' => 'string', 'description' => 'Satuan. Untuk barang kategori pasar pilih salah satu: gr/kg/biji/buah/ekor/ikt/ppn/btr/bks. Bila pengguna menyebut harga TANPA satuan, isi "-" (strip) dan jumlah=1 (artinya 1 barang seharga nominal itu).'],
                            'keterangan' => ['type' => 'string'],
                        ],
                        'required' => ['barang_id', 'jumlah'],
                    ],
                ],
            ],
            'required' => ['supplier_id', 'items'],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $items = is_array($arguments['items'] ?? null) ? $arguments['items'] : [];

        $data = [
            'supplier_id' => (int) ($arguments['supplier_id'] ?? 0),
            'keterangan' => filled($arguments['keterangan'] ?? null) ? trim((string) $arguments['keterangan']) : null,
            'items' => $items,
            'nota_paths' => [],
        ];

        try {
            Validator::make($data, [
                'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
                'keterangan' => ['nullable', 'string', 'max:255'],
                'items' => ['required', 'array', 'min:1', 'max:100'],
                'items.*.barang_id' => ['required', 'integer', 'distinct', 'exists:barangs,id'],
                'items.*.jumlah' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
                'items.*.harga_satuan' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
                'items.*.satuan' => ['nullable', 'string', 'max:50'],
                'items.*.keterangan' => ['nullable', 'string', 'max:500'],
            ], [
                'items.required' => 'Minimal satu barang wajib diisi.',
                'items.*.barang_id.distinct' => 'Barang yang sama tidak boleh dicatat dua kali dalam satu transaksi.',
                'items.*.jumlah.gt' => 'Jumlah barang harus lebih dari nol.',
            ])->validate();
        } catch (ValidationException $exception) {
            throw new ToolException('Draft transaksi tidak valid: '.implode(' ', $exception->validator->errors()->all()));
        }

        if (! Supplier::query()->aktif()->whereKey($data['supplier_id'])->exists()) {
            throw new ToolException('Supplier tidak ditemukan atau tidak aktif.');
        }

        $payload = [
            'supplier_id' => $data['supplier_id'],
            'keterangan' => $data['keterangan'],
            'items' => collect($items)->values()->map(fn (array $item): array => [
                'barang_id' => (int) ($item['barang_id'] ?? 0),
                'jumlah' => (float) ($item['jumlah'] ?? 0),
                'harga_satuan' => (int) ($item['harga_satuan'] ?? 0),
                'satuan' => filled($item['satuan'] ?? null) ? trim((string) $item['satuan']) : null,
                'keterangan' => filled($item['keterangan'] ?? null) ? trim((string) $item['keterangan']) : null,
            ])->all(),
        ];

        $summary = $this->summary($payload);

        // Draft belanja lama pada percakapan ini digantikan draft terbaru agar
        // tidak muncul dua kartu konfirmasi (mis. saat user menambah item).
        $this->supersedePendingActions($conversation, AiPendingAction::TYPE_PENGELUARAN_BELANJA);

        $action = AiPendingAction::query()->create([
            'ai_conversation_id' => $conversation?->getKey(),
            'user_id' => $user->getKey(),
            'type' => AiPendingAction::TYPE_PENGELUARAN_BELANJA,
            'payload' => $payload,
            'status' => AiPendingAction::STATUS_PENDING,
            'summary' => $summary,
        ]);

        // Pelajari pola ucapan user (memori pribadi) bila draft akhirnya hanya
        // memuat satu barang, agar Asisten makin mengerti sebutan pengguna.
        $this->learnFromConversation($user, $conversation, $payload);

        return [
            'pending_action_id' => (int) $action->getKey(),
            'ringkasan' => $summary,
            'instruksi' => 'Minta pengguna menekan tombol "Catat Transaksi Belanja" pada kartu konfirmasi.',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function summary(array $payload): string
    {
        $supplier = Supplier::query()->whereKey($payload['supplier_id'])->value('nama_supplier') ?? ('#'.$payload['supplier_id']);

        $names = Barang::query()
            ->whereKey(collect($payload['items'])->pluck('barang_id')->all())
            ->pluck('nama_barang', 'id');

        $lines = collect($payload['items'])->map(function (array $item) use ($names): string {
            $nama = $names[$item['barang_id']] ?? ('barang #'.$item['barang_id']);
            $unit = $item['satuan'] ? ' '.$item['satuan'] : '';
            $harga = $item['harga_satuan'] > 0 ? ' @ Rp'.number_format($item['harga_satuan'], 0, ',', '.') : '';

            return $nama.' × '.$this->formatNumber($item['jumlah']).$unit.$harga;
        })->implode('; ');

        return "Transaksi belanja ke {$supplier}: {$lines}";
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', '.'), '0'), ',');
    }

    /**
     * Pelajari frasa user terakhir -> barang pada draft (memori pribadi).
     *
     * @param  array<string, mixed>  $payload
     */
    private function learnFromConversation(User $user, ?AiConversation $conversation, array $payload): void
    {
        if (! $conversation) {
            return;
        }

        $lastUserMessage = $conversation->messages()
            ->where('role', \App\Models\AiMessage::ROLE_USER)
            ->orderByDesc('id')
            ->value('content');

        if (! is_string($lastUserMessage) || trim($lastUserMessage) === '') {
            return;
        }

        $barangIds = collect($payload['items'] ?? [])
            ->pluck('barang_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        app(AiUserMemoryService::class)->learnFromBelanja($user, $lastUserMessage, $barangIds);
    }
}
