<?php

namespace App\Filament\Resources\CatatanResource\Pages;

use App\Filament\Resources\CatatanResource;
use App\Models\Catatan;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCatatan extends EditRecord
{
    protected static string $resource = CatatanResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['jenis'] ?? null) === Catatan::JENIS_BIASA) {
            $data['supplier_id'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->getRecord()->jenis === Catatan::JENIS_BIASA) {
            $this->getRecord()->items()->delete();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->modalDescription('Catatan dan daftar belanjanya akan dihapus. Data barang, supplier, stok, dan mutasi tidak akan berubah.'),
        ];
    }
}
