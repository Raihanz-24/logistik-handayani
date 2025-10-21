<?php

namespace App\Filament\Resources\KategoriProdukResource\Pages;

use App\Filament\Resources\KategoriProdukResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditKategoriProduk extends EditRecord
{
    protected static string $resource = KategoriProdukResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\DeleteAction::make(),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Ubah Kategori Produk';
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
