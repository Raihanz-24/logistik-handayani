<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KalkulatorBelanjaResource\Pages;
use App\Filament\Resources\KalkulatorBelanjaResource\RelationManagers\PengeluaranRelationManager;
use App\Models\KalkulatorBelanja;
use App\Models\PengeluaranBelanja;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class KalkulatorBelanjaResource extends Resource
{
    protected static ?string $model = KalkulatorBelanja::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Catatan';

    protected static ?string $navigationLabel = 'Transaksi Belanja';

    protected static ?string $modelLabel = 'Transaksi Belanja';

    protected static ?string $pluralModelLabel = 'Transaksi Belanja';

    protected static ?string $recordTitleAttribute = 'judul';

    protected static ?string $slug = 'kalkulator-belanja';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()
            ->with([
                'user',
                'pengeluaran.supplier',
                'pengeluaran.notas',
            ])
            ->withSum('pengeluaran', 'nominal')
            ->withCount('pengeluaran');

        return $user ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Sesi Belanja')
                ->description('Simpan sesi terlebih dahulu. Transaksi setiap toko kemudian dicatat terpisah agar data lebih aman.')
                ->extraAttributes(['class' => 'wm-kb-section wm-kb-session-section'])
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Forms\Components\DatePicker::make('tanggal')
                        ->label('Tanggal Belanja')
                        ->default(today())
                        ->native(false)
                        ->required(),
                    Forms\Components\TextInput::make('judul')
                        ->label('Nama Sesi')
                        ->placeholder('Contoh: Belanja kebutuhan dapur')
                        ->maxLength(255)
                        ->required(),
                    Forms\Components\TextInput::make('uang_awal')
                        ->label('Uang Awal')
                        ->prefix('Rp')
                        ->helperText('Opsional. Kosongkan bila sesi ini hanya untuk mencatat pengeluaran.')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(999_999_999_999)
                        ->inputMode('numeric')
                        ->live(debounce: 500)
                        ->dehydrateStateUsing(fn (mixed $state): int => self::integerValue($state)),
                    Forms\Components\Textarea::make('catatan')
                        ->label('Catatan Sesi')
                        ->placeholder('Opsional')
                        ->rows(3)
                        ->maxLength(5000),
                ]),

            Section::make('Ringkasan Otomatis')
                ->description('Detail barang tidak mengubah stok atau mutasi. Total dihitung oleh server dari jumlah × harga satuan.')
                ->extraAttributes(['class' => 'wm-kb-section wm-kb-summary-section'])
                ->columns(['default' => 1, 'sm' => 3])
                ->schema([
                    Forms\Components\Placeholder::make('uang_awal_preview')
                        ->label('Uang Awal')
                        ->content(fn (Get $get): string => filled($get('uang_awal'))
                            ? self::rupiah(self::integerValue($get('uang_awal')))
                            : 'Tidak dicatat')
                        ->extraAttributes(['class' => 'wm-kb-balance wm-kb-balance--initial']),
                    Forms\Components\Placeholder::make('total_pengeluaran_preview')
                        ->label('Total Pengeluaran')
                        ->content(fn (?KalkulatorBelanja $record): string => '- '.self::rupiah($record?->total_pengeluaran ?? 0))
                        ->extraAttributes(['class' => 'wm-kb-balance wm-kb-balance--out']),
                    Forms\Components\Placeholder::make('sisa_uang_preview')
                        ->label('Sisa Uang')
                        ->content(function (Get $get, ?KalkulatorBelanja $record): string {
                            if (! filled($get('uang_awal'))) {
                                return 'Tidak dicatat';
                            }

                            $remaining = self::integerValue($get('uang_awal')) - ($record?->total_pengeluaran ?? 0);

                            return self::rupiah($remaining).($remaining < 0 ? ' — pengeluaran melebihi uang awal' : '');
                        })
                        ->extraAttributes(['class' => 'wm-kb-balance wm-kb-balance--remaining']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('tanggal', 'desc')
            ->recordUrl(fn (KalkulatorBelanja $record): string => static::getUrl('view', ['record' => $record]))
            ->columns([
                Tables\Columns\ViewColumn::make('mobile_transaction')
                    ->label('Riwayat Belanja')
                    ->view('filament.tables.columns.kalkulator-belanja-mobile')
                    ->hiddenFrom('md'),
                Tables\Columns\TextColumn::make('judul')
                    ->label('Sesi Belanja')
                    ->description(fn (KalkulatorBelanja $record): string => $record->tanggal->format('d M Y'))
                    ->searchable()
                    ->sortable()
                    ->visibleFrom('md')
                    ->wrap(),
                Tables\Columns\TextColumn::make('daftar_supplier')
                    ->label('Pengeluaran per Toko')
                    ->state(fn (KalkulatorBelanja $record): array => $record->pengeluaran
                        ->groupBy(fn (PengeluaranBelanja $item): string => $item->namaSupplier())
                        ->map(fn ($items, string $supplier): string => $supplier.' — '.self::rupiah((int) $items->sum('nominal')))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->visibleFrom('md')
                    ->wrap(),
                Tables\Columns\TextColumn::make('uang_awal')
                    ->label('Uang Awal')
                    ->state(fn (KalkulatorBelanja $record): ?int => $record->hasInitialMoney()
                        ? (int) $record->uang_awal
                        : null)
                    ->formatStateUsing(fn (mixed $state): string => $state === null
                        ? 'Tidak dicatat'
                        : self::rupiah((int) $state))
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('total_pengeluaran')
                    ->label('Pengeluaran')
                    ->state(fn (KalkulatorBelanja $record): int => $record->total_pengeluaran)
                    ->formatStateUsing(fn (mixed $state): string => self::rupiah((int) $state))
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('sisa_uang')
                    ->label('Sisa')
                    ->state(fn (KalkulatorBelanja $record): ?int => $record->hasInitialMoney()
                        ? $record->sisa_uang
                        : null)
                    ->formatStateUsing(fn (mixed $state): string => $state === null ? '-' : self::rupiah((int) $state))
                    ->color(fn (KalkulatorBelanja $record): string => ! $record->hasInitialMoney()
                        ? 'gray'
                        : ($record->sisa_uang < 0 ? 'danger' : 'success'))
                    ->weight('bold')
                    ->visibleFrom('md'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Detail'),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Ringkasan Belanja')
                ->extraAttributes(['class' => 'wm-kb-detail-summary'])
                ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
                ->schema([
                    TextEntry::make('tanggal')->label('Tanggal')->date('d F Y'),
                    TextEntry::make('uang_awal')
                        ->label('Uang Awal')
                        ->state(fn (KalkulatorBelanja $record): string => $record->hasInitialMoney()
                            ? self::rupiah((int) $record->uang_awal)
                            : 'Tidak dicatat'),
                    TextEntry::make('total_pengeluaran')
                        ->label('Total Pengeluaran')
                        ->state(fn (KalkulatorBelanja $record): int => $record->total_pengeluaran)
                        ->formatStateUsing(fn (mixed $state): string => self::rupiah((int) $state)),
                    TextEntry::make('sisa_uang')
                        ->label('Sisa Uang')
                        ->state(fn (KalkulatorBelanja $record): string => $record->hasInitialMoney()
                            ? self::rupiah($record->sisa_uang)
                            : '-')
                        ->badge()
                        ->color(fn (KalkulatorBelanja $record): string => ! $record->hasInitialMoney()
                            ? 'gray'
                            : ($record->sisa_uang < 0 ? 'danger' : 'success')),
                    TextEntry::make('catatan')
                        ->label('Catatan Sesi')
                        ->placeholder('-')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [PengeluaranRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKalkulatorBelanjas::route('/'),
            'create' => Pages\CreateKalkulatorBelanja::route('/buat'),
            'view' => Pages\ViewKalkulatorBelanja::route('/{record}'),
            'edit' => Pages\EditKalkulatorBelanja::route('/{record}/ubah'),
        ];
    }

    /** @param array<mixed>|null $rows */
    public static function formTotal(?array $rows): int
    {
        return collect($rows ?? [])->sum(
            fn (mixed $row): int => self::integerValue(is_array($row) ? ($row['nominal'] ?? 0) : 0),
        );
    }

    public static function rupiah(int $amount): string
    {
        return ($amount < 0 ? '-' : '').'Rp'.number_format(abs($amount), 0, ',', '.');
    }

    /** @return array<string, string> */
    public static function monthOptions(): array
    {
        return collect(range(0, 35))
            ->mapWithKeys(function (int $offset): array {
                $date = Carbon::today()->startOfMonth()->subMonths($offset);

                return [$date->format('Y-m') => $date->translatedFormat('F Y')];
            })
            ->all();
    }

    private static function integerValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return (int) preg_replace('/[^0-9]/', '', (string) $value);
    }
}
