<?php

namespace App\Filament\Resources\BarangLokasiResource\Pages;

use App\Filament\Resources\BarangLokasiResource;
use App\Models\BarangLokasi;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class ListBarangLokasis extends ListRecords
{
    protected static string $view = 'filament.resources.barang-lokasi-resource.pages.list-barang-lokasis';

    /** @var array<int, string> */
    #[Url(as: 'gudang')]
    public array $gudangAktif = [];

    /** @var array<string, array{label: string, keyword: string}> */
    private const GUDANG_CEPAT = [
        'dapur' => ['label' => 'Gudang Dapur', 'keyword' => 'dapur'],
        'utama' => ['label' => 'Gudang Utama', 'keyword' => 'utama'],
    ];

    public function mount(): void
    {
        $this->gudangAktif = array_values(array_intersect(array_keys(self::GUDANG_CEPAT), $this->gudangAktif));

        parent::mount();
    }

    public function getTableRecordKey($record): string
    {
        return "{$record->barang_id}-{$record->lokasi_id}";
    }

    protected static string $resource = BarangLokasiResource::class;

    public function toggleGudang(string $gudang): void
    {
        if (! array_key_exists($gudang, self::GUDANG_CEPAT)) {
            return;
        }

        $this->gudangAktif = in_array($gudang, $this->gudangAktif, true)
            ? array_values(array_diff($this->gudangAktif, [$gudang]))
            : [...$this->gudangAktif, $gudang];

        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    public function resetGudang(): void
    {
        $this->gudangAktif = [];
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    /** @return array<string, array{label: string, keyword: string}> */
    public function gudangCepat(): array
    {
        return self::GUDANG_CEPAT;
    }

    /**
     * @return array{
     *     warehouses: array<int, array{label: string, keyword: string}>,
     *     search: string
     * }
     */
    public function stockPdfExportContext(): array
    {
        $warehouses = collect($this->gudangAktif)
            ->filter(fn (string $key): bool => array_key_exists($key, self::GUDANG_CEPAT))
            ->map(fn (string $key): array => self::GUDANG_CEPAT[$key])
            ->values()
            ->all();

        return [
            'warehouses' => $warehouses,
            'search' => trim((string) ($this->tableSearch ?? '')),
        ];
    }

    /** @return array<string, array{label: string, jumlah_barang: int, stok: int, baik: int, rusak: int, hilang: int}> */
    public function ringkasanGudang(): array
    {
        return collect(self::GUDANG_CEPAT)
            ->mapWithKeys(function (array $gudang, string $key): array {
                $ringkasan = BarangLokasi::query()
                    ->whereHas('lokasi', fn (Builder $query): Builder => $query
                        ->where('nama_lokasi', 'like', "%{$gudang['keyword']}%"))
                    ->selectRaw('COUNT(DISTINCT barang_id) as jumlah_barang')
                    ->selectRaw('COALESCE(SUM(stok), 0) as stok')
                    ->selectRaw('COALESCE(SUM(stok_baik), 0) as baik')
                    ->selectRaw('COALESCE(SUM(stok_rusak), 0) as rusak')
                    ->selectRaw('COALESCE(SUM(stok_hilang), 0) as hilang')
                    ->first();

                return [$key => [
                    'label' => $gudang['label'],
                    'jumlah_barang' => (int) ($ringkasan?->jumlah_barang ?? 0),
                    'stok' => (int) ($ringkasan?->stok ?? 0),
                    'baik' => (int) ($ringkasan?->baik ?? 0),
                    'rusak' => (int) ($ringkasan?->rusak ?? 0),
                    'hilang' => (int) ($ringkasan?->hilang ?? 0),
                ]];
            })
            ->all();
    }

    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();
        $gudangAktif = array_intersect(array_keys(self::GUDANG_CEPAT), $this->gudangAktif);

        if (! $query || $gudangAktif === []) {
            return $query;
        }

        return $query->whereHas('lokasi', function (Builder $lokasiQuery) use ($gudangAktif): void {
            $lokasiQuery->where(function (Builder $namaQuery) use ($gudangAktif): void {
                foreach ($gudangAktif as $gudang) {
                    $keyword = self::GUDANG_CEPAT[$gudang]['keyword'];
                    $namaQuery->orWhere('nama_lokasi', 'like', "%{$keyword}%");
                }
            });
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            // Actions\CreateAction::make(),
        ];
    }
}
