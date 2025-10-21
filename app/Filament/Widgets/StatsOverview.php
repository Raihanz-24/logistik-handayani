<?php

namespace App\Filament\Widgets;

use App\Models\Lokasi;
use App\Models\Produk;
use App\Models\User;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    use HasWidgetShield, InteractsWithPageFilters;
    protected ?string $heading = 'Statistik';
    protected static ?int $sort = 0;
    protected function getStats(): array
    {
        $filters = $this->filters;

        $produkQuery = Produk::query();
        $userQuery = User::query();
        $lokasiQuery = Lokasi::query();

        if ($filters['startDate'] ?? null) {
            $produkQuery->whereDate('created_at', '>=', $filters['startDate']);
            $userQuery->whereDate('created_at', '>=', $filters['startDate']);
            $lokasiQuery->whereDate('created_at', '>=', $filters['startDate']);
        }

        if ($filters['endDate'] ?? null) {
            $produkQuery->whereDate('created_at', '<=', $filters['endDate']);
            $userQuery->whereDate('created_at', '<=', $filters['endDate']);
            $lokasiQuery->whereDate('created_at', '<=', $filters['endDate']);
        }

        return [
            Stat::make('Total Produk', $produkQuery->count())
                ->url(route('filament.admin.resources.produk.index'))
                ->description('klik untuk melihat semua produk'),

            Stat::make('Total Pengguna', $userQuery->count())
                ->url(route('filament.admin.resources.pengguna.index'))
                ->description('klik untuk melihat semua pengguna'),

            Stat::make('Total Lokasi', $lokasiQuery->count())
                ->url(route('filament.admin.resources.lokasi.index'))
                ->description('klik untuk melihat semua lokasi'),
        ];
    }

}
