<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LokasiResource\Pages;
use App\Models\Lokasi;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LokasiResource extends Resource
{
    protected static ?string $model = Lokasi::class;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Lokasi';

    protected static ?string $pluralLabel = 'Lokasi';

    protected static ?string $slug = 'lokasi';

    public static function getGloballySearchableAttributes(): array
    {
        return ['kode_lokasi'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Kode lokasi' => $record->kode_lokasi,
            'Jenis' => Lokasi::jenisOptions()[$record->jenis_lokasi] ?? '-',
        ];
    }

    public static function getGlobalSearchResultActions(Model $record): array
    {
        return [
            Action::make('lihat')
                ->url(static::getUrl('edit', ['record' => $record])),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Data Lokasi')
                    ->description('Bedakan gudang penyimpan stok dengan lokasi tempat barang digunakan.')
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('kode_lokasi')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\TextInput::make('nama_lokasi')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('jenis_lokasi')
                            ->label('Jenis Lokasi')
                            ->options(Lokasi::jenisOptions())
                            ->default(Lokasi::JENIS_GUDANG)
                            ->required()
                            ->live()
                            ->native(false)
                            ->helperText('Gudang menyimpan saldo stok. Lokasi Pemakaian hanya menjadi tujuan barang habis pakai.')
                            ->rules([
                                fn (?Lokasi $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                    if (! $record || $value !== Lokasi::JENIS_PEMAKAIAN) {
                                        return;
                                    }

                                    if ($record->barang()->exists() || $record->mutasi()->exists()) {
                                        $fail('Lokasi ini sudah memiliki stok atau riwayat sebagai gudang sehingga jenisnya tidak dapat diubah.');
                                    }
                                },
                            ]),
                        Forms\Components\Toggle::make('menggunakan_rak')
                            ->label('Gunakan Rak')
                            ->helperText('Aktifkan jika gudang memiliki rak bertingkat.')
                            ->default(false)
                            ->live()
                            ->visible(fn (Forms\Get $get): bool => $get('jenis_lokasi') === Lokasi::JENIS_GUDANG)
                            ->afterStateUpdated(function (bool $state, Forms\Set $set): void {
                                if (! $state) {
                                    $set('jumlah_rak', 0);
                                    $set('konfigurasi_rak', []);
                                }
                            }),
                        Forms\Components\TextInput::make('jumlah_rak')
                            ->label('Jumlah Rak')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(50)
                            ->required(fn (Forms\Get $get): bool => (bool) $get('menggunakan_rak'))
                            ->dehydrated(false)
                            ->live(onBlur: true)
                            ->visible(fn (Forms\Get $get): bool => $get('jenis_lokasi') === Lokasi::JENIS_GUDANG
                                && (bool) $get('menggunakan_rak'))
                            ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set): void {
                                $count = max(0, min(50, (int) $state));
                                $current = array_values($get('konfigurasi_rak') ?? []);
                                $configuration = [];

                                for ($index = 0; $index < $count; $index++) {
                                    $configuration[] = [
                                        'nomor_rak' => $index + 1,
                                        'jumlah_tingkat' => (int) ($current[$index]['jumlah_tingkat'] ?? 1),
                                    ];
                                }

                                $set('konfigurasi_rak', $configuration);
                            }),
                        Forms\Components\Repeater::make('konfigurasi_rak')
                            ->label('Konfigurasi Tingkat Setiap Rak')
                            ->schema([
                                Forms\Components\Hidden::make('nomor_rak'),
                                Forms\Components\Placeholder::make('kode_rak')
                                    ->label('Rak')
                                    ->content(fn (Forms\Get $get): string => 'Rak '.($get('nomor_rak') ?: '-')),
                                Forms\Components\TextInput::make('jumlah_tingkat')
                                    ->label('Jumlah Tingkat')
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(50)
                                    ->required()
                                    ->helperText(fn (Forms\Get $get): string => $get('nomor_rak')
                                        ? "Kode otomatis: RK{$get('nomor_rak')}-01 sampai RK{$get('nomor_rak')}-".str_pad((string) ($get('jumlah_tingkat') ?: 1), 2, '0', STR_PAD_LEFT)
                                        : 'Kode dibuat otomatis.'),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->visible(fn (Forms\Get $get): bool => $get('jenis_lokasi') === Lokasi::JENIS_GUDANG
                                && (bool) $get('menggunakan_rak'))
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('alamat')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('keterangan')
                            ->maxLength(255),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', direction: 'desc')
            ->paginationPageOptions([5, 25, 50, 100, 250])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('kode_lokasi')
                    ->limit(30)
                    ->searchable(),
                Tables\Columns\TextColumn::make('nama_lokasi')
                    ->limit(30)
                    ->searchable(),
                Tables\Columns\TextColumn::make('jenis_lokasi')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Lokasi::jenisOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => $state === Lokasi::JENIS_GUDANG ? 'success' : 'info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('rak_summary')
                    ->label('Rak')
                    ->state(fn (Lokasi $record): string => ! $record->menggunakan_rak
                        ? 'Tanpa rak'
                        : $record->raks()->where('aktif', true)->count().' rak')
                    ->badge()
                    ->color(fn (Lokasi $record): string => $record->menggunakan_rak ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('keterangan')
                    ->limit(30)
                    ->searchable(),
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
                Tables\Filters\SelectFilter::make('jenis_lokasi')
                    ->label('Jenis Lokasi')
                    ->options(Lokasi::jenisOptions()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Lokasi $record): bool => ! $record->barang()->exists()
                        && ! $record->mutasi()->exists()
                        && ! $record->mutasiTujuan()->exists())
                    ->modalHeading(fn ($record) => 'Hapus Lokasi: '.$record->kode_lokasi),
            ])
            ->bulkActions([]);
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
            'index' => Pages\ListLokasis::route('/'),
            'create' => Pages\CreateLokasi::route('/buat'),
            'edit' => Pages\EditLokasi::route('/{record}/ubah'),
        ];
    }
}
