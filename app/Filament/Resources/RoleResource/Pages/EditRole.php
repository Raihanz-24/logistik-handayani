<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Services\AuditLogger;
use App\Support\RolePermissionCatalog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var array<int, mixed> */
    private array $selectedPermissions = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permission_groups'] = RolePermissionCatalog::groupedValues(
            $this->getRecord()->permissions()->pluck('name')->all(),
        );

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->selectedPermissions = RolePermissionCatalog::flattenGroups($data['permission_groups'] ?? []);

        if ($this->getRecord()->name === 'super_admin') {
            unset($data['name']);
        }

        return Arr::only($data, ['name']);
    }

    protected function afterSave(): void
    {
        RolePermissionCatalog::sync($this->getRecord(), $this->selectedPermissions);

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
