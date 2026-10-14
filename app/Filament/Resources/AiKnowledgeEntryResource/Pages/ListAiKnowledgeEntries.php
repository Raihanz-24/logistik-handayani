<?php

namespace App\Filament\Resources\AiKnowledgeEntryResource\Pages;

use App\Filament\Resources\AiKnowledgeEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAiKnowledgeEntries extends ListRecords
{
    protected static string $resource = AiKnowledgeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tambah Pengetahuan'),
        ];
    }
}
