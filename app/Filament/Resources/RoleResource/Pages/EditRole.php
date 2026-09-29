<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Services\AuditLogger;
use App\Support\RolePermissionCatalog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use TypeError;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** See CreateRole: role and permission changes must be one transaction. */
    protected ?bool $hasDatabaseTransactions = true;

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        try {
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        } catch (TypeError $exception) {
            report($exception);
            logger()->error('Submit perubahan peran gagal karena TypeError.', [
                'exception' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'data.name' => 'Perubahan izin belum dapat disimpan. Silakan coba lagi. Data tidak diubah.',
            ]);
        }
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permission_groups'] = RolePermissionCatalog::groupedValues(
            $this->getRecord()->permissions()->pluck('name')->all(),
        );

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->getRecord()->name === 'super_admin') {
            unset($data['name']);
        }

        return Arr::only($data, ['name']);
    }

    protected function afterSave(): void
    {
        RolePermissionCatalog::sync(
            $this->getRecord(),
            RolePermissionCatalog::selectedFromFormData($this->data ?? []),
        );

        app(AuditLogger::class)->activity(
            'role_update',
            'Memperbarui peran dan izin: '.$this->getRecord()->name,
            auth()->user(),
            ['role_id' => $this->getRecord()->getKey()],
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => RoleResource::canDelete($this->getRecord())),
        ];
    }
}
