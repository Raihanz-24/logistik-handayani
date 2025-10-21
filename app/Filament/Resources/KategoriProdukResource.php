<?php

namespace App\Filament\Resources;

use App\Filament\Resources\KategoriProdukResource\Pages;
use App\Filament\Resources\KategoriProdukResource\RelationManagers;
use App\Models\KategoriProduk;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class KategoriProdukResource extends Resource
{
    protected static ?string $model = KategoriProduk::class;
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Kategori Produk';
    protected static ?string $pluralLabel = 'Kategori Produk';
    protected static ?string $slug = 'kategori-produk';

    public static function getGloballySearchableAttributes(): array
    {
        return ['nama'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Kategori' => $record->nama,
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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', direction: 'desc')
            ->paginationPageOptions([5, 25, 50, 100, 250])
            ->defaultPaginationPageOption(5)
            ->columns([
                Tables\Columns\TextColumn::make('nama')
                    ->limit(30)
                    ->searchable(),
                Tables\Columns\TextColumn::make('slug')
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
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalHeading(fn($record) => 'Hapus Kategori: ' . $record->nama),

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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKategoriProduks::route('/'),
            'create' => Pages\CreateKategoriProduk::route('/buat'),
            'edit' => Pages\EditKategoriProduk::route('/{record}/ubah'),
        ];
    }
}
