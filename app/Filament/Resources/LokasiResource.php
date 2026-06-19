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
