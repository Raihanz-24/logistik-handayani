<?php

namespace App\Services;

use App\Models\FotoBarangItemBelanjaLink;
use App\Models\FotoBarangSession;
use App\Models\PengeluaranBelanjaItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FotoBarangPurchaseItemLinkService
{
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
