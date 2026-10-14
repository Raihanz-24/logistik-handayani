<?php

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\AiPendingAction;
use App\Models\Barang;
use App\Models\Lokasi;
use App\Models\Mutasi;
use App\Models\PosisiRak;
use App\Models\Supplier;
use App\Models\User;
use App\Services\MutasiDataService;

/**
 * Menyiapkan DRAFT mutasi (masuk / keluar / perubahan kondisi).
 *
 * TIDAK mengubah stok. Tool hanya memvalidasi lewat MutasiDataService::prepare()
 * lalu menyimpan aksi pending yang harus dikonfirmasi user. Saat user menekan
 * "Buat Draft Mutasi", barulah record Mutasi dibuat dengan status 'pending'
 * (belum di-approve, sesuai alur normal aplikasi).
 */
class BuatDraftMutasiTool extends AiTool
{
    public function name(): string
    {
        return 'buat_draft_mutasi';
    }

    public function description(): string
    {
        return 'Menyiapkan draft mutasi: masuk (pengadaan), keluar (transfer/pemakaian), '
            .'atau perubahan kondisi. Draft TIDAK langsung mengubah stok — pengguna harus '
            .'menekan tombol konfirmasi. Butuh barang_id (cari_barang), lokasi_id (cari_lokasi). '
            .'Untuk jenis "masuk" wajib supplier_id. Untuk "keluar" wajib lokasi_tujuan_id. '
            .'Untuk "keluar"/"perubahan_kondisi" wajib kondisi_asal (baik/rusak). '
            .'Bila gudang tujuan memakai rak (lihat cari_lokasi), WAJIB panggil cari_rak lalu isi '
            .'posisi_rak_tujuan_id dengan posisi_rak_id yang dipilih.';
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
                'jenis_mutasi' => [
                    'type' => 'string',
                    'enum' => ['masuk', 'keluar', 'perubahan_kondisi'],
                ],
                'barang_id' => ['type' => 'integer', 'description' => 'ID barang.'],
                'jumlah' => ['type' => 'integer', 'minimum' => 1],
                'lokasi_id' => [
                    'type' => 'integer',
                    'description' => 'Gudang asal (untuk keluar/perubahan_kondisi) atau gudang tujuan (untuk masuk).',
                ],
                'lokasi_tujuan_id' => [
                    'type' => 'integer',
                    'description' => 'Wajib untuk jenis keluar.',
                ],
                'supplier_id' => [
                    'type' => 'integer',
                    'description' => 'Wajib untuk jenis masuk (supplier aktif).',
                ],
                'kondisi_asal' => [
                    'type' => 'string',
                    'enum' => ['baik', 'rusak'],
                    'description' => 'Wajib untuk keluar/perubahan_kondisi.',
                ],
                'posisi_rak_tujuan_id' => [
                    'type' => 'integer',
                    'description' => 'Jika gudang tujuan menggunakan rak.',
                ],
                'tanggal' => ['type' => 'string', 'description' => 'Format YYYY-MM-DD. Default hari ini.'],
                'keterangan' => ['type' => 'string'],
            ],
            'required' => ['jenis_mutasi', 'barang_id', 'jumlah', 'lokasi_id'],
        ];
    }

    public function execute(array $arguments, User $user, ?AiConversation $conversation = null): array
    {
        $jenis = (string) ($arguments['jenis_mutasi'] ?? '');

        $payload = [
            'jenis_mutasi' => $jenis,
            'barang_id' => (int) ($arguments['barang_id'] ?? 0),
            'jumlah' => (int) ($arguments['jumlah'] ?? 0),
            'lokasi_id' => (int) ($arguments['lokasi_id'] ?? 0),
            'lokasi_tujuan_id' => (int) ($arguments['lokasi_tujuan_id'] ?? 0) ?: null,
            'supplier_id' => (int) ($arguments['supplier_id'] ?? 0) ?: null,
            'kondisi_asal' => $arguments['kondisi_asal'] ?? null,
            'posisi_rak_tujuan_id' => (int) ($arguments['posisi_rak_tujuan_id'] ?? 0) ?: null,
            'tanggal' => $arguments['tanggal'] ?? now()->toDateString(),
            'keterangan' => filled($arguments['keterangan'] ?? null) ? trim((string) $arguments['keterangan']) : null,
        ];

        // Validasi lengkap tanpa menulis apa pun.
        try {
            app(MutasiDataService::class)->prepare([...$payload, 'user_id' => $user->getKey()]);
        } catch (\Throwable $exception) {
            throw new ToolException(
                'Draft mutasi tidak valid: '.$exception->getMessage().$this->rackHint($payload)
            );
        }

        $summary = $this->summary($payload);
        $action = $this->createPendingAction($conversation, $user, $payload, $summary);

        return [
            'pending_action_id' => (int) $action->getKey(),
            'jenis' => $jenis,
            'ringkasan' => $summary,
            'instruksi' => 'Minta pengguna menekan tombol "Buat Draft Mutasi" pada kartu konfirmasi.',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function createPendingAction(?AiConversation $conversation, User $user, array $payload, string $summary): AiPendingAction
    {
        return AiPendingAction::query()->create([
            'ai_conversation_id' => $conversation?->getKey(),
            'user_id' => $user->getKey(),
            'type' => AiPendingAction::TYPE_MUTASI_DRAFT,
            'payload' => $payload,
            'status' => AiPendingAction::STATUS_PENDING,
            'summary' => $summary,
        ]);
    }

    /**
     * Bila error terkait rak, tambahkan daftar rak aktif gudang tujuan agar
     * model bisa langsung memilih dan mengulang dengan posisi_rak_tujuan_id.
     *
     * @param  array<string, mixed>  $payload
     */
    private function rackHint(array $payload): string
    {
        $jenis = $payload['jenis_mutasi'] ?? '';
        $warehouseId = $jenis === 'keluar'
            ? (int) ($payload['lokasi_tujuan_id'] ?? 0)
            : (int) ($payload['lokasi_id'] ?? 0);

        if (! $warehouseId) {
            return '';
        }

        $gudang = Lokasi::query()->find($warehouseId);

        if (! $gudang || ! (bool) $gudang->menggunakan_rak) {
            return '';
        }

        $rak = PosisiRak::query()->aktif()
            ->where('lokasi_id', $warehouseId)
            ->orderBy('kode')
            ->limit(50)
            ->get()
            ->map(fn (PosisiRak $position): string => $position->kode.' (posisi_rak_id='.$position->getKey().')')
            ->all();

        if ($rak === []) {
            return ' Gudang "'.$gudang->nama_lokasi.'" memakai rak tetapi belum ada rak aktif. '
                .'Beritahu pengguna untuk menambah rak aktif lebih dulu.';
        }

        return ' Rak aktif di "'.$gudang->nama_lokasi.'": '.implode(', ', $rak)
            .'. Ulangi pemanggilan dengan posisi_rak_tujuan_id salah satu di atas.';
    }

    /** @param array<string, mixed> $payload */
    private function summary(array $payload): string
    {
        $barang = Barang::query()->whereKey($payload['barang_id'])->value('nama_barang') ?? ('barang #'.$payload['barang_id']);
        $gudang = Lokasi::query()->whereKey($payload['lokasi_id'])->value('nama_lokasi') ?? ('gudang #'.$payload['lokasi_id']);
        $jenis = Mutasi::jenisOptions()[$payload['jenis_mutasi']] ?? $payload['jenis_mutasi'];
        $jumlah = (int) $payload['jumlah'];

        $text = "Mutasi {$jenis}: {$jumlah} {$barang} @ {$gudang}";

        if ($payload['jenis_mutasi'] === 'keluar' && $payload['lokasi_tujuan_id']) {
            $tujuan = Lokasi::query()->whereKey($payload['lokasi_tujuan_id'])->value('nama_lokasi') ?? ('#'.$payload['lokasi_tujuan_id']);
            $text .= " → {$tujuan}";
        }

        if ($payload['jenis_mutasi'] === 'masuk' && $payload['supplier_id']) {
            $supplier = Supplier::query()->whereKey($payload['supplier_id'])->value('nama_supplier') ?? ('#'.$payload['supplier_id']);
            $text .= " (supplier: {$supplier})";
        }

        if ($payload['tanggal']) {
            $text .= ' • '.$payload['tanggal'];
        }

        return $text;
    }
}
