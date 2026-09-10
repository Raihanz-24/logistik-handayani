<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Support\RolePermissionCatalog;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Peran & Izin';

    protected static ?string $modelLabel = 'Peran';

    protected static ?string $pluralModelLabel = 'Peran & Izin';

    protected static ?string $slug = 'peran';

    protected static ?int $navigationSort = 850;

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny() && $record->getAttribute('name') !== 'super_admin';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Data Peran')
                ->description('Peran menentukan menu dan aksi yang dapat dipakai pengguna.')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Peran')
                        ->placeholder('Contoh: admin')
                        ->required()
                        ->alphaDash()
                        ->maxLength(100)
                        ->rules([Rule::notIn(['super_admin'])])
                        ->disabled(fn (?Role $record): bool => $record?->name === 'super_admin')
                        ->unique(ignoreRecord: true),
                ]),
            Section::make('Hak Akses')
                ->description('Centang hanya akses yang diperlukan. Izin foto editor sengaja dipisahkan dari Foto Maps.')
                ->schema([
                    Forms\Components\CheckboxList::make('permissions')
                        ->label('Izin untuk peran ini')
                        ->options(fn (): array => RolePermissionCatalog::options())
                        ->columns(['default' => 1, 'md' => 2])
                        ->bulkToggleable()
                        ->searchable()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->recordUrl(fn (Role $record): string => static::getUrl('edit', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Peran')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Jumlah Izin')
                    ->counts('permissions')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Terakhir Diubah')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Atur Izin'),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Role $record): bool => static::canDelete($record)),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/buat'),
            'edit' => Pages\EditRole::route('/{record}/ubah'),
        ];
    }
}
