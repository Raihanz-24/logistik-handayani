<?php

namespace App\Filament\Resources\MutasiRakResource\Pages;

use App\Filament\Resources\MutasiRakResource;
use App\Services\MutasiRakService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateMutasiRak extends CreateRecord
{
    protected static string $resource = MutasiRakResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(MutasiRakService::class)->create($data, (int) auth()->id());
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'data.barang_id' => $exception->getMessage(),
            ]);
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Mutasi antar-rak dibuat dan menunggu persetujuan.';
    }

    protected function getRedirectUrl(): string
    {
        return MutasiRakResource::getUrl('index');
    }
}
