<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Services\AuditLogger;
use App\Support\RolePermissionCatalog;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use TypeError;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * Permission creation changes two related tables. Keep both writes atomic
     * so a failed request can never leave a role without its intended access.
     */
    protected ?bool $hasDatabaseTransactions = true;

    public function create(bool $another = false): void
    {
        try {
            parent::create($another);
        } catch (TypeError $exception) {
            report($exception);
            logger()->error('Submit pembuatan peran gagal karena TypeError.', [
                'exception' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'data.name' => 'Peran belum dapat disimpan. Silakan coba lagi. Data tidak diubah.',
            ]);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['name'] ?? null) === 'super_admin') {
            throw ValidationException::withMessages([
                'name' => 'Nama peran super_admin sudah dikhususkan untuk pemilik sistem.',
            ]);
        }

        return [
            ...Arr::only($data, ['name']),
            'guard_name' => 'web',
        ];
    }

    protected function afterCreate(): void
    {
        RolePermissionCatalog::sync(
            $this->getRecord(),
            RolePermissionCatalog::selectedFromFormData($this->data ?? []),
        );

        app(AuditLogger::class)->activity(
            'role_create',
            'Membuat peran: '.$this->getRecord()->name,
            auth()->user(),
            ['role_id' => $this->getRecord()->getKey()],
        );
    }
}
