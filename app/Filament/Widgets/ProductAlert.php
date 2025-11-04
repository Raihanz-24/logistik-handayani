<?php

namespace App\Filament\Widgets;

use App\Models\ProdukLokasi;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ProductAlert extends BaseWidget
{
    use HasWidgetShield, InteractsWithPageFilters;

    protected static ?string $heading = 'Stok hampir habis';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 1;

    public function getTableRecordKey($record): string
    {
        return "{$record->produk_id}-{$record->lokasi_id}";
    }

    /* ---------------- HEADER (STATISTIK) ---------------- */
    protected function getTableHeading(): string|HtmlString|null
    {
        [$start, $end] = $this->resolveDateRange();
        [$labels, $values, $countItems] = $this->buildLowStockBars($start, $end);
        $chartSvg = $this->renderHorizontalBarSvg($labels, $values, 780, 22);

        $rangeBadge = '';
        if (($this->filters['startDate'] ?? null) || ($this->filters['endDate'] ?? null)) {
            $startStr = e($start->toDateString());
            $endStr   = e($end->toDateString());
            $rangeBadge = "<span class='ml-2 inline-flex items-center rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-200'>{$startStr} → {$endStr}</span>";
        }

        $countHtml = number_format($countItems);

        $html = <<<HTML
<div class="flex flex-col gap-2">
  <div class="flex items-center gap-2">
    <span class="inline-flex h-2.5 w-2.5 animate-pulse rounded-full bg-amber-500"></span>
    <span class="text-base font-semibold">Stok hampir habis</span>
    <span class="ml-1 text-xs text-gray-500">({$countHtml} item stok &lt; 5)</span>
    {$rangeBadge}
  </div>
  <div class="w-full">{$chartSvg}</div>
</div>
HTML;

        return new HtmlString($html);
    }

    protected function getTableDescription(): string|HtmlString|null
    {
        return new HtmlString('<span class="text-xs text-gray-500">Grafik menampilkan item dengan stok &lt; 5. Tabel di bawah menampilkan stok &lt; 10.</span>');
    }

    protected function getTableEmptyStateIcon(): ?string
    {
        return 'heroicon-o-check-badge';
    }
    protected function getTableEmptyStateHeading(): ?string
    {
        return 'Semua stok aman';
    }
    protected function getTableEmptyStateDescription(): ?string
    {
        return 'Tidak ada item dengan stok di bawah ambang (10).';
    }

    /* ---------------- TABEL ---------------- */
    public function table(Table $table): Table
    {
        $filters = $this->filters;
        $query = ProdukLokasi::query()
            ->with(['produk', 'lokasi'])
            ->where('stok', '<', 10)
            ->whereHas('mutasiWidget', function (Builder $query) use ($filters) {
                if ($filters['startDate'] ?? null) {
                    $query->whereDate('tanggal', '>=', $filters['startDate']);
                }
                if ($filters['endDate'] ?? null) {
                    $query->whereDate('tanggal', '<=', $filters['endDate']);
                }
            });

        return $table
            ->paginationPageOptions([5, 25, 50, 100, 250])
            ->defaultPaginationPageOption(5)
            ->defaultSort('stok')
            ->query($query)
            ->columns([
                TextColumn::make('produk.nama_produk')
                    ->label('Produk')
                    ->limit(30)
                    ->sortable()
                    ->searchable(),
                TextColumn::make('lokasi.nama_lokasi')
                    ->label('Lokasi')
                    ->limit(30)
                    ->sortable()
                    ->searchable(),
                TextColumn::make('stok')
                    ->label('Stok')
                    ->sortable()
                    ->badge()
                    ->color(fn(int $state): string => match (true) {
                        $state <= 0 => 'danger',
                        $state < 5  => 'warning',
                        default     => 'gray',
                    }),
            ]);
    }

    /* ---------------- UTIL ---------------- */
    private function resolveDateRange(): array
    {
        $filters = $this->filters ?? [];
        $end = isset($filters['endDate'])
            ? Carbon::parse($filters['endDate'])->endOfDay()
            : now()->endOfDay();

        $start = isset($filters['startDate'])
            ? Carbon::parse($filters['startDate'])->startOfDay()
            : (clone $end)->subDays(29)->startOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }
        return [$start, $end];
    }

    private function buildLowStockBars(Carbon $start, Carbon $end): array
    {
        $cacheKey = sprintf('pl_low_stock_bars:%s:%s', $start->toDateString(), $end->toDateString());
        return Cache::remember($cacheKey, 60, function () use ($start, $end) {
            $rows = ProdukLokasi::query()
                ->with(['produk:id,nama_produk', 'lokasi:id,nama_lokasi'])
                ->where('stok', '<', 5)
                ->whereHas('mutasiWidget', function (Builder $q) use ($start, $end) {
                    $q->whereDate('tanggal', '>=', $start->toDateString())
                        ->whereDate('tanggal', '<=', $end->toDateString());
                })
                ->orderBy('stok', 'asc')
                ->limit(10)
                ->get(['produk_id', 'lokasi_id', 'stok']);

            $labels = [];
            $values = [];
            foreach ($rows as $r) {
                $prod = $r->produk?->nama_produk ?? 'Produk';
                $lok  = $r->lokasi?->nama_lokasi ?? 'Lokasi';
                $labels[] = $this->truncate("{$prod} ({$lok})", 36);
                $values[] = (int) $r->stok;
            }
            return [$labels, $values, count($values)];
        });
    }

    /* ---------------- SVG CHART ---------------- */
    private function renderHorizontalBarSvg(array $labels, array $values, int $width = 780, int $rowHeight = 22): string
    {
        $count = count($labels);
        if ($count === 0) {
            return '<div class="h-10 text-xs text-gray-500">Tidak ada item stok &lt; 5 pada rentang tanggal.</div>';
        }

        $padX = 12;
        $padY = 10;
        $labelW = 360;
        $valPad = 8;
        $barAreaW = max(120, $width - $padX * 2 - $labelW);
        $barH = 10;
        $height = $padY * 2 + $count * $rowHeight;
        $max = max($values) ?: 1;

        $bars = '';
        foreach ($values as $i => $v) {
            $labelText = $this->xml($labels[$i]);
            $y = $padY + $i * $rowHeight + ($rowHeight - $barH) / 2;
            $w = (int) round(($v / $max) * $barAreaW);
            $barX = $padX + $labelW;
            $barColor = '#f9d923';
            $valX = $barX + $w + $valPad;
            $valY = $y + $barH;

            $bars .= "<text x='{$padX}' y='{$valY}' fill='#cbd5e1' font-size='11'>{$labelText}</text>";
            $bars .= "<rect x='{$barX}' y='{$y}' width='{$barAreaW}' height='{$barH}' rx='{$barH}' fill='rgba(255,255,255,0.08)' />";
            $bars .= "<rect x='{$barX}' y='{$y}' width='0' height='{$barH}' rx='{$barH}' fill='{$barColor}'>";
            $bars .= "<animate attributeName='width' from='0' to='{$w}' dur='700ms' fill='freeze' /></rect>";
            $bars .= "<text x='{$valX}' y='{$valY}' fill='#e5e7eb' font-size='11'>{$v}</text>";
        }

        $grid = '';
        foreach ([0, .25, .5, .75, 1] as $t) {
            $gx = $padX + $labelW + (int) round($t * $barAreaW);
            $grid .= "<line x1='{$gx}' y1='{$padY}' x2='{$gx}' y2='" . ($height - $padY) . "' stroke='rgba(255,255,255,.06)' stroke-width='1' />";
        }

        $svg = "<svg viewBox='0 0 {$width} {$height}' width='100%' height='{$height}' xmlns='http://www.w3.org/2000/svg' role='img'>{$grid}{$bars}</svg>";
        return $svg;
    }

    private function truncate(string $text, int $max = 36): string
    {
        $text = trim($text);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    private function xml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
