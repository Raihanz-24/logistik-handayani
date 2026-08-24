<?php

namespace App\Filament\Resources\LokasiResource\Pages;

use App\Filament\Resources\LokasiResource;
use App\Models\Lokasi;
use App\Services\RakGudangService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateLokasi extends CreateRecord
{
    protected static string $resource = LokasiResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Buat Lokasi';
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Lokasi {
            $configuration = $data['konfigurasi_rak'] ?? [];
            $record = Lokasi::query()->create($data);
            app(RakGudangService::class)->sync($record, $configuration);

            return $record;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
