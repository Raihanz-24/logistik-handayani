<?php

namespace App\Filament\Resources\CatatanResource\Pages;

use App\Filament\Resources\CatatanResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCatatans extends ListRecords
{
    protected static string $resource = CatatanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Buat Catatan'),
        ];
    }
}
