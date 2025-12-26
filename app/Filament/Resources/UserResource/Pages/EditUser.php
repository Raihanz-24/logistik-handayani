<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Hapus')
                ->requiresConfirmation()
                ->modalHeading(function (): string {
                    return UserResource::cannotDeleteReason($this->getRecord())
                        ? 'Pengguna tidak dapat dihapus'
                        : 'Hapus pengguna';
                })
                ->modalDescription(function (): string {
                    return UserResource::cannotDeleteReason($this->getRecord())
                        ?: 'Apakah Anda yakin ingin menghapus pengguna ini?';
                })
                ->modalSubmitActionLabel(function (): string {
                    return UserResource::cannotDeleteReason($this->getRecord()) ? 'Tutup' : 'Hapus';
                })
                ->modalCancelActionLabel('Batal')
                ->action(function (): void {
                    $record = $this->getRecord();
                    $reason = UserResource::cannotDeleteReason($record);

                    // kalau tidak boleh hapus, cukup tampilkan modal info
                    if ($reason) {
                        return;
                    }

                    $record->delete();
                }),
        ];
    }
}
