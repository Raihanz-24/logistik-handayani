<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Services\AuditLogger;
use App\Support\RolePermissionCatalog;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<int, mixed> */
    private array $selectedPermissions = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['name'] ?? null) === 'super_admin') {
            throw ValidationException::withMessages([
                'name' => 'Nama peran super_admin sudah dikhususkan untuk pemilik sistem.',
            ]);
        }

        $this->selectedPermissions = RolePermissionCatalog::flattenGroups($data['permission_groups'] ?? []);

        return [
            ...Arr::only($data, ['name']),
            'guard_name' => 'web',
        ];
    }

    protected function afterCreate(): void
    {
        RolePermissionCatalog::sync($this->getRecord(), $this->selectedPermissions);

        app(AuditLogger::class)->activity(
            'role_create',
            'Membuat peran: '.$this->getRecord()->name,
            auth()->user(),
            ['role_id' => $this->getRecord()->getKey()],
        );
    }
}
