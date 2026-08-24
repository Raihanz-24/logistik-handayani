<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BarangResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers\MutasiRelationManager;
use App\Models\Barang;
use App\Services\BarangImageService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class BarangResource extends Resource
{
    protected static ?string $model = Barang::class;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Barang';

    protected static ?string $pluralLabel = 'Barang';

    protected static ?string $slug = 'barang';

    public static function getGloballySearchableAttributes(): array
    {
        return ['nama_barang'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Nama Barang' => $record->nama_barang,
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
                Section::make('Barang')
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('nama_barang')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('kategoriBarangs')
                            ->label('Kategori')
                            ->relationship('kategoriBarangs', 'nama')
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
                                    ]),
                            ]),
                        Forms\Components\Placeholder::make('kode_barang_preview')
                            ->label('Kode Barang')
                            ->content(fn (?Barang $record): string => $record?->kode_barang ?? 'Dibuat otomatis saat barang disimpan')
                            ->helperText('Format otomatis: BRG-001, BRG-002, dan seterusnya.'),
                        Forms\Components\TextInput::make('satuan')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('deskripsi')
                            ->columnSpanFull(),
                        FileUpload::make('gambar')
                            ->label('Gambar Barang')
                            ->helperText('Opsional. Maksimal 3 MB. Gambar otomatis dikompres dan disimpan sebagai WebP.')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(3072)
                            ->disk('public')
                            ->directory('barang')
                            ->visibility('public')
                            ->imagePreviewHeight('220')
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('2000')
                            ->imageResizeTargetHeight('2000')
                            ->imageResizeUpscale(false)
                            ->openable()
                            ->downloadable()
                            ->saveUploadedFileUsing(
                                fn (TemporaryUploadedFile $file): string => app(BarangImageService::class)->store($file),
                            )
                            ->deleteUploadedFileUsing(
                                fn (string $file): bool => Storage::disk('public')->delete($file),
                            )
                            ->columnSpanFull(),
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
                Tables\Columns\ImageColumn::make('gambar')
                    ->label('Gambar')
                    ->disk('public')
                    ->height(48)
                    ->width(48)
                    ->square(),
                Tables\Columns\TextColumn::make('nama_barang')
                    ->searchable(),
                Tables\Columns\TextColumn::make('kode_barang')
                    ->searchable(),
                Tables\Columns\TextColumn::make('satuan')
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
                    ->modalHeading(fn ($record) => 'Hapus Barang: '.$record->nama_barang),
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
            'index' => Pages\ListBarangs::route('/'),
            'create' => Pages\CreateBarang::route('/buat'),
            'edit' => Pages\EditBarang::route('/{record}/ubah'),
        ];
    }
}
