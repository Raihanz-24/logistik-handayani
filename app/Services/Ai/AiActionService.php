<?php

namespace App\Services\Ai;

use App\Models\AiPendingAction;
use App\Models\KalkulatorBelanja;
use App\Models\Mutasi;
use App\Models\PengeluaranBelanja;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BelanjaTransactionService;
use App\Services\MutasiDataService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Menjalankan aksi AI yang sudah dikonfirmasi user.
 *
 * Ini SATU-SATUNYA tempat aksi AI benar-benar menulis data. Semua aksi
 * memakai service aplikasi yang sudah ada:
 * - mutasi  → MutasiDataService::prepare() + Mutasi::create(status: pending)
 * - belanja → BelanjaTransactionService::save()
 */
class AiActionService
{
    /**
     * @return array{type: string, id: int, label: string, url: string|null}
     */
    public function confirm(AiPendingAction $action, User $user): array
    {
        if (! $action->isPending()) {
            throw new RuntimeException('Aksi ini sudah diproses sebelumnya.');
        }

        $this->assertOwnership($action, $user);

        return match ($action->type) {
            AiPendingAction::TYPE_MUTASI_DRAFT => $this->confirmMutasi($action, $user),
            AiPendingAction::TYPE_PENGELUARAN_BELANJA => $this->confirmBelanja($action, $user),
            default => throw new RuntimeException('Jenis aksi tidak dikenal.'),
        };
    }

    public function cancel(AiPendingAction $action, User $user): void
    {
        $this->assertOwnership($action, $user);

        if (! $action->isPending()) {
            return;
        }

        $action->update(['status' => AiPendingAction::STATUS_CANCELLED]);

        app(AuditLogger::class)->activity(
            'ai_action_cancel',
            'Membatalkan aksi Asisten AI: '.($action->summary ?? $action->type),
            $user,
            ['pending_action_id' => $action->getKey(), 'type' => $action->type],
        );
    }

    /** @return array{type: string, id: int, label: string, url: string|null} */
    private function confirmMutasi(AiPendingAction $action, User $user): array
    {
        $payload = $action->payload ?? [];

        $record = DB::transaction(function () use ($payload, $user): Mutasi {
            $prepared = app(MutasiDataService::class)->prepare([...$payload, 'user_id' => $user->getKey()]);

            return Mutasi::query()->create([
                ...$prepared,
                'status' => 'pending',
                'user_id' => $user->getKey(),
                'created_by' => $user->getKey(),
            ]);
        });

        $action->update([
            'status' => AiPendingAction::STATUS_CONFIRMED,
            'result_type' => Mutasi::class,
            'result_id' => $record->getKey(),
            'confirmed_at' => now(),
        ]);

        app(AuditLogger::class)->activity(
            'ai_action_mutasi_draft',
            'Asisten AI membuat draft mutasi: '.($action->summary ?? ''),
            $user,
            [
                'pending_action_id' => $action->getKey(),
                'mutasi_id' => $record->getKey(),
                'jenis_mutasi' => $record->jenis_mutasi,
                'jumlah' => (int) $record->jumlah,
            ],
        );

        return [
            'type' => 'mutasi',
            'id' => (int) $record->getKey(),
            'label' => 'Draft mutasi dibuat dan menunggu persetujuan.',
            'url' => $this->mutasiUrl($record),
        ];
    }

    /** @return array{type: string, id: int, label: string, url: string|null} */
    private function confirmBelanja(AiPendingAction $action, User $user): array
    {
        $payload = $action->payload ?? [];
        $url = null;

        $session = DB::transaction(function () use ($payload, $user, &$url): KalkulatorBelanja {
            $session = KalkulatorBelanja::query()->create([
                'user_id' => $user->getKey(),
                'tanggal' => now()->toDateString(),
                'judul' => 'Transaksi via Asisten AI',
                'uang_awal' => 0,
            ]);

            $expense = app(BelanjaTransactionService::class)->save($session, null, [
                'supplier_id' => $payload['supplier_id'] ?? 0,
                'keterangan' => $payload['keterangan'] ?? null,
                'items' => $payload['items'] ?? [],
                'nota_paths' => [],
            ]);

            $url = $this->kalkulatorUrl($session);

            return $session->setRelation('pengeluaran', collect([$expense]));
        });

        $lastExpense = $session->pengeluaran->last();

        $action->update([
            'status' => AiPendingAction::STATUS_CONFIRMED,
            'result_type' => KalkulatorBelanja::class,
            'result_id' => $session->getKey(),
            'confirmed_at' => now(),
        ]);

        app(AuditLogger::class)->activity(
            'ai_action_transaksi_belanja',
            'Asisten AI mencatat transaksi belanja: '.($action->summary ?? ''),
            $user,
            [
                'pending_action_id' => $action->getKey(),
                'kalkulator_belanja_id' => $session->getKey(),
                'pengeluaran_belanja_id' => $lastExpense?->getKey(),
            ],
        );

        return [
            'type' => 'belanja',
            'id' => (int) $session->getKey(),
            'label' => 'Transaksi belanja dicatat (sesi '.$session->judul.').',
            'url' => $url,
        ];
    }

    private function assertOwnership(AiPendingAction $action, User $user): void
    {
        if ($user->hasRole('super_admin')) {
            return;
        }

        if ((int) $action->user_id !== (int) $user->getKey()) {
            throw new RuntimeException('Aksi ini bukan milik Anda.');
        }
    }

    private function mutasiUrl(Mutasi $mutasi): ?string
    {
        if (! class_exists(\App\Filament\Resources\MutasiResource::class)) {
            return null;
        }

        try {
            return \App\Filament\Resources\MutasiResource::getUrl('index');
        } catch (\Throwable) {
            return null;
        }
    }

    private function kalkulatorUrl(KalkulatorBelanja $session): ?string
    {
        if (! class_exists(\App\Filament\Resources\KalkulatorBelanjaResource::class)) {
            return null;
        }

        try {
            return \App\Filament\Resources\KalkulatorBelanjaResource::getUrl('edit', ['record' => $session]);
        } catch (\Throwable) {
            return null;
        }
    }
}
