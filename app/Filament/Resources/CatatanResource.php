<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CatatanResource\Pages;
use App\Models\Barang;
use App\Models\Catatan;
use Filament\Forms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CatatanResource extends Resource
{
    protected static ?string $model = Catatan::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Catatan';

    protected static ?string $navigationLabel = 'Catatan & Belanja';

    protected static ?string $modelLabel = 'Catatan';

    protected static ?string $pluralModelLabel = 'Catatan & Belanja';

    protected static ?string $recordTitleAttribute = 'judul';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user
            ? $query->visibleTo($user)
            : $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Jenis dan Identitas Catatan')
                ->description('Catatan ini berdiri sendiri dan tidak mengubah stok maupun mutasi barang.')
                ->columns(2)
                ->schema([
                    Forms\Components\Radio::make('jenis')
                        ->label('Jenis Catatan')
                        ->options(Catatan::jenisOptions())
                        ->default(Catatan::JENIS_BELANJA)
                        ->inline()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            if ($state === Catatan::JENIS_BIASA) {
                                $set('supplier_id', null);
                            }
                        })
                        ->required(),

                    Forms\Components\DatePicker::make('tanggal')
                        ->label('Tanggal')
                        ->default(today())
                        ->native(false)
                        ->required(),

                    Forms\Components\TextInput::make('judul')
                        ->label('Judul')
                        ->placeholder('Contoh: Belanja kebutuhan gudang')
                        ->maxLength(255)
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\Select::make('supplier_id')
                        ->label('Toko / Supplier')
                        ->relationship(
                            name: 'supplier',
                            titleAttribute: 'nama_supplier',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->orderBy('nama_supplier'),
                        )
                        ->searchable()
                        ->preload()
                        ->placeholder('Opsional, boleh dikosongkan')
                        ->visible(fn (Get $get): bool => $get('jenis') === Catatan::JENIS_BELANJA)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('isi')
                        ->label(fn (Get $get): string => $get('jenis') === Catatan::JENIS_BIASA
                            ? 'Isi Catatan'
                            : 'Catatan Tambahan')
                        ->placeholder('Tulis informasi tambahan di sini...')
                        ->rows(5)
                        ->maxLength(10000)
                        ->required(fn (Get $get): bool => $get('jenis') === Catatan::JENIS_BIASA)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('selesai')
                        ->label('Tandai catatan selesai')
                        ->default(false)
                        ->columnSpanFull(),
                ]),

            Section::make('Daftar Barang yang Akan Dibeli')
                ->description('Barang hanya dipilih sebagai referensi. Stok barang tidak akan berubah.')
                ->visible(fn (Get $get): bool => $get('jenis') === Catatan::JENIS_BELANJA)
                ->schema([
                    Repeater::make('items')
                        ->label('Barang')
                        ->relationship()
                        ->orderColumn('urutan')
                        ->reorderable()
                        ->minItems(1)
                        ->required(fn (Get $get): bool => $get('jenis') === Catatan::JENIS_BELANJA)
                        ->defaultItems(1)
                        ->addActionLabel('Tambah Barang')
                        ->itemLabel(function (array $state): string {
                            if (blank($state['barang_id'] ?? null)) {
                                return 'Barang belum dipilih';
                            }

                            return Barang::query()
                                ->whereKey($state['barang_id'])
                                ->value('nama_barang') ?? 'Barang';
                        })
                        ->schema([
                            Forms\Components\Select::make('barang_id')
                                ->label('Nama Barang')
                                ->relationship('barang', 'nama_barang', fn (Builder $query): Builder => $query->orderBy('nama_barang'))
                                ->getOptionLabelFromRecordUsing(
                                    fn (Barang $record): string => "{$record->kode_barang} - {$record->nama_barang}"
                                )
                                ->searchable(['kode_barang', 'nama_barang'])
                                ->live()
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->required()
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('jumlah')
                                ->label('Jumlah')
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->maxValue(999999)
                                ->default(1)
                                ->suffix(function (Get $get): ?string {
                                    $barangId = $get('barang_id');

                                    return $barangId
                                        ? Barang::query()->whereKey($barangId)->value('satuan')
                                        : null;
                                })
                                ->required(),

                            Forms\Components\Toggle::make('sudah_dibeli')
                                ->label('Sudah dibeli')
                                ->default(false),

                            Forms\Components\TextInput::make('keterangan')
                                ->label('Keterangan')
                                ->placeholder('Contoh: merek atau ukuran tertentu')
                                ->maxLength(255)
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
            ->columns([
                Tables\Columns\TextColumn::make('tanggal')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('judul')
                    ->label('Judul')
                    ->description(fn (Catatan $record): string => Catatan::jenisOptions()[$record->jenis] ?? $record->jenis)
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('supplier_display')
                    ->label('Toko / Supplier')
                    ->state(fn (Catatan $record): ?string => $record->supplier?->nama_supplier
                        ?? $record->nama_supplier_snapshot)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Barang')
                    ->counts('items')
                    ->formatStateUsing(fn (int $state): string => "{$state} barang")
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Dibuat oleh')
                    ->placeholder('Pengguna terhapus')
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false)
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('selesai')
                    ->label('Selesai')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')
                    ->label('Jenis Catatan')
                    ->options(Catatan::jenisOptions()),
                Tables\Filters\TernaryFilter::make('selesai')
                    ->label('Status')
                    ->trueLabel('Sudah selesai')
                    ->falseLabel('Belum selesai'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Buka / Edit'),
                Tables\Actions\DeleteAction::make()
                    ->label('Hapus')
                    ->requiresConfirmation()
                    ->modalDescription('Catatan dan daftar belanjanya akan dihapus. Data barang, supplier, stok, dan mutasi tidak akan berubah.'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Belum ada catatan')
            ->emptyStateDescription('Buat daftar belanja atau catatan biasa pertama Anda.')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()->label('Buat Catatan'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCatatans::route('/'),
            'create' => Pages\CreateCatatan::route('/buat'),
            'edit' => Pages\EditCatatan::route('/{record}/ubah'),
        ];
    }
}
