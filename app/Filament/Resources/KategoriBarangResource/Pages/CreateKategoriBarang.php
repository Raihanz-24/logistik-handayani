<?php

namespace App\Filament\Resources\KategoriBarangResource\Pages;

use App\Filament\Resources\KategoriBarangResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

class CreateKategoriBarang extends CreateRecord
{
    protected static string $resource = KategoriBarangResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Buat Kategori Barang';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
