<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KalkulatorBelanjaResource\Pages;
use App\Models\KalkulatorBelanja;
use App\Models\PengeluaranBelanja;
use App\Models\Supplier;
use App\Services\NotaBelanjaImageService;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class KalkulatorBelanjaResource extends Resource
{
    private const MAX_ORIGINAL_RECEIPT_SIZE_KB = 10 * 1024;

    protected static ?string $model = KalkulatorBelanja::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Catatan';

    protected static ?string $navigationLabel = 'Kalkulator Belanja';

    protected static ?string $modelLabel = 'Kalkulator Belanja';

    protected static ?string $pluralModelLabel = 'Kalkulator Belanja';

    protected static ?string $recordTitleAttribute = 'judul';

    protected static ?string $slug = 'kalkulator-belanja';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()
            ->with(['user', 'pengeluaran.supplier'])
            ->withSum('pengeluaran', 'nominal')
            ->withCount('pengeluaran');

        return $user ? $query->visibleTo($user) : $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Sesi Belanja')
                ->description('Catatan keuangan ini berdiri sendiri dan tidak mengubah stok maupun mutasi barang.')
                ->extraAttributes(['class' => 'wm-kb-section wm-kb-session-section'])
                ->columns(2)
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
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(999_999_999_999)
                        ->inputMode('numeric')
                        ->live(debounce: 500)
                        ->required(),
                    Forms\Components\Textarea::make('catatan')
                        ->label('Catatan')
                        ->placeholder('Opsional')
                        ->rows(3)
                        ->maxLength(5000),
                ]),

            Section::make('Ringkasan Otomatis')
                ->description('Saldo berubah otomatis sesuai nominal belanja yang dimasukkan.')
                ->extraAttributes(['class' => 'wm-kb-section wm-kb-summary-section'])
                ->columns(3)
                ->schema([
                    Forms\Components\Placeholder::make('uang_awal_preview')
                        ->label('Uang Awal')
                        ->content(fn (Get $get): string => self::rupiah(self::integerValue($get('uang_awal'))))
                        ->extraAttributes(['class' => 'wm-kb-balance wm-kb-balance--initial']),
                    Forms\Components\Placeholder::make('total_pengeluaran_preview')
                        ->label('Total Pengeluaran')
                        ->content(fn (Get $get): string => '- '.self::rupiah(self::formTotal($get('pengeluaran'))))
                        ->extraAttributes(['class' => 'wm-kb-balance wm-kb-balance--out']),
                    Forms\Components\Placeholder::make('sisa_uang_preview')
                        ->label('Sisa Uang')
                        ->content(function (Get $get): string {
                            $remaining = self::integerValue($get('uang_awal')) - self::formTotal($get('pengeluaran'));

                            return self::rupiah($remaining).($remaining < 0 ? ' — pengeluaran melebihi uang awal' : '');
                        })
                        ->extraAttributes(['class' => 'wm-kb-balance wm-kb-balance--remaining']),
                ]),

            Section::make('Pengeluaran per Toko')
                ->description('Tambahkan satu kartu transaksi untuk setiap supplier. Foto nota bersifat opsional.')
                ->extraAttributes(['class' => 'wm-kb-section wm-kb-expense-section'])
                ->schema([
                    Repeater::make('pengeluaran')
                        ->label('Daftar Toko')
                        ->relationship()
                        ->orderColumn('urutan')
                        ->reorderable()
                        ->collapsible()
                        ->minItems(1)
                        ->maxItems(30)
                        ->defaultItems(1)
                        ->required()
                        ->addActionLabel('Tambah Pengeluaran Toko')
                        ->extraAttributes(['class' => 'wm-kb-expense-repeater'])
                        ->itemLabel(function (array $state): string {
                            $supplierId = (int) ($state['supplier_id'] ?? 0);
                            $supplier = $supplierId > 0
                                ? Supplier::query()->whereKey($supplierId)->value('nama_supplier')
                                : null;
                            $amount = self::integerValue($state['nominal'] ?? 0);

                            return $supplier
                                ? $supplier.($amount > 0 ? ' · '.self::rupiah($amount) : '')
                                : 'Pengeluaran baru';
                        })
                        ->schema([
                            Forms\Components\Select::make('supplier_id')
                                ->label('Toko / Supplier')
                                ->options(fn (): array => Supplier::query()->aktif()->orderBy('nama_supplier')
                                    ->pluck('nama_supplier', 'id')->all())
                                ->getOptionLabelUsing(fn (mixed $value): ?string => Supplier::query()
                                    ->whereKey((int) $value)->value('nama_supplier'))
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->createOptionForm([
                                    Forms\Components\TextInput::make('nama_supplier')
                                        ->label('Nama Supplier')
                                        ->required()
                                        ->unique(Supplier::class, 'nama_supplier')
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('kontak_person')
                                        ->label('Kontak Person')
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('telepon')
                                        ->label('No. Telepon')
                                        ->tel()
                                        ->maxLength(50),
                                ])
                                ->createOptionUsing(fn (array $data): int => (int) Supplier::query()
                                    ->create([...$data, 'aktif' => true])->getKey())
                                ->required()
                                ->columnSpan(2),
                            Forms\Components\TextInput::make('nominal')
                                ->label('Total Belanja')
                                ->prefix('Rp')
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->maxValue(999_999_999_999)
                                ->inputMode('numeric')
                                ->live(debounce: 400)
                                ->required(),
                            Forms\Components\TextInput::make('keterangan')
                                ->label('Keterangan')
                                ->placeholder('Opsional')
                                ->maxLength(255),
                            FileUpload::make('foto_nota')
                                ->label('Foto Nota')
                                ->helperText('Opsional. Maksimal 10 MB dan otomatis dikompres menjadi WebP.')
                                ->image()
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(self::MAX_ORIGINAL_RECEIPT_SIZE_KB)
                                ->disk('public')
                                ->directory('nota-belanja')
                                ->visibility('public')
                                ->imagePreviewHeight('180')
                                ->imageResizeMode('contain')
                                ->imageResizeTargetWidth('2000')
                                ->imageResizeTargetHeight('2000')
                                ->imageResizeUpscale(false)
                                ->openable()
                                ->downloadable()
                                ->afterStateUpdated(function (mixed $state): void {
                                    if (! $state instanceof TemporaryUploadedFile) {
                                        return;
                                    }

                                    Notification::make()
                                        ->title('Foto nota siap diproses')
                                        ->body('Foto akan dikompres otomatis ketika sesi disimpan.')
                                        ->info()
                                        ->send();
                                })
                                ->saveUploadedFileUsing(
                                    fn (TemporaryUploadedFile $file): string => app(NotaBelanjaImageService::class)->store($file),
                                )
                                ->deleteUploadedFileUsing(
                                    fn (string $file): bool => Storage::disk('public')->delete($file),
                                )
                                ->columnSpanFull(),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
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
                    ->formatStateUsing(fn (mixed $state): string => self::rupiah((int) $state))
                    ->visibleFrom('md')
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_pengeluaran')
                    ->label('Pengeluaran')
                    ->state(fn (KalkulatorBelanja $record): int => $record->total_pengeluaran)
                    ->visibleFrom('md')
                    ->formatStateUsing(fn (mixed $state): string => self::rupiah((int) $state)),
                Tables\Columns\TextColumn::make('sisa_uang')
                    ->label('Sisa')
                    ->state(fn (KalkulatorBelanja $record): int => $record->sisa_uang)
                    ->formatStateUsing(fn (mixed $state): string => self::rupiah((int) $state))
                    ->visibleFrom('md')
                    ->color(fn (KalkulatorBelanja $record): string => $record->sisa_uang < 0 ? 'danger' : 'success')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('pengeluaran_count')
                    ->label('Jumlah Toko')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Dibuat Oleh')
                    ->placeholder('Pengguna terhapus')
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('bulan')
                    ->form([
                        Forms\Components\Select::make('bulan')
                            ->label('Bulan Belanja')
                            ->options(self::monthOptions())
                            ->searchable()
                            ->placeholder('Semua bulan'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $month = $data['bulan'] ?? null;

                        if (! is_string($month) || ! preg_match('/^(\d{4})-(\d{2})$/', $month, $matches)) {
                            return $query;
                        }

                        return $query
                            ->whereYear('tanggal', (int) $matches[1])
                            ->whereMonth('tanggal', (int) $matches[2]);
                    }),
                Tables\Filters\Filter::make('periode')
                    ->label('Periode Belanja')
                    ->form([
                        Forms\Components\DatePicker::make('dari')
                            ->label('Dari Tanggal')
                            ->native(false),
                        Forms\Components\DatePicker::make('sampai')
                            ->label('Sampai Tanggal')
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['dari'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('tanggal', '>=', $date))
                        ->when($data['sampai'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('tanggal', '<=', $date))),
                Tables\Filters\Filter::make('supplier')
                    ->form([
                        Forms\Components\Select::make('supplier_id')
                            ->label('Toko / Supplier')
                            ->options(fn (): array => Supplier::query()->orderBy('nama_supplier')
                                ->pluck('nama_supplier', 'id')->all())
                            ->searchable()
                            ->preload(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['supplier_id'] ?? null,
                        fn (Builder $query, mixed $supplierId): Builder => $query->whereHas(
                            'pengeluaran',
                            fn (Builder $query): Builder => $query->where('supplier_id', $supplierId),
                        ),
                    )),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->label('Lihat Detail'),
                    Tables\Actions\EditAction::make()->label('Edit Sesi'),
                    Tables\Actions\DeleteAction::make()
                        ->label('Hapus Sesi')
                        ->requiresConfirmation()
                        ->modalHeading('Hapus sesi belanja?')
                        ->modalDescription('Daftar pengeluaran dan foto nota pada sesi ini ikut dihapus. Data supplier, stok, dan mutasi tidak akan berubah.'),
                ])
                    ->label('Aksi')
                    ->icon('heroicon-m-ellipsis-horizontal'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Belum ada sesi belanja')
            ->emptyStateDescription('Buat sesi untuk menghitung pengeluaran dan sisa uang belanja.')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('Buat Kalkulator Belanja'),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Ringkasan Belanja')
                ->columns(3)
                ->schema([
                    TextEntry::make('tanggal')->label('Tanggal')->date('d F Y'),
                    TextEntry::make('uang_awal')
                        ->label('Uang Awal')
                        ->formatStateUsing(fn (mixed $state): string => self::rupiah((int) $state)),
                    TextEntry::make('total_pengeluaran')
                        ->label('Total Pengeluaran')
                        ->state(fn (KalkulatorBelanja $record): int => $record->total_pengeluaran)
                        ->formatStateUsing(fn (mixed $state): string => self::rupiah((int) $state)),
                    TextEntry::make('sisa_uang')
                        ->label('Sisa Uang')
                        ->state(fn (KalkulatorBelanja $record): int => $record->sisa_uang)
                        ->formatStateUsing(fn (mixed $state): string => self::rupiah((int) $state))
                        ->badge()
                        ->color(fn (KalkulatorBelanja $record): string => $record->sisa_uang < 0 ? 'danger' : 'success'),
                    TextEntry::make('catatan')
                        ->label('Catatan')
                        ->placeholder('-')
                        ->columnSpanFull(),
                ]),
            InfolistSection::make('Pengeluaran per Toko')
                ->schema([
                    RepeatableEntry::make('pengeluaran')
                        ->label('')
                        ->schema([
                            TextEntry::make('supplier_display')
                                ->label('Toko / Supplier')
                                ->state(fn (PengeluaranBelanja $record): string => $record->namaSupplier())
                                ->weight('bold'),
                            TextEntry::make('nominal')
                                ->label('Total Belanja')
                                ->formatStateUsing(fn (mixed $state): string => self::rupiah((int) $state)),
                            TextEntry::make('keterangan')
                                ->label('Keterangan')
                                ->placeholder('-'),
                            ImageEntry::make('foto_nota')
                                ->label('Foto Nota')
                                ->disk('public')
                                ->height(180)
                                ->url(fn (?string $state): ?string => filled($state)
                                    ? Storage::disk('public')->url($state)
                                    : null)
                                ->openUrlInNewTab(),
                        ])
                        ->columns(4),
                ]),
        ]);
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
