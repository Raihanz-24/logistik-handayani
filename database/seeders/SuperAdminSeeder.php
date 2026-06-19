<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SuperAdminSeeder extends Seeder
{
    /**
     * Create or update the deployment super administrator.
     */
    public function run(): void
    {
        $name = trim((string) config('deployment.super_admin.name'));
        $email = strtolower(trim((string) config('deployment.super_admin.email')));
        $password = (string) config('deployment.super_admin.password');
        $guard = (string) config('auth.defaults.guard', 'web');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException(
                'SUPER_ADMIN_EMAIL wajib diisi dengan alamat email yang valid.',
            );
        }

        if (mb_strlen($password) < 12) {
            throw new RuntimeException(
                'SUPER_ADMIN_PASSWORD wajib diisi minimal 12 karakter.',
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The default role must exist because UserObserver assigns it to new users.
        Role::findOrCreate('user', $guard);
        $superAdminRole = Role::findOrCreate('super_admin', $guard);

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name !== '' ? $name : 'Super Administrator',
                'password' => Hash::make($password),
            ],
        );

        $user->syncRoles([$superAdminRole]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info("Super admin {$user->email} siap digunakan.");
    }
}
