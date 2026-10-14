<?php

namespace App\Filament\Resources\AiKnowledgeEntryResource\Pages;

use App\Filament\Resources\AiKnowledgeEntryResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateAiKnowledgeEntry extends CreateRecord
{
    protected static string $resource = AiKnowledgeEntryResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Tambah Pengetahuan AI';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
