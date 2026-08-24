<?php

namespace App\Filament\Resources\LokasiResource\Pages;

use App\Filament\Resources\LokasiResource;
use App\Models\Lokasi;
use App\Services\RakGudangService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditLokasi extends EditRecord
{
    protected static string $resource = LokasiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\DeleteAction::make(),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Ubah Lokasi';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $configuration = $this->record->raks()
            ->where('aktif', true)
            ->orderBy('nomor_rak')
            ->get()
            ->map(fn ($rack): array => [
                'nomor_rak' => $rack->nomor_rak,
                'jumlah_tingkat' => $rack->jumlah_tingkat,
            ])
            ->all();

        $data['jumlah_rak'] = count($configuration);
        $data['konfigurasi_rak'] = $configuration;

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Lokasi {
            /** @var Lokasi $record */
            $configuration = $data['konfigurasi_rak'] ?? [];
            $record->update($data);
            app(RakGudangService::class)->sync($record->fresh(), $configuration);

            return $record;
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
