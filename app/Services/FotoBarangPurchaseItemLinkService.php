<?php

namespace App\Services;

use App\Models\Barang;
use App\Models\FotoBarangItem;
use App\Models\FotoBarangItemBelanjaLink;
use App\Models\FotoBarangSession;
use App\Models\PengeluaranBelanja;
use App\Models\PengeluaranBelanjaItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FotoBarangPurchaseItemLinkService
{
    /**
     * Memberi label sebuah foto yang baru diambil dan, bila perlu, membuat baris
     * barang berharga Rp0 pada satu transaksi yang terhubung ke folder.
     *
     * Tidak pernah mengubah foto atau transaksi apabila folder tidak tepat
     * terhubung ke satu transaksi yang dapat diakses pengguna.
     */
    public function autoAssignCapturedPhoto(
        FotoBarangSession $session,
        FotoBarangItem $photo,
        int $barangId,
    ): ?PengeluaranBelanjaItem {
        if ($barangId < 1) {
            return null;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Sesi pengguna tidak valid. Silakan masuk kembali.');
        }

        $result = DB::transaction(function () use ($session, $photo, $barangId, $user): ?array {
            $lockedSession = FotoBarangSession::query()
                ->lockForUpdate()
                ->findOrFail($session->getKey());
            $lockedPhoto = $lockedSession->items()
                ->whereKey($photo->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedPhoto) {
                throw new RuntimeException('Foto yang baru diambil tidak ditemukan dalam sesi ini.');
            }

            $expenses = $lockedSession->pengeluaranBelanjas()
                ->whereHas('kalkulatorBelanja', fn ($query) => $query->visibleTo($user))
                ->lockForUpdate()
                ->get();

            // Folder biasa atau folder dengan lebih dari satu transaksi tidak
            // boleh ditebakkan ke salah satu transaksi.
            if ($expenses->count() !== 1) {
                return null;
            }

            /** @var PengeluaranBelanja $expense */
            $expense = $expenses->first();
            $barang = Barang::query()->findOrFail($barangId);
            $purchaseItem = $expense->items()
                ->where('barang_id', $barangId)
                ->lockForUpdate()
                ->first();
            $created = false;

            if (! $purchaseItem) {
                $purchaseItem = $expense->items()->create([
                    'barang_id' => (int) $barang->getKey(),
                    'kode_barang_snapshot' => (string) $barang->kode_barang,
                    'nama_barang_snapshot' => (string) $barang->nama_barang,
                    'satuan_snapshot' => (string) ($barang->satuan ?: '-'),
                    'jumlah' => '1.000',
                    'harga_satuan' => 0,
                    'subtotal' => 0,
                    'keterangan' => 'Ditambahkan otomatis dari Foto Maps.',
                    'urutan' => ((int) $expense->items()->max('urutan')) + 1,
                ]);
                $created = true;
            }

            FotoBarangItemBelanjaLink::query()
                ->where('foto_barang_item_id', $lockedPhoto->getKey())
                ->delete();
            FotoBarangItemBelanjaLink::query()->create([
                'foto_barang_item_id' => (int) $lockedPhoto->getKey(),
                'pengeluaran_belanja_item_id' => (int) $purchaseItem->getKey(),
            ]);

            return ['item' => $purchaseItem, 'created' => $created];
        });

        if (! $result) {
            return null;
        }

        /** @var PengeluaranBelanjaItem $purchaseItem */
        $purchaseItem = $result['item'];
        app(AuditLogger::class)->activity(
            'foto_barang_purchase_item_auto_assign',
            'Memberi label otomatis '.$purchaseItem->namaBarang()." pada foto sesi: {$session->judul}",
            $user,
            [
                'session_id' => $session->getKey(),
                'photo_id' => $photo->getKey(),
                'pengeluaran_belanja_item_id' => $purchaseItem->getKey(),
                'barang_id' => $purchaseItem->barang_id,
                'item_created_from_photo' => $result['created'],
            ],
        );

        return $purchaseItem;
    }

    /**
     * @param  array<int, int|string>  $photoIds
     * @return array{photo_ids: array<int, int>, purchase_item_id: ?int, label: ?string}
     */
    public function assign(
        FotoBarangSession $session,
        array $photoIds,
        ?int $purchaseItemId,
    ): array {
        $ids = collect($photoIds)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty() || $ids->count() > 100) {
            throw new RuntimeException('Pilih antara 1 sampai 100 foto.');
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Sesi pengguna tidak valid. Silakan masuk kembali.');
        }

        $purchaseItem = DB::transaction(function () use ($session, $ids, $purchaseItemId, $user): ?PengeluaranBelanjaItem {
            $lockedSession = FotoBarangSession::query()
                ->lockForUpdate()
                ->findOrFail($session->getKey());
            $photos = $lockedSession->items()
                ->whereKey($ids->all())
                ->lockForUpdate()
                ->get(['id']);

            if ($photos->count() !== $ids->count()) {
                throw new RuntimeException('Salah satu foto tidak ditemukan dalam folder ini.');
            }

            $item = null;

            if ($purchaseItemId !== null) {
                $item = PengeluaranBelanjaItem::query()
                    ->with('pengeluaranBelanja.supplier')
                    ->whereKey($purchaseItemId)
                    ->whereHas('pengeluaranBelanja.fotoBarangSessions', fn ($query) => $query
                        ->whereKey($lockedSession->getKey()))
                    ->whereHas('pengeluaranBelanja.kalkulatorBelanja', fn ($query) => $query
                        ->visibleTo($user))
                    ->first();

                if (! $item) {
                    throw new RuntimeException('Barang tidak berasal dari transaksi yang terhubung ke folder ini.');
                }
            }

            FotoBarangItemBelanjaLink::query()
                ->whereIn('foto_barang_item_id', $ids->all())
                ->delete();

            if ($item) {
                $now = now();
                FotoBarangItemBelanjaLink::query()->insert(
                    $ids->map(fn (int $photoId): array => [
                        'foto_barang_item_id' => $photoId,
                        'pengeluaran_belanja_item_id' => (int) $item->getKey(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all(),
                );
            }

            return $item;
        });

        $label = $purchaseItem?->namaBarang();
        app(AuditLogger::class)->activity(
            $purchaseItem ? 'foto_barang_purchase_item_assign' : 'foto_barang_purchase_item_unassign',
            ($purchaseItem ? 'Menetapkan barang '.$label.' ke ' : 'Melepas label barang dari ')
                .$ids->count()." foto pada sesi: {$session->judul}",
            auth()->user(),
            [
                'session_id' => $session->getKey(),
                'photo_ids' => $ids->all(),
                'photo_count' => $ids->count(),
                'pengeluaran_belanja_item_id' => $purchaseItem?->getKey(),
                'barang_id' => $purchaseItem?->barang_id,
            ],
        );

        return [
            'photo_ids' => $ids->all(),
            'purchase_item_id' => $purchaseItem?->getKey(),
            'label' => $label,
        ];
    }

    /**
     * Menghubungkan seluruh foto yang belum memiliki label dengan item transaksi secara satu banding satu.
     * Urutan selalu diambil dari urutan foto dan urutan item yang tersimpan di server.
     *
     * @return array{photo_ids: array<int, int>, purchase_item_ids: array<int, int>, assignments: array<int, array{photo_id: int, purchase_item_id: int}>}
     */
    public function assignSequential(FotoBarangSession $session, int $expenseId): array
    {
        if ($expenseId < 1) {
            throw new RuntimeException('Pilih transaksi belanja terlebih dahulu.');
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Sesi pengguna tidak valid. Silakan masuk kembali.');
        }

        $result = DB::transaction(function () use ($session, $expenseId, $user): array {
            $lockedSession = FotoBarangSession::query()
                ->lockForUpdate()
                ->findOrFail($session->getKey());
            $expense = $lockedSession->pengeluaranBelanjas()
                ->whereKey($expenseId)
                ->whereHas('kalkulatorBelanja', fn ($query) => $query->visibleTo($user))
                ->lockForUpdate()
                ->first();

            if (! $expense) {
                throw new RuntimeException('Transaksi tidak terhubung ke folder ini atau tidak dapat diakses.');
            }

            $photos = $lockedSession->items()
                ->doesntHave('purchaseLink')
                ->reorder()
                ->orderBy('urutan')
                ->lockForUpdate()
                ->get(['id', 'urutan']);
            $items = $expense->items()
                ->reorder()
                ->orderBy('urutan')
                ->lockForUpdate()
                ->get(['id', 'urutan']);

            if ($photos->isEmpty()) {
                throw new RuntimeException('Tidak ada foto tanpa label di folder ini.');
            }

            if ($items->isEmpty()) {
                throw new RuntimeException('Transaksi ini belum memiliki detail barang.');
            }

            if ($photos->count() !== $items->count()) {
                throw new RuntimeException(sprintf(
                    'Jumlah harus sama: %d foto tanpa label dan %d barang transaksi. Tidak ada label yang diubah.',
                    $photos->count(),
                    $items->count(),
                ));
            }

            $photoIds = $photos->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            $existingLink = FotoBarangItemBelanjaLink::query()
                ->whereIn('foto_barang_item_id', $photoIds)
                ->lockForUpdate()
                ->exists();

            if ($existingLink) {
                throw new RuntimeException('Sebagian foto baru saja diberi label. Muat ulang folder lalu coba kembali.');
            }

            $now = now();
            $assignments = $photos->values()->map(function ($photo, int $index) use ($items): array {
                return [
                    'photo_id' => (int) $photo->getKey(),
                    'purchase_item_id' => (int) $items[$index]->getKey(),
                ];
            })->all();

            FotoBarangItemBelanjaLink::query()->insert(
                collect($assignments)->map(fn (array $assignment): array => [
                    'foto_barang_item_id' => $assignment['photo_id'],
                    'pengeluaran_belanja_item_id' => $assignment['purchase_item_id'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );

            return [
                'expense_id' => (int) $expense->getKey(),
                'assignments' => $assignments,
            ];
        });

        $photoIds = collect($result['assignments'])->pluck('photo_id')->all();
        $purchaseItemIds = collect($result['assignments'])->pluck('purchase_item_id')->all();

        app(AuditLogger::class)->activity(
            'foto_barang_purchase_item_assign_sequential',
            'Menetapkan label barang berurutan ke '.count($photoIds)." foto pada sesi: {$session->judul}",
            auth()->user(),
            [
                'session_id' => $session->getKey(),
                'pengeluaran_belanja_id' => $result['expense_id'],
                'photo_ids' => $photoIds,
                'pengeluaran_belanja_item_ids' => $purchaseItemIds,
                'photo_count' => count($photoIds),
                'mode' => 'sequential',
            ],
        );

        return [
            'photo_ids' => $photoIds,
            'purchase_item_ids' => $purchaseItemIds,
            'assignments' => $result['assignments'],
        ];
    }

    /** @param array<int, int|string> $sessionIds */
    public function pruneSessions(array $sessionIds): int
    {
        $ids = collect($sessionIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique();
        $deleted = 0;

        foreach ($ids as $sessionId) {
            $allowedExpenseIds = DB::table('foto_barang_session_pengeluaran_belanja')
                ->where('foto_barang_session_id', $sessionId)
                ->pluck('pengeluaran_belanja_id');
            $query = FotoBarangItemBelanjaLink::query()
                ->whereHas('photo', fn ($photoQuery) => $photoQuery
                    ->where('foto_barang_session_id', $sessionId));

            if ($allowedExpenseIds->isEmpty()) {
                $deleted += $query->delete();

                continue;
            }

            $deleted += $query
                ->whereHas('purchaseItem', fn ($itemQuery) => $itemQuery
                    ->whereNotIn('pengeluaran_belanja_id', $allowedExpenseIds->all()))
                ->delete();
        }

        return $deleted;
    }
}
