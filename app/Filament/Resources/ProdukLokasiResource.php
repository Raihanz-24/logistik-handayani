<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProdukLokasiResource\Pages;
use App\Filament\Resources\ProdukLokasiResource\RelationManagers;
use App\Models\ProdukLokasi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;



class ProdukLokasiResource extends Resource
{
    protected static ?string $model = ProdukLokasi::class;
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Stok Produk';
    protected static ?string $pluralLabel = 'Stok Produk';
    protected static ?string $slug = 'stok-produk';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('produk_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('lokasi_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('stok')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table

            ->paginationPageOptions([5, 25, 50, 100, 250])
            ->defaultPaginationPageOption(5)
            ->defaultSort('produk_id', direction: 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('produk.nama_produk')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('lokasi.nama_lokasi')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('stok')
                    ->searchable()
                    ->numeric()
                    ->sortable(),
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
            'index' => Pages\ListProdukLokasis::route('/'),
            // 'create' => Pages\CreateProdukLokasi::route('/create'),
            // 'edit' => Pages\EditProdukLokasi::route('/{record}/edit'),
        ];
    }
}
