<?php

namespace App\Filament\Resources\MutasiResource\Pages;

use App\Filament\Resources\MutasiResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditMutasi extends EditRecord
{
    protected static string $resource = MutasiResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Ubah Mutasi';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function beforeSave(): void
    {
        if (in_array($this->record->status, ['approved', 'cancelled'])) {
            Notification::make()
                ->title('Tidak dapat mengubah')
                ->body('Mutasi yang sudah disetujui atau dibatalkan tidak bisa diedit.')
                ->danger()
                ->send();

            $this->halt(); // stop simpan
        }

    }

    protected function getHeaderActions(): array
    {
        return [
            // kosongkan tombol delete jika perlu
        ];
    }
}
