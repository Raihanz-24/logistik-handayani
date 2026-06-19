<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BarangAlert;
use App\Filament\Widgets\DashboardHero;
use App\Filament\Widgets\RestockRecommendation;
use App\Filament\Widgets\StatsOverview;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    use BaseDashboard\Concerns\HasFiltersForm;

    protected static string $view = 'filament.pages.dashboard';

    protected ?string $maxContentWidth = 'full';

    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'warehouse-dashboard-body',
        ];
    }

    public function getColumns(): int|string|array
    {
        return 1;
    }

    public function getWidgets(): array
    {
        return [
            DashboardHero::class,
        ];
    }

    public function getOperationalWidgets(): array
    {
        return [
            StatsOverview::class,
            BarangAlert::class,
            RestockRecommendation::class,
        ];
    }

    public function getVisibleOperationalWidgets(): array
    {
        return $this->filterVisibleWidgets($this->getOperationalWidgets());
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Periode analisis')
                    ->description('Atur rentang tanggal untuk statistik mutasi dan rekomendasi restock.')
                    ->icon('heroicon-o-calendar-days')
                    ->schema([
                        DatePicker::make('startDate')
                            ->native(true)
                            ->closeOnDateSelection()
                            ->displayFormat('d M Y')
                            ->label('Tanggal mulai')
                            ->maxDate(fn (Get $get) => $get('endDate')),
                        DatePicker::make('endDate')
                            ->label('Tanggal akhir')
                            ->native(true)
                            ->closeOnDateSelection()
                            ->displayFormat('d M Y')
                            ->minDate(fn (Get $get) => $get('startDate'))
                            ->maxDate(now()),
                    ])
                    ->columns(2),
            ]);
    }
}
