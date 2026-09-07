<?php

namespace App\Filament\Resources\KalkulatorBelanjaResource\Pages;

use App\Filament\Resources\KalkulatorBelanjaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKalkulatorBelanja extends CreateRecord
{
    protected static string $resource = KalkulatorBelanjaResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
