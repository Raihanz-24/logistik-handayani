<?php

namespace App\Filament\Widgets;

use App\Services\DashboardCacheService;
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

    protected static bool $isLazy = false;

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

        $today = now('Asia/Jakarta')->toDateString();
        $startKey = $start?->toDateString() ?? "default-{$today}";
        $endKey = $end?->toDateString() ?? "default-{$today}";
        $configKey = hash('sha256', serialize([
            config('saw-restock.limit'),
            config('saw-restock.period_days'),
            config('saw-restock.weights'),
        ]));

        $result = app(DashboardCacheService::class)->remember(
            "restock:v2:{$startKey}:{$endKey}:{$configKey}",
            fn (): array => app(SawRestockRecommendationService::class)->calculate($start, $end),
        );

        $result['recommendations'] = $result['recommendations']
            ->map(fn (array $item): array => $item + [
                'score_percentage' => min(100, max(0, $item['nilai_preferensi'] * 100)),
            ]);

        return $result;
    }
}
