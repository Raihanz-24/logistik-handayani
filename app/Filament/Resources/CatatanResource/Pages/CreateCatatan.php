<?php

namespace App\Filament\Resources\CatatanResource\Pages;

use App\Filament\Resources\CatatanResource;
use App\Models\Catatan;
use Filament\Resources\Pages\CreateRecord;

class CreateCatatan extends CreateRecord
{
    protected static string $resource = CatatanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        if (($data['jenis'] ?? null) === Catatan::JENIS_BIASA) {
            $data['supplier_id'] = null;
        }

        return $data;
    }
}
