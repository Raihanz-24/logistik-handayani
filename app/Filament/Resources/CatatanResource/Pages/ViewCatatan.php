<?php

namespace App\Filament\Resources\CatatanResource\Pages;

use App\Filament\Resources\CatatanResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCatatan extends ViewRecord
{
    protected static string $resource = CatatanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Edit Catatan'),
        ];
    }
}
