<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BarangLokasiResource\Pages;
use App\Models\BarangLokasi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
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
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table

            ->paginationPageOptions([5, 25, 50, 100, 250])
            ->defaultPaginationPageOption(5)
            ->defaultSort('barang_id', direction: 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('barang.nama_barang')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('lokasi.nama_lokasi')
                    ->label('Gudang')
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
            'index' => Pages\ListBarangLokasis::route('/'),
            // 'create' => Pages\CreateBarangLokasi::route('/create'),
            // 'edit' => Pages\EditBarangLokasi::route('/{record}/edit'),
        ];
    }
}
