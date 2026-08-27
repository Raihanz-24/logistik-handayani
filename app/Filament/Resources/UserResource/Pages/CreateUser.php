<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->userCrud('roles_update', $this->getRecord(), auth()->user(), [
            'roles' => $this->getRecord()->getRoleNames()->values()->all(),
        ]);
    }
}
