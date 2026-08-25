<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Supplier';

    protected static ?string $pluralLabel = 'Supplier';

    protected static ?string $slug = 'supplier';

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return auth()->check();
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->check();
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->check() && ! $record->mutasis()->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Data Supplier')->columns(2)->schema([
                Forms\Components\TextInput::make('nama_supplier')
                    ->label('Nama Supplier')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\TextInput::make('kontak_person')
                    ->label('Kontak Person')
                    ->maxLength(255),
                Forms\Components\TextInput::make('telepon')
                    ->label('No. Telepon')
                    ->tel()
                    ->maxLength(50),
                Forms\Components\Toggle::make('aktif')
                    ->label('Aktif')
                    ->default(true),
                Forms\Components\Textarea::make('alamat')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nama_supplier')
            ->columns([
                Tables\Columns\TextColumn::make('nama_supplier')->label('Nama Supplier')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('kontak_person')->label('Kontak Person')->searchable()->placeholder('-'),
                Tables\Columns\TextColumn::make('telepon')->label('No. Telepon')->searchable()->placeholder('-'),
                Tables\Columns\IconColumn::make('aktif')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('mutasis_count')->label('Jumlah Mutasi')->counts('mutasis')->numeric(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Supplier $record): bool => ! $record->mutasis()->exists()),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/buat'),
            'edit' => Pages\EditSupplier::route('/{record}/ubah'),
        ];
    }
}
