<?php

namespace App\Filament\Resources\MutasiResource\Pages;

use App\Filament\Resources\MutasiResource;
use App\Models\Lokasi;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateMutasi extends CreateRecord
{
    protected static string $resource = MutasiResource::class;

    protected function beforeCreate(): void
    {
        $data = $this->form->getState();
        $lokasiId = (int) ($data['lokasi_id'] ?? 0);

        if (! Lokasi::query()->gudang()->whereKey($lokasiId)->exists()) {
            Notification::make()
                ->title('Gudang tidak valid')
                ->body('Mutasi stok hanya dapat menggunakan lokasi bertipe Gudang.')
                ->danger()
                ->send();

            throw new Halt;
        }

        if (($data['jenis_mutasi'] ?? null) !== 'keluar') {
            return;
        }

        $lokasiTujuanId = (int) ($data['lokasi_tujuan_id'] ?? 0);
        if (
            ! $lokasiTujuanId
            || $lokasiTujuanId === $lokasiId
            || ! Lokasi::query()->whereKey($lokasiTujuanId)->exists()
        ) {
            Notification::make()
                ->title('Lokasi tujuan tidak valid')
                ->body('Pilih lokasi tujuan yang berbeda dari gudang asal.')
                ->danger()
                ->send();

            throw new Halt;
        }

        $barangId = (int) ($data['barang_id'] ?? 0);
        $jumlah = (int) ($data['jumlah'] ?? 0);

        if (! $barangId || ! $lokasiId || $jumlah <= 0) {
            return;
        }

        $stok = MutasiResource::getStokTersedia($barangId, $lokasiId);

        if ($jumlah > $stok) {
            Notification::make()
                ->title('Stok tidak mencukupi')
                ->body("Pengajuan tidak bisa disubmit. Stok tersedia (dikurangi pending): {$stok}, diminta: {$jumlah}.")
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return MutasiResource::getUrl('index');
    }
}
