<?php

namespace App\Filament\Resources\MutasiRakResource\Pages;

use App\Filament\Resources\MutasiRakResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewMutasiRak extends ViewRecord
{
    protected static string $resource = MutasiRakResource::class;

    public function getTitle(): string|Htmlable
    {
        return "Detail Mutasi Antar Rak #{$this->getRecord()->getKey()}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('kembali')->label('Kembali ke Daftar')
                ->icon('heroicon-o-arrow-left')->color('gray')
                ->url(static::$resource::getUrl('index')),
        ];
    }
}
