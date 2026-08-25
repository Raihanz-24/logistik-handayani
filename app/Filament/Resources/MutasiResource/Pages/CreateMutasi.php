<?php

namespace App\Filament\Resources\MutasiResource\Pages;

use App\Filament\Resources\MutasiResource;
use App\Models\Mutasi;
use App\Services\MutasiDataService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateMutasi extends CreateRecord
{
    protected static string $resource = MutasiResource::class;

    protected int $createdCount = 0;

    /**
     * Restore the canonical product ID before Filament builds its validation
     * payload. A searchable Select can briefly lose its visible state while
     * Livewire rebuilds dependent rack options, while the mirrored ID remains.
     */
    protected function beforeValidate(): void
    {
        $items = $this->data['items'] ?? [];
        $jenis = $this->data['jenis_mutasi'] ?? null;
        $warehouseId = (int) ($this->data['lokasi_id'] ?? 0);

        foreach ($items as $key => $item) {
            $barangId = filled($item['barang_id'] ?? null)
                ? $item['barang_id']
                : ($item['barang_id_terpilih'] ?? null);

            if (filled($barangId)) {
                $items[$key]['barang_id'] = (int) $barangId;
                $items[$key]['barang_id_terpilih'] = (int) $barangId;
            }

            $targetPositionId = filled($item['posisi_rak_tujuan_id'] ?? null)
                ? $item['posisi_rak_tujuan_id']
                : ($item['posisi_rak_tujuan_id_terpilih'] ?? null);

            if (filled($targetPositionId)) {
                $items[$key]['posisi_rak_tujuan_id'] = (int) $targetPositionId;
                $items[$key]['posisi_rak_tujuan_id_terpilih'] = (int) $targetPositionId;
            }

            if ($jenis !== 'masuk' && blank($item['kondisi_asal'] ?? null) && filled($barangId)) {
                $items[$key]['kondisi_asal'] = MutasiResource::defaultSourceCondition(
                    (int) $barangId,
                    $warehouseId,
                );
            }

            $sourceCondition = $items[$key]['kondisi_asal'] ?? null;
            $items[$key]['kondisi_tujuan'] = match ($jenis) {
                'masuk' => Mutasi::KONDISI_BAIK,
                'keluar' => $sourceCondition,
                'perubahan_kondisi' => filled($item['kondisi_tujuan'] ?? null)
                    ? $item['kondisi_tujuan']
                    : ($sourceCondition === Mutasi::KONDISI_BAIK
                        ? Mutasi::KONDISI_RUSAK
                        : Mutasi::KONDISI_BAIK),
                default => $item['kondisi_tujuan'] ?? null,
            };
        }

        $this->data['items'] = $items;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $items = collect($data['items'] ?? [])->map(function (array $item): array {
            $item['barang_id'] = filled($item['barang_id'] ?? null)
                ? $item['barang_id']
                : ($item['barang_id_terpilih'] ?? null);
            $item['posisi_rak_tujuan_id'] = filled($item['posisi_rak_tujuan_id'] ?? null)
                ? $item['posisi_rak_tujuan_id']
                : ($item['posisi_rak_tujuan_id_terpilih'] ?? null);
            unset($item['barang_id_terpilih'], $item['posisi_rak_tujuan_id_terpilih']);

            return $item;
        });
        unset($data['items']);

        $barangIds = $items->pluck('barang_id')->filter()->map(fn ($id): int => (int) $id);
        if ($barangIds->count() !== $barangIds->unique()->count()) {
            throw new \RuntimeException('Setiap barang hanya boleh dipilih satu kali dalam satu mutasi.');
        }

        return DB::transaction(function () use ($data, $items): Mutasi {
            $firstRecord = null;
            $actorId = (int) auth()->id();
            $preparation = app(MutasiDataService::class);

            foreach ($items as $item) {
                $prepared = $preparation->prepare([...$data, ...$item]);
                $record = Mutasi::query()->create([
                    ...$prepared,
                    'status' => 'pending',
                    'user_id' => $actorId,
                    'created_by' => $actorId,
                ]);
                $firstRecord ??= $record;
                $this->createdCount++;
            }

            if (! $firstRecord) {
                throw new \RuntimeException('Minimal satu barang wajib ditambahkan.');
            }

            return $firstRecord;
        });
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return "{$this->createdCount} mutasi berhasil dibuat dan menunggu approval.";
    }

    protected function getRedirectUrl(): string
    {
        return MutasiResource::getUrl('index');
    }
}
