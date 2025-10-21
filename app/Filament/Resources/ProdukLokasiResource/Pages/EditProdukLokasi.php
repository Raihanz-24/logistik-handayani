<?php

namespace App\Filament\Resources\ProdukLokasiResource\Pages;

use App\Filament\Resources\ProdukLokasiResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProdukLokasi extends EditRecord
{
    protected static string $resource = ProdukLokasiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\DeleteAction::make(),
        ];
    }
}
