<?php

namespace App\Filament\Resources\MutasiRakResource\Pages;

use App\Filament\Resources\MutasiRakResource;
use App\Models\MutasiRak;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListMutasiRaks extends ListRecords
{
    protected static string $resource = MutasiRakResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Buat Mutasi Antar Rak'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->where('status', MutasiRak::STATUS_PENDING),
            ),
            'approved' => Tab::make('Disetujui')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->where('status', MutasiRak::STATUS_APPROVED),
            ),
            'cancelled' => Tab::make('Dibatalkan')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->where('status', MutasiRak::STATUS_CANCELLED),
            ),
        ];
    }
}
