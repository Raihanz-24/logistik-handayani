<?php

namespace App\Filament\Resources\MutasiResource\Pages;

use App\Filament\Resources\MutasiResource;
use App\Models\Lokasi;
use App\Models\Produk;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateMutasi extends CreateRecord
{
    protected static string $resource = MutasiResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Buat Mutasi';
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function beforeCreate(): void
    {
        if ($this->data['status'] !== 'approved')
            return;

        $produkId = $this->data['produk_id'];
        $lokasiId = $this->data['lokasi_id'];
        $jumlah = $this->data['jumlah'];
        $jenis = $this->data['jenis_mutasi'];

        $produk = Produk::find($produkId);
        $lokasi = Lokasi::find($lokasiId);

        $stokSekarang = $produk->lokasi()->where('lokasi_id', $lokasi->id)->first()?->pivot->stok ?? 0;

        $stokBaru = $jenis === 'masuk'
            ? $stokSekarang + $jumlah
            : $stokSekarang - $jumlah;

        if ($stokBaru < 0) {
            Notification::make()
                ->title('Gagal menyimpan')
                ->body('Stok tidak cukup untuk disetujui.')
                ->danger()
                ->send();

            $this->halt(); // menghentikan proses create
        }

        $produk->lokasi()->syncWithoutDetaching([
            $lokasi->id => ['stok' => $stokBaru],
        ]);
    }
}
