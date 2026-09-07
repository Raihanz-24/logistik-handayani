<?php

namespace App\Filament\Resources\KalkulatorBelanjaResource\Pages;

use App\Filament\Resources\KalkulatorBelanjaResource;
use App\Models\PengeluaranBelanja;
use App\Models\User;
use App\Services\BelanjaPdfExportService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ListKalkulatorBelanjas extends Page
{
    use WithPagination;

    protected static string $resource = KalkulatorBelanjaResource::class;

    protected static string $view = 'filament.resources.kalkulator-belanja-resource.pages.list-kalkulator-belanjas';

    #[Url(as: 'cari')]
    public string $search = '';

    #[Url(as: 'bulan')]
    public ?string $filterMonth = null;

    #[Url(as: 'dari')]
    public ?string $dateFrom = null;

    #[Url(as: 'sampai')]
    public ?string $dateTo = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export-pdf')
                ->label('Export PDF')
                ->icon('heroicon-m-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    try {
                        return app(BelanjaPdfExportService::class)->download($this->pdfExportContext(), $user);
                    } catch (\Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Export PDF gagal diproses')
                            ->body('Silakan coba kembali. Detail teknis sudah dicatat pada log server.')
                            ->danger()
                            ->send();

                        return null;
                    }
                }),
            Actions\Action::make('buat-sesi-belanja')
                ->label('Buat Sesi Belanja')
                ->icon('heroicon-m-plus')
                ->url(KalkulatorBelanjaResource::getUrl('create')),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterMonth(): void
    {
        if (filled($this->filterMonth)) {
            $this->dateFrom = null;
            $this->dateTo = null;
        }

        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        if (filled($this->dateFrom)) {
            $this->filterMonth = null;
        }

        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        if (filled($this->dateTo)) {
            $this->filterMonth = null;
        }

        $this->resetPage();
    }

    public function resetLedgerFilters(): void
    {
        $this->search = '';
        $this->filterMonth = null;
        $this->dateFrom = null;
        $this->dateTo = null;
        $this->resetPage();
    }

    /** @return array{search: string, month: ?string, from: ?string, to: ?string} */
    public function pdfExportContext(): array
    {
        return [
            'search' => $this->search,
            'month' => $this->filterMonth,
            'from' => $this->dateFrom,
            'to' => $this->dateTo,
        ];
    }

    protected function getViewData(): array
    {
        $query = $this->filteredQuery();
        $sessionIds = (clone $query)
            ->reorder()
            ->select('kalkulator_belanjas.id');
        $expenses = PengeluaranBelanja::query()
            ->whereIn('kalkulator_belanja_id', $sessionIds);
        $expenseSummary = (clone $expenses)
            ->selectRaw('COALESCE(SUM(nominal), 0) as total_out')
            ->selectRaw('COUNT(*) as transaction_count')
            ->first();
        $receiptTransactions = (clone $expenses)
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('foto_nota')
                    ->orWhereHas('notas');
            })
            ->count();
        $records = (clone $query)
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(10);

        return [
            'records' => $records,
            'summary' => [
                'total_out' => (int) ($expenseSummary?->total_out ?? 0),
                'transactions' => (int) ($expenseSummary?->transaction_count ?? 0),
                'receipts' => $receiptTransactions,
                'sessions' => $records->total(),
                'period' => $this->periodLabel(),
            ],
            'monthOptions' => KalkulatorBelanjaResource::monthOptions(),
            'hasActiveFilters' => filled($this->search)
                || filled($this->filterMonth)
                || filled($this->dateFrom)
                || filled($this->dateTo),
        ];
    }

    private function filteredQuery(): Builder
    {
        $query = KalkulatorBelanjaResource::getEloquentQuery();
        $month = $this->validMonth($this->filterMonth);
        [$from, $to] = $this->validDateRange();
        $search = Str::limit(trim($this->search), 100, '');

        if ($month) {
            [$year, $monthNumber] = array_map('intval', explode('-', $month));
            $query->whereYear('tanggal', $year)->whereMonth('tanggal', $monthNumber);
        }

        $query
            ->when($from, fn (Builder $query, string $date): Builder => $query->whereDate('tanggal', '>=', $date))
            ->when($to, fn (Builder $query, string $date): Builder => $query->whereDate('tanggal', '<=', $date));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $query) use ($like): void {
                $query
                    ->where('judul', 'like', $like)
                    ->orWhereHas('pengeluaran', function (Builder $query) use ($like): void {
                        $query
                            ->where('nama_supplier_snapshot', 'like', $like)
                            ->orWhereHas('supplier', fn (Builder $query): Builder => $query->where('nama_supplier', 'like', $like))
                            ->orWhereHas('items', fn (Builder $query): Builder => $query
                                ->where('nama_barang_snapshot', 'like', $like)
                                ->orWhere('kode_barang_snapshot', 'like', $like));
                    });
            });
        }

        return $query;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function validDateRange(): array
    {
        $from = $this->validDate($this->dateFrom);
        $to = $this->validDate($this->dateTo);

        if ($from && $to && $from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    private function validDate(?string $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function validMonth(?string $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)
            ? $value
            : null;
    }

    private function periodLabel(): string
    {
        if ($month = $this->validMonth($this->filterMonth)) {
            return Carbon::createFromFormat('!Y-m', $month)->translatedFormat('F Y');
        }

        [$from, $to] = $this->validDateRange();

        return match (true) {
            filled($from) && filled($to) => Carbon::parse($from)->translatedFormat('d M Y').' - '.Carbon::parse($to)->translatedFormat('d M Y'),
            filled($from) => 'Mulai '.Carbon::parse($from)->translatedFormat('d M Y'),
            filled($to) => 'Sampai '.Carbon::parse($to)->translatedFormat('d M Y'),
            default => 'Semua periode',
        };
    }
}
