<?php

namespace App\Filament\Resources\AiUsageResource\Pages;

use App\Filament\Resources\AiUsageResource;
use Filament\Resources\Pages\ListRecords;

class ListAiUsages extends ListRecords
{
    protected static string $resource = AiUsageResource::class;

    /** @return array<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [
            AiUsageSummaryWidget::class,
        ];
    }
}
