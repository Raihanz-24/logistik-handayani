<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BarangLokasiResource\Pages;
use App\Models\Barang;
use App\Models\BarangLokasi;
use App\Services\HistoricalStockService;
use App\Services\StockExcelExportService;
use App\Services\StockPdfExportService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BarangLokasiResource extends Resource
{
    protected static ?string $model = BarangLokasi::class;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Stok Barang';

    protected static ?string $pluralLabel = 'Stok Barang';

    protected static ?string $slug = 'stok-barang';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('lokasi', fn (Builder $query): Builder => $query->gudang());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('barang_id')
                    ->label('Barang')
                    ->relationship('barang', 'nama_barang')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false),
                Forms\Components\Select::make('lokasi_id')
                    ->label('Gudang')
                    ->relationship(
                        name: 'lokasi',
                        titleAttribute: 'nama_lokasi',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->gudang(),
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('stok')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table

            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(10)
            ->extremePaginationLinks()
            ->defaultSort(
                fn (Builder $query): Builder => $query->orderBy(
                    Barang::query()
                        ->select('nama_barang')
                        ->whereColumn('barangs.id', 'barang_lokasi.barang_id'),
                ),
            )
            ->columns([
                Tables\Columns\ImageColumn::make('barang.gambar')
                    ->label('Gambar')
                    ->disk('public')
                    ->height(48)
                    ->width(48)
                    ->square()
                    ->tooltip(fn (BarangLokasi $record): string => filled($record->barang?->gambar)
                        ? 'Klik untuk melihat gambar'
                        : 'Gambar belum tersedia')
                    ->extraImgAttributes(['style' => 'cursor: zoom-in;'])
                    ->toggleable()
                    ->action(
                        Action::make('preview-gambar-stok')
                            ->modalHeading(fn (BarangLokasi $record): string => $record->barang?->nama_barang ?? 'Gambar Barang')
                            ->modalContent(fn (BarangLokasi $record) => view(
                                'filament.components.barang-image-preview',
                                ['barang' => $record->barang],
                            ))
                            ->modalWidth('5xl')
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Kembali')
                            ->visible(fn (BarangLokasi $record): bool => filled($record->barang?->gambar)),
                    ),
                Tables\Columns\TextColumn::make('barang.nama_barang')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('lokasi.nama_lokasi')
                    ->label('Gudang')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('posisiRak.kode')
                    ->label('Posisi Rak')
                    ->placeholder('Tanpa rak')
                    ->badge()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('stok')
                    ->label('Total Stok')
                    ->getStateUsing(fn (BarangLokasi $record, $livewire): int => $livewire
                        ->stockValueForTable($record, 'stok'))
                    ->numeric()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('barang.satuan')
                    ->label('Satuan')
                    ->placeholder('-')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('stok_baik')
                    ->label('Baik')
                    ->getStateUsing(fn (BarangLokasi $record, $livewire): int => $livewire
                        ->stockValueForTable($record, 'stok_baik'))
                    ->numeric()
                    ->color('success')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('stok_rusak')
                    ->label('Rusak')
                    ->getStateUsing(fn (BarangLokasi $record, $livewire): int => $livewire
                        ->stockValueForTable($record, 'stok_rusak'))
                    ->numeric()
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('stok_hilang')
                    ->label('Hilang')
                    ->getStateUsing(fn (BarangLokasi $record, $livewire): int => $livewire
                        ->stockValueForTable($record, 'stok_hilang'))
                    ->numeric()
                    ->color('warning')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('tanggal_stok')
                    ->label('Stok per Tanggal')
                    ->form([
                        Forms\Components\DatePicker::make('tanggal')
                            ->label('Posisi stok pada akhir tanggal')
                            ->helperText('Kosongkan untuk menampilkan stok saat ini.')
                            ->maxDate(now('Asia/Jakarta')->toDateString())
                            ->native(false),
                    ])
                    ->indicateUsing(function (array $data): ?string {
                        if (blank($data['tanggal'] ?? null)) {
                            return null;
                        }

                        $date = app(HistoricalStockService::class)
                            ->parseAsOfDate($data['tanggal']);

                        return 'Posisi stok: '.$date->locale('id')->translatedFormat('d F Y');
                    }),
            ])
            ->headerActions([
                Action::make('export-excel')
                    ->label('Export Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->tooltip('Rekap stok pada akhir tanggal tertentu sesuai filter aktif')
                    ->form([
                        Forms\Components\DatePicker::make('as_of_date')
                            ->label('Posisi stok per tanggal')
                            ->helperText('Mutasi setelah tanggal ini tidak dihitung dalam hasil export.')
                            ->default(fn ($livewire): string => $livewire->stockAsOfDate())
                            ->maxDate(now('Asia/Jakarta')->toDateString())
                            ->native(false)
                            ->required(),
                    ])
                    ->modalHeading('Export Rekap Stok ke Excel')
                    ->modalSubmitActionLabel('Unduh Excel')
                    ->action(function (array $data, $livewire) {
                        try {
                            return app(StockExcelExportService::class)
                                ->download([
                                    ...$livewire->stockExportContext(),
                                    'as_of_date' => $data['as_of_date'],
                                ]);
                        } catch (\Throwable $exception) {
                            report($exception);

                            Notification::make()
                                ->title('Export Excel gagal diproses')
                                ->body('Silakan muat ulang halaman dan coba kembali. Detail teknis telah dicatat di log server.')
                                ->danger()
                                ->send();

                            return null;
                        }
                    }),
                Action::make('export-pdf')
                    ->label('Export PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('danger')
                    ->tooltip('Unduh seluruh stok sesuai filter aktif')
                    ->action(fn ($livewire) => app(StockPdfExportService::class)
                        ->download($livewire->stockPdfExportContext())),
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBarangLokasis::route('/'),
            // 'create' => Pages\CreateBarangLokasi::route('/create'),
            // 'edit' => Pages\EditBarangLokasi::route('/{record}/edit'),
        ];
    }
}
