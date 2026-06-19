<?php

namespace App\Filament\Resources\BarangLokasiResource\Pages;

use App\Filament\Resources\BarangLokasiResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBarangLokasis extends ListRecords
{
    public function getTableRecordKey($record): string
    {
        return "{$record->barang_id}-{$record->lokasi_id}";
    }

    protected static string $resource = BarangLokasiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
        ];
    }
}
