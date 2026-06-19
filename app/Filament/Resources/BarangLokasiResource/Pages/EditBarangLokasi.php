<?php

namespace App\Filament\Resources\BarangLokasiResource\Pages;

use App\Filament\Resources\BarangLokasiResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBarangLokasi extends EditRecord
{
    protected static string $resource = BarangLokasiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\DeleteAction::make(),
        ];
    }
}
