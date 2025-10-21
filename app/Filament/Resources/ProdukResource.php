<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProdukResource\Pages;
use App\Filament\Resources\ProdukResource\RelationManagers;
use App\Filament\Resources\UserResource\RelationManagers\MutasiRelationManager;
use App\Models\Produk;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;


use Filament\Notifications\Notification;

class ProdukResource extends Resource
{
    protected static ?string $model = Produk::class;
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Produk';
    protected static ?string $pluralLabel = 'Produk';
    protected static ?string $slug = 'produk';

    public static function getGloballySearchableAttributes(): array
    {
        return ['nama_produk'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Nama Produk' => $record->nama_produk,
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
                Section::make('Produk')
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('nama_produk')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('kategoriProduks')
                            ->label('Kategori')
                            ->relationship('kategoriProduks', 'nama')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->createOptionForm([
                                Section::make('Kategori')
                                    ->collapsible()
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('nama')
                                            ->unique(ignoreRecord: true)
                                            ->required()
                                            ->maxLength(255),
                                        Forms\Components\TextInput::make('slug')
                                            ->unique(ignoreRecord: true)
                                            ->required()
                                            ->maxLength(255),
                                    ])
                            ]),
                        Forms\Components\TextInput::make('kode_produk')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('satuan')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('deskripsi')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('harga_beli')
                            ->prefix('Rp')
                            ->required()
                            ->numeric()
                            ->mask(
                                RawJs::make(<<<'JS'
                                    $input => {
                                        let number = $input.replace(/[^\d]/g, '');
                                        if (number === '') return '';
                                        return new Intl.NumberFormat('id-ID').format(Number(number));
                                    }
                                JS)
                            )
                            ->stripCharacters([',', '.']),
                        Forms\Components\TextInput::make('harga_jual')
                            ->prefix('Rp')
                            ->required()
                            ->numeric()
                            ->mask(
                                RawJs::make(<<<'JS'
                                    $input => {
                                        let number = $input.replace(/[^\d]/g, '');
                                        if (number === '') return '';
                                        return new Intl.NumberFormat('id-ID').format(Number(number));
                                    }
                                JS)
                            )
                            ->stripCharacters([',', '.']),
                        Forms\Components\TextInput::make('barcode')
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Forms\Components\FileUpload::make('gambar')
                            ->label('Gambar Produk')
                            ->getUploadedFileNameForStorageUsing(
                                fn(TemporaryUploadedFile $file): string => 'produk-' . $file->hashName()
                            )
                            ->maxSize(2048)
                            ->helperText('Ukuran maksimal 2mb')
                            ->image()
                            ->downloadable()
                            ->openable(),
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', direction: 'desc')
            ->paginationPageOptions([5, 25, 50, 100, 250])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('nama_produk')
                    ->searchable(),
                Tables\Columns\TextColumn::make('kode_produk')
                    ->searchable(),
                Tables\Columns\TextColumn::make('satuan')
                    ->searchable(),
                Tables\Columns\TextColumn::make('harga_beli')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('harga_jual')
                    ->money('IDR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('barcode')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ImageColumn::make('gambar')
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
                //
            ])

            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalHeading(fn($record) => 'Hapus Produk: ' . $record->nama_produk),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MutasiRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProduks::route('/'),
            'create' => Pages\CreateProduk::route('/buat'),
            'edit' => Pages\EditProduk::route('/{record}/ubah'),
        ];
    }
}
