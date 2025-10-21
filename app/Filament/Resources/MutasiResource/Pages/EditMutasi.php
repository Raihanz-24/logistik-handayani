<?php

namespace App\Filament\Resources\MutasiResource\Pages;

use App\Filament\Resources\MutasiResource;
use App\Models\Lokasi;
use App\Models\Produk;
use Filament\Actions;
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

        if ($this->data['status'] === 'approved') {
            $produkId = $this->data['produk_id'];
            $lokasiId = $this->data['lokasi_id'];
            $jumlah = $this->data['jumlah'];
            $jenis = $this->data['jenis_mutasi'];

            $produk = Produk::find($produkId);
            $lokasi = Lokasi::find($lokasiId);

            $stokSekarang = $produk->lokasi()->where('lokasi_id', $lokasiId)->first()?->pivot->stok ?? 0;

            $stokBaru = $jenis === 'masuk'
                ? $stokSekarang + $jumlah
                : $stokSekarang - $jumlah;

            if ($stokBaru < 0) {
                Notification::make()
                    ->title('Gagal menyimpan')
                    ->body('Stok tidak cukup untuk disetujui.')
                    ->danger()
                    ->send();

                $this->halt(); // hentikan simpan
            }

            $produk->lokasi()->syncWithoutDetaching([
                $lokasi->id => ['stok' => $stokBaru],
            ]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            // kosongkan tombol delete jika perlu
        ];
    }
}
