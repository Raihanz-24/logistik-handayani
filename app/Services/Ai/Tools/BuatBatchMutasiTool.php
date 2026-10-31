<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Ai\MutasiBatchService;

/**
 * Menyiapkan BANYAK draft mutasi sekaligus dalam SATU kartu konfirmasi.
 *
 * TIDAK mengubah stok. Semua item divalidasi lebih dulu (termasuk stok
 * kumulatif), lalu disimpan sebagai satu aksi pending bertipe `mutasi_batch`.
 * Saat pengguna menekan satu tombol konfirmasi, seluruh mutasi dibuat dengan
 * status 'pending' (menunggu persetujuan) dalam satu transaksi — bila ada satu
 * yang gagal, semuanya dibatalkan agar tidak setengah jadi.
 */
class BuatBatchMutasiTool extends AiTool
{
    public function name(): string
    {
        return 'buat_batch_mutasi';
    }

    public function description(): string
    {
        return 'Menyiapkan BANYAK draft mutasi sekaligus dalam SATU kartu konfirmasi. '
            .'Gunakan ini (BUKAN buat_draft_mutasi berulang kali) ketika pengguna meminta '
            .'lebih dari satu mutasi dalam satu perintah — beda barang, beda jenis, atau beda '
            .'tanggal. Setiap item punya field yang sama seperti buat_draft_mutasi: jenis_mutasi, '
            .'barang_id, jumlah, lokasi_id, opsional lokasi_tujuan_id/supplier_id/kondisi_asal/'
            .'posisi_rak_tujuan_id/tanggal/keterangan. Draft TIDAK langsung mengubah stok; '
            .'pengguna mengonfirmasi sekali untuk membuat semua mutasi (status pending).';
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
                    'description' => 'Daftar mutasi yang akan dibuat sekaligus.',
                    'minItems' => 1,
                    'maxItems' => 50,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'jenis_mutasi' => [
                                'type' => 'string',
                                'enum' => ['masuk', 'keluar', 'perubahan_kondisi'],
                            ],
                            'barang_id' => ['type' => 'integer'],
                            'jumlah' => ['type' => 'integer', 'minimum' => 1],
                            'lokasi_id' => [
                                'type' => 'integer',
                                'description' => 'Gudang asal (keluar/perubahan_kondisi) atau gudang tujuan (masuk).',
                            ],
                            'lokasi_tujuan_id' => ['type' => 'integer', 'description' => 'Wajib untuk jenis keluar.'],
                            'supplier_id' => ['type' => 'integer', 'description' => 'Wajib untuk jenis masuk.'],
                            'kondisi_asal' => [
                                'type' => 'string',
                                'enum' => ['baik', 'rusak'],
                                'description' => 'Wajib untuk keluar/perubahan_kondisi.',
                            ],
                            'posisi_rak_tujuan_id' => ['type' => 'integer'],
                            'tanggal' => ['type' => 'string', 'description' => 'Format YYYY-MM-DD. Default hari ini.'],
                            'keterangan' => ['type' => 'string'],
                        ],
                        'required' => ['jenis_mutasi', 'barang_id', 'jumlah', 'lokasi_id'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $rawItems = is_array($arguments['items'] ?? null) ? $arguments['items'] : [];

        if ($rawItems === []) {
            throw new ToolException('Minimal satu mutasi harus diisi.');
        }

        $payloads = array_map(fn (array $item): array => $this->normalize($item), $rawItems);

        try {
            app(MutasiBatchService::class)->validate($payloads, (int) $user->getKey());
        } catch (\Throwable $exception) {
            throw new ToolException('Draft mutasi batch tidak valid: '.$exception->getMessage());
        }

        $summary = $this->summary($payloads);

        // Draft mutasi tunggal/batch lama pada percakapan ini digantikan batch
        // terbaru agar kartu konfirmasi tidak menumpuk.
        $this->supersedePendingActions($conversation, AiPendingAction::TYPE_MUTASI_DRAFT);
        $this->supersedePendingActions($conversation, AiPendingAction::TYPE_MUTASI_BATCH);

        $action = AiPendingAction::query()->create([
            'ai_conversation_id' => $conversation?->getKey(),
            'user_id' => $user->getKey(),
            'type' => AiPendingAction::TYPE_MUTASI_BATCH,
            'payload' => ['items' => $payloads],
            'status' => AiPendingAction::STATUS_PENDING,
            'summary' => $summary,
        ]);

        return [
            'pending_action_id' => (int) $action->getKey(),
            'jumlah_mutasi' => count($payloads),
            'ringkasan' => $summary,
            'instruksi' => 'Minta pengguna menekan tombol "Buat Semua Draft" pada kartu konfirmasi. '
                .'Satu konfirmasi membuat semua mutasi sekaligus (status pending).',
        ];
    }

    /** @param array<string, mixed> $item */
    private function normalize(array $item): array
    {
        return [
            'jenis_mutasi' => (string) ($item['jenis_mutasi'] ?? ''),
            'barang_id' => (int) ($item['barang_id'] ?? 0),
            'jumlah' => (int) ($item['jumlah'] ?? 0),
            'lokasi_id' => (int) ($item['lokasi_id'] ?? 0),
            'lokasi_tujuan_id' => (int) ($item['lokasi_tujuan_id'] ?? 0) ?: null,
            'supplier_id' => (int) ($item['supplier_id'] ?? 0) ?: null,
            'kondisi_asal' => $item['kondisi_asal'] ?? null,
            'posisi_rak_tujuan_id' => (int) ($item['posisi_rak_tujuan_id'] ?? 0) ?: null,
            'tanggal' => filled($item['tanggal'] ?? null) ? (string) $item['tanggal'] : now()->toDateString(),
            'keterangan' => filled($item['keterangan'] ?? null) ? trim((string) $item['keterangan']) : null,
        ];
    }

    /**
     * Ringkasan bernomor (satu baris per mutasi) untuk kartu konfirmasi.
     *
     * @param  array<int, array<string, mixed>>  $payloads
     */
    private function summary(array $payloads): string
    {
        $barangNames = Barang::query()
            ->whereKey(collect($payloads)->pluck('barang_id')->filter()->all())
            ->pluck('nama_barang', 'id');

        $lokasiNames = Lokasi::query()
            ->whereKey(collect($payloads)
                ->flatMap(fn (array $p): array => [$p['lokasi_id'], $p['lokasi_tujuan_id']])
                ->filter()
                ->all())
            ->pluck('nama_lokasi', 'id');

        $supplierNames = Supplier::query()
            ->whereKey(collect($payloads)->pluck('supplier_id')->filter()->all())
            ->pluck('nama_supplier', 'id');

        $jenisLabels = Mutasi::jenisOptions();

        $lines = collect($payloads)->values()->map(function (array $p, int $index) use ($barangNames, $lokasiNames, $supplierNames, $jenisLabels): string {
            $barang = $barangNames[$p['barang_id']] ?? ('barang #'.$p['barang_id']);
            $gudang = $lokasiNames[$p['lokasi_id']] ?? ('gudang #'.$p['lokasi_id']);
            $jenis = $jenisLabels[$p['jenis_mutasi']] ?? $p['jenis_mutasi'];

            $line = ($index + 1).'. '.$jenis.': '.$p['jumlah'].' '.$barang.' @ '.$gudang;

            if ($p['jenis_mutasi'] === 'keluar' && $p['lokasi_tujuan_id']) {
                $tujuan = $lokasiNames[$p['lokasi_tujuan_id']] ?? ('#'.$p['lokasi_tujuan_id']);
                $line .= ' → '.$tujuan;
            }

            if ($p['jenis_mutasi'] === 'masuk' && $p['supplier_id']) {
                $supplier = $supplierNames[$p['supplier_id']] ?? ('#'.$p['supplier_id']);
                $line .= ' (supplier: '.$supplier.')';
            }

            if ($p['tanggal']) {
                $line .= ' • '.$p['tanggal'];
            }

            return $line;
        })->implode("\n");

        return "Draft ".count($payloads)." mutasi sekaligus:\n".$lines;
    }
}
