<?php

namespace App\Filament\Resources\CatatanResource\RelationManagers;

use App\Filament\Resources\CatatanResource;
use App\Models\Catatan;
use App\Models\CatatanItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Daftar Barang yang Akan Dibeli';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Catatan
            && $ownerRecord->jenis === Catatan::JENIS_BELANJA
            && CatatanResource::canEdit($ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Centang barang yang sudah dibeli')
            ->description('Centang dapat diubah langsung tanpa membuka halaman edit.')
            ->columns([
                Tables\Columns\CheckboxColumn::make('sudah_dibeli')
                    ->label('Sudah Dibeli')
                    ->afterStateUpdated(function (CatatanItem $record): void {
                        $catatan = $record->catatan;

                        $catatan->update([
                            'selesai' => ! $catatan->items()->where('sudah_dibeli', false)->exists(),
                        ]);
                    }),
                Tables\Columns\TextColumn::make('nama_barang_snapshot')
                    ->label('Nama Barang')
                    ->state(fn (CatatanItem $record): string => $record->namaBarang())
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('jumlah')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (CatatanItem $record): string => trim(
                        number_format($record->jumlah, 0, ',', '.').' '.($record->satuan_snapshot ?? '')
                    )),
                Tables\Columns\TextColumn::make('keterangan')
                    ->label('Keterangan')
                    ->placeholder('-')
                    ->wrap(),
            ])
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated(false)
            ->emptyStateHeading('Belum ada barang dalam daftar');
    }
}
