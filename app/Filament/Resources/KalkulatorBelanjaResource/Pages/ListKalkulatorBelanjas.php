<?php

namespace App\Filament\Resources\KalkulatorBelanjaResource\Pages;

use App\Filament\Resources\KalkulatorBelanjaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListKalkulatorBelanjas extends ListRecords
{
    protected static string $resource = KalkulatorBelanjaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Buat Sesi Belanja'),
        ];
    }
}
