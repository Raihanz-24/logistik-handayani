<?php

namespace App\Filament\Resources\KategoriProdukResource\Pages;

use App\Filament\Resources\KategoriProdukResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateKategoriProduk extends CreateRecord
{
    protected static string $resource = KategoriProdukResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Buat Kategori Produk';
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
