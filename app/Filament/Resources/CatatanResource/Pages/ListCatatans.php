<?php

namespace App\Filament\Resources\CatatanResource\Pages;

use App\Filament\Resources\CatatanResource;
use App\Models\Catatan;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCatatans extends ListRecords
{
    protected static string $resource = CatatanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Buat Catatan'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'belanja' => Tab::make('Daftar Belanja')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('jenis', Catatan::JENIS_BELANJA)),
            'biasa' => Tab::make('Catatan Biasa')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('jenis', Catatan::JENIS_BIASA)),
            'semua' => Tab::make('Semua'),
        ];
    }
}
