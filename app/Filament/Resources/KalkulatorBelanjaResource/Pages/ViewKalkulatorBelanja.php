<?php

namespace App\Filament\Resources\KalkulatorBelanjaResource\Pages;

use App\Filament\Resources\KalkulatorBelanjaResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

class ViewKalkulatorBelanja extends ViewRecord
{
    protected static string $resource = KalkulatorBelanjaResource::class;

    #[On('belanja-updated')]
    public function refreshBelanjaSummary(): void
    {
        if ($fresh = $this->record->fresh()) {
            $this->record = $fresh;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Edit Sesi'),
            Actions\DeleteAction::make()
                ->modalHeading('Hapus sesi belanja?')
                ->modalDescription('Daftar pengeluaran dan foto nota ikut dihapus. Data supplier, stok, dan mutasi tidak akan berubah.'),
        ];
    }
}
