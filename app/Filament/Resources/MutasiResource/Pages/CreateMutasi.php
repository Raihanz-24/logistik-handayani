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

    protected function handleRecordCreation(array $data): Model
    {
        $items = collect($data['items'] ?? []);
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
