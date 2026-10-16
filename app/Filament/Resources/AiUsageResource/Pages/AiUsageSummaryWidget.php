<?php

namespace App\Filament\Resources\AiUsageResource\Pages;

use App\Models\AiUsage;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan penggunaan token Asisten AI: hari ini (vs anggaran harian),
 * 30 hari terakhir, rata-rata, dan kegagalan. Dilengkapi grafik 14 hari.
 */
class AiUsageSummaryWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $monthStart = CarbonImmutable::today()->subDays(29)->startOfDay();
        $budget = max(1, (int) config('ai.budget.daily_tokens', 1000000));

        $todayTotal = (int) AiUsage::query()->whereDate('created_at', $today)->sum('total_tokens');
        $todayRequests = (int) AiUsage::query()->whereDate('created_at', $today)->count();
        $todayPersen = round(($todayTotal / $budget) * 100, 1);

        $monthQuery = AiUsage::query()->where('created_at', '>=', $monthStart);
        $monthTotal = (int) $monthQuery->clone()->sum('total_tokens');
        $monthRequests = (int) $monthQuery->clone()->count();
        $monthErrors = (int) $monthQuery->clone()->where('status', AiUsage::STATUS_ERROR)->count();
        $avgPerRequest = $monthRequests > 0 ? (int) round($monthTotal / $monthRequests) : 0;

        $statusEmoji = $todayPersen >= 100 ? '🔴' : ($todayPersen >= 70 ? '🟠' : '🟢');

        return [
            Stat::make('Token hari ini', number_format($todayTotal, 0, ',', '.'))
                ->description($todayRequests.' permintaan · '.$todayPersen.'% dari anggaran '.number_format($budget, 0, ',', '.').' '.$statusEmoji)
                ->descriptionIcon('heroicon-m-bolt')
                ->color($todayPersen >= 100 ? 'danger' : ($todayPersen >= 70 ? 'warning' : 'primary'))
                ->chart($this->dailySeries(14, 'total_tokens')),
            Stat::make('Token 30 hari', number_format($monthTotal, 0, ',', '.'))
                ->description($monthRequests.' permintaan')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info')
                ->chart($this->dailySeries(30, 'total_tokens')),
            Stat::make('Rata-rata / permintaan', number_format($avgPerRequest, 0, ',', '.'))
                ->description('30 hari terakhir')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('success'),
            Stat::make('Gagal', number_format($monthErrors, 0, ',', '.'))
                ->description('30 hari terakhir')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($monthErrors > 0 ? 'danger' : 'gray'),
        ];
    }

    /**
     * Deret harian (total token per hari) untuk grafik.
     *
     * @return array<int, int|float>
     */
    private function dailySeries(int $days, string $column): array
    {
        $start = CarbonImmutable::today()->subDays($days - 1)->startOfDay();

        $rows = AiUsage::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as hari, SUM('.$column.') as total')
            ->groupBy('hari')
            ->pluck('total', 'hari');

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->addDays($i)->toDateString();
            $series[] = (int) ($rows[$date] ?? 0);
        }

        return $series;
    }
}
