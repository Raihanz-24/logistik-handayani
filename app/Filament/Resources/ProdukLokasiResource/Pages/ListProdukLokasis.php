<?php

namespace App\Filament\Resources\ProdukLokasiResource\Pages;

use App\Filament\Resources\ProdukLokasiResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProdukLokasis extends ListRecords
{
    public function getTableRecordKey($record): string
    {
        return "{$record->produk_id}-{$record->lokasi_id}";
    }

    protected static string $resource = ProdukLokasiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
        ];
    }
}
