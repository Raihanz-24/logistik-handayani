<?php

namespace App\Filament\Pages;

use App\Services\DashboardCacheService;
use App\Services\SawRestockRecommendationService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\WithPagination;

class SawRestockReport extends Page implements HasForms
{
    use InteractsWithForms;
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Analisis Prioritas SAW';

    protected static ?string $title = 'Analisis Prioritas Restock SAW';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.saw-restock-report';

    /** @var array<string, mixed>|null */
    public ?array $filters = [];

    public function mount(): void
    {
        $this->form->fill($this->defaultFilters());
    }

    /** @return array<string, string> */
    private function defaultFilters(): array
    {
        $now = CarbonImmutable::now('Asia/Jakarta');

        return [
            'start_date' => $now->startOfMonth()->toDateString(),
            'end_date' => $now->endOfMonth()->toDateString(),
            'search' => '',
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Filter analisis')
                    ->description('Pemakaian hanya menghitung mutasi keluar yang sudah disetujui.')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        DatePicker::make('start_date')
                            ->label('Tanggal mulai')
                            ->native(true)
                            ->closeOnDateSelection()
                            ->displayFormat('d M Y')
                            ->required()
                            ->maxDate(fn (Get $get): ?string => $get('end_date')),
                        DatePicker::make('end_date')
                            ->label('Tanggal akhir')
                            ->native(true)
                            ->closeOnDateSelection()
                            ->displayFormat('d M Y')
                            ->required()
                            ->minDate(fn (Get $get): ?string => $get('start_date')),
                        TextInput::make('search')
                            ->label('Cari barang')
                            ->placeholder('Nama atau kode barang')
                            ->maxLength(100),
                    ]),
            ])
            ->statePath('filters');
    }

    public function applyFilters(): void
    {
        $this->form->getState();
        $this->resetPage('sawReportPage');
    }

    public function useThisWeek(): void
    {
        $now = CarbonImmutable::now('Asia/Jakarta');
        $this->form->fill([
            ...($this->filters ?? []),
            'start_date' => $now->startOfWeek()->toDateString(),
            'end_date' => $now->endOfWeek()->toDateString(),
        ]);
        $this->resetPage('sawReportPage');
    }

    public function useThisMonth(): void
    {
        $this->form->fill($this->defaultFilters());
        $this->resetPage('sawReportPage');
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, weights: array<string, float>, recommendations: Collection<int, array<string, mixed>>}
     */
    public function report(): array
    {
        [$start, $end] = $this->period();
        $configKey = hash('sha256', serialize(config('saw-restock.weights', [])));

        /** @var array{start: CarbonImmutable, end: CarbonImmutable, weights: array<string, float>, recommendations: Collection<int, array<string, mixed>>} $result */
        $result = app(DashboardCacheService::class)->remember(
            "saw-report:v1:{$start->toDateString()}:{$end->toDateString()}:{$configKey}",
            fn (): array => app(SawRestockRecommendationService::class)->calculate($start, $end, PHP_INT_MAX),
        );

        $search = mb_strtolower(trim((string) ($this->filters['search'] ?? '')));
        if ($search !== '') {
            $result['recommendations'] = $result['recommendations']
                ->filter(fn (array $item): bool => str_contains(mb_strtolower($item['nama_barang']), $search)
                    || str_contains(mb_strtolower($item['kode_barang']), $search))
                ->values();
        }

        return $result;
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginatedRecommendations(): LengthAwarePaginator
    {
        $recommendations = $this->report()['recommendations'];
        $perPage = 20;
        $page = $this->getPage('sawReportPage');

        return new LengthAwarePaginator(
            $recommendations->forPage($page, $perPage)->values(),
            $recommendations->count(),
            $perPage,
            $page,
            ['pageName' => 'sawReportPage'],
        );
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function period(): array
    {
        $defaults = $this->defaultFilters();
        $start = CarbonImmutable::parse($this->filters['start_date'] ?? $defaults['start_date'], 'Asia/Jakarta')->startOfDay();
        $end = CarbonImmutable::parse($this->filters['end_date'] ?? $defaults['end_date'], 'Asia/Jakarta')->endOfDay();

        return $start->greaterThan($end)
            ? [$end->startOfDay(), $start->endOfDay()]
            : [$start, $end];
    }
}
