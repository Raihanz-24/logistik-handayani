<?php

namespace App\Filament\Widgets;

use App\Services\SawRestockRecommendationService;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Carbon\Carbon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

class RestockRecommendation extends Widget
{
    use HasWidgetShield;
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.restock-recommendation';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $start = filled($this->filters['startDate'] ?? null)
            ? Carbon::parse($this->filters['startDate'])
            : null;
        $end = filled($this->filters['endDate'] ?? null)
            ? Carbon::parse($this->filters['endDate'])
            : null;

        return app(SawRestockRecommendationService::class)
            ->calculate($start, $end);
    }
}
