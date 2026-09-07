<?php

namespace App\Filament\Resources\KalkulatorBelanjaResource\Pages;

use App\Filament\Resources\KalkulatorBelanjaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditKalkulatorBelanja extends EditRecord
{
    protected static string $resource = KalkulatorBelanjaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()->label('Lihat Detail'),
            Actions\DeleteAction::make()
                ->modalHeading('Hapus sesi belanja?')
                ->modalDescription('Daftar pengeluaran dan foto nota ikut dihapus. Data supplier, stok, dan mutasi tidak akan berubah.'),
        ];
    }
}
