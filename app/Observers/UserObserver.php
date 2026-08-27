<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserObserver
{
    private const DEFAULT_ROLE = 'user';

    /**
     * Handle the User "created" event.
     */
    public function created(User $user): void
    {
        if (! Schema::hasTable(config('permission.table_names.roles', 'roles'))) {
            return;
        }

        $guard = config('auth.defaults.guard', 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate(self::DEFAULT_ROLE, $guard);

        if (! $user->hasAnyRole()) {
            $user->assignRole(self::DEFAULT_ROLE);
        }

        app(AuditLogger::class)->userCrud('create', $user, auth()->user(), [
            'roles' => $user->getRoleNames()->values()->all(),
        ]);
    }

    /**
     * Handle the User "updated" event.
     */
    public function updated(User $user): void
    {
        $changedFields = array_values(array_intersect(
            array_keys($user->getChanges()),
            ['name', 'username', 'email', 'password'],
        ));

        if ($changedFields === []) {
            return;
        }

        app(AuditLogger::class)->userCrud('update', $user, auth()->user(), [
            'changed_fields' => $changedFields,
        ]);
    }

    /**
     * Handle the User "deleted" event.
     */
    public function deleted(User $user): void
    {
        app(AuditLogger::class)->userCrud('delete', $user, auth()->user(), [
            'roles' => $user->getRoleNames()->values()->all(),
        ]);
    }

    /**
     * Handle the User "restored" event.
     */
    public function restored(User $user): void
    {
        //
    }

    /**
     * Handle the User "force deleted" event.
     */
    public function forceDeleted(User $user): void
    {
        //
    }
}
