<?php

namespace App\Filament\Resources\AiUsageResource\Pages;

use App\Models\AiUsage;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ringkasan penggunaan token Asisten AI (hari ini & 30 hari terakhir).
 */
class AiUsageSummaryWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = CarbonImmutable::today();
        $monthStart = CarbonImmutable::today()->subDays(29)->startOfDay();

        $todayTotal = (int) AiUsage::query()->whereDate('created_at', $today)->sum('total_tokens');
        $todayRequests = (int) AiUsage::query()->whereDate('created_at', $today)->count();

        $monthQuery = AiUsage::query()->where('created_at', '>=', $monthStart);
        $monthTotal = (int) $monthQuery->clone()->sum('total_tokens');
        $monthRequests = (int) $monthQuery->clone()->count();
        $monthErrors = (int) $monthQuery->clone()->where('status', AiUsage::STATUS_ERROR)->count();
        $avgPerRequest = $monthRequests > 0 ? (int) round($monthTotal / $monthRequests) : 0;

        return [
            Stat::make('Token hari ini', number_format($todayTotal, 0, ',', '.'))
                ->description($todayRequests.' permintaan hari ini')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('primary'),
            Stat::make('Token 30 hari', number_format($monthTotal, 0, ',', '.'))
                ->description($monthRequests.' permintaan')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info'),
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
}
