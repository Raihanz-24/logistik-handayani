<?php

namespace App\Filament\Resources\MutasiResource\Pages;

use App\Filament\Resources\MutasiResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListMutasis extends ListRecords
{
    protected static string $resource = MutasiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Buat Mutasi'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'Pending' => Tab::make()
                ->label('Pending')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', 'pending')),
            'Approved' => Tab::make()
                ->label('Disetujui')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', 'approved')),

            'Cancelled' => Tab::make()
                ->label('Dibatalkan')
                ->modifyQueryUsing(fn(Builder $query) => $query->where('status', 'cancelled')),
        ];
    }
}
