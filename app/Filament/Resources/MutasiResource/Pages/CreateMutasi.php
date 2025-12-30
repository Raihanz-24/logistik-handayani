<?php

namespace App\Filament\Resources\MutasiResource\Pages;

use App\Filament\Resources\MutasiResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateMutasi extends CreateRecord
{
    protected static string $resource = MutasiResource::class;

    protected function beforeCreate(): void
    {
        $data = $this->form->getState();

        if (($data['jenis_mutasi'] ?? null) !== 'keluar') {
            return;
        }

        $produkId = (int) ($data['produk_id'] ?? 0);
        $lokasiId = (int) ($data['lokasi_id'] ?? 0);
        $jumlah   = (int) ($data['jumlah'] ?? 0);

        if (! $produkId || ! $lokasiId || $jumlah <= 0) {
            return;
        }

        $stok = MutasiResource::getStokTersedia($produkId, $lokasiId);

        if ($jumlah > $stok) {
            Notification::make()
                ->title('Stok tidak mencukupi')
                ->body("Pengajuan tidak bisa disubmit. Stok tersedia (dikurangi pending): {$stok}, diminta: {$jumlah}.")
                ->danger()
                ->send();

            throw new Halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return MutasiResource::getUrl('index');
    }
}
