<?php

namespace App\Filament\Resources\AiKnowledgeEntryResource\Pages;

use App\Filament\Resources\AiKnowledgeEntryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAiKnowledgeEntry extends EditRecord
{
    protected static string $resource = AiKnowledgeEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
