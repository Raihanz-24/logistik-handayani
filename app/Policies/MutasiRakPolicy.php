<?php

namespace App\Policies;

use App\Models\MutasiRak;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MutasiRakPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_mutasi');
    }

    public function view(User $user, MutasiRak $mutasiRak): bool
    {
        return $user->can('view_mutasi');
    }

    public function create(User $user): bool
    {
        return $user->can('create_mutasi');
    }

    public function update(User $user, MutasiRak $mutasiRak): bool
    {
        return false;
    }

    public function delete(User $user, MutasiRak $mutasiRak): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function approveAny(User $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function approve(User $user, MutasiRak $mutasiRak): bool
    {
        return $this->isSuperAdmin($user) && $mutasiRak->status === MutasiRak::STATUS_PENDING;
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(['super_admin', 'super admin', 'Super Admin', 'super-admin']);
    }
}
