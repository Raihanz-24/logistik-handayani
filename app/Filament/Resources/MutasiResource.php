<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MutasiResource\Pages;
use App\Filament\Resources\MutasiResource\RelationManagers;
use App\Models\Mutasi;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

// ===== Tambahan untuk fitur Export =====
use App\Filament\Exports\MutasiExporter;
use Filament\Tables\Actions\ExportAction;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Tables\Filters\Filter;

class MutasiResource extends Resource
{
    protected static ?string $model = Mutasi::class;
    protected static ?string $navigationGroup = 'Master Data';
    protected static ?string $navigationLabel = 'Mutasi';
    protected static ?string $pluralLabel = 'Mutasi';
    protected static ?string $slug = 'mutasi';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Mutasi')
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        Forms\Components\DatePicker::make('tanggal')
                            ->default(now())
                            ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled']))
                            ->required(),
                        Forms\Components\Select::make('jenis_mutasi')
                            ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled']))
                            ->options([
                                'masuk' => 'Masuk',
                                'keluar' => 'Keluar',
                            ])
                            ->native(false)
                            ->required(),
                        Forms\Components\TextInput::make('jumlah')
                            ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled']))
                            ->required()
                            ->numeric(),
                        Forms\Components\TextInput::make('keterangan')
                            ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled']))
                            ->maxLength(255),
                        Forms\Components\TextInput::make('no_ref')
                            ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled']))
                            ->maxLength(255),
                        Forms\Components\Select::make('status')
                            ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled']))
                            ->options([
                                'pending' => 'Pending',
                                'approved' => 'Disetujui',
                                'cancelled' => 'Dibatalkan',
                            ])
                            ->native(false)
                            ->required(),
                        Forms\Components\Select::make('user_id')
                            ->label('Dicatat oleh')
                            ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled']))
                            ->relationship('user', 'name')
                            ->preload()
                            ->native(false)
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('produk_id')
                            ->relationship('produk', 'nama_produk')
                            ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled']))
                            ->preload()
                            ->native(false)
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('lokasi_id')
                            ->relationship('lokasi', 'nama_lokasi')
                            ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled']))
                            ->preload()
                            ->native(false)
                            ->searchable()
                            ->required(),
                        Forms\Components\Select::make('created_by')
                            ->label('Dibuat oleh')
                            ->disabled(fn($record) => in_array($record?->status, ['approved', 'cancelled']))
                            ->relationship('user', 'name')
                            ->preload()
                            ->native(false)
                            ->searchable()
                            ->required(),
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
                Tables\Columns\TextColumn::make('tanggal')
                    ->date()
                    ->sortable()
                    ->dateTime('l, d F Y'),
                Tables\Columns\TextColumn::make('jenis_mutasi'),
                Tables\Columns\TextColumn::make('jumlah')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('keterangan')
                    ->searchable(),
                Tables\Columns\TextColumn::make('no_ref')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->sortable(),
                Tables\Columns\TextColumn::make('produk.nama_produk')
                    ->sortable(),
                Tables\Columns\TextColumn::make('lokasi.nama_lokasi')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Dibuat oleh')
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
                // === Filter rentang tanggal (dipakai tabel & export) ===
                Filter::make('rentang_tanggal')
                    ->label('Rentang Tanggal')
                    ->form([
                        Forms\Components\DatePicker::make('from')
                            ->label('Dari tanggal')
                            ->native(false)
                            ->displayFormat('Y-m-d'),
                        Forms\Components\DatePicker::make('to')
                            ->label('Sampai tanggal')
                            ->native(false)
                            ->displayFormat('Y-m-d'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $from = $data['from'] ?? null;
                        $to   = $data['to'] ?? null;

                        if ($from) {
                            $query->whereDate('tanggal', '>=', $from);
                        }
                        if ($to) {
                            $query->whereDate('tanggal', '<=', $to);
                        }

                        return $query;
                    }),
            ])
            ->headerActions([
                // === Export to Excel (background) ===
                ExportAction::make('export-excel')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->exporter(MutasiExporter::class)
                    ->formats([ExportFormat::Xlsx])
                    ->fileName(fn() => 'mutasi_' . now()->format('Ymd_His')),
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    ExportBulkAction::make('export-selected')
                        ->label('Export Terpilih')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->exporter(MutasiExporter::class)
                        ->formats([ExportFormat::Xlsx])
                        ->fileName(fn() => 'mutasi_terpilih_' . now()->format('Ymd_His')),
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
            'index' => Pages\ListMutasis::route('/'),
            'create' => Pages\CreateMutasi::route('/buat'),
            'edit' => Pages\EditMutasi::route('/{record}/ubah'),
        ];
    }
}
