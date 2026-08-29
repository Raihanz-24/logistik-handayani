<?php

namespace App\Policies;

use App\Models\Catatan;
use App\Models\User;

class CatatanPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Catatan $catatan): bool
    {
        return $this->ownsOrSuperAdmin($user, $catatan);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Catatan $catatan): bool
    {
        return $this->ownsOrSuperAdmin($user, $catatan);
    }

    public function delete(User $user, Catatan $catatan): bool
    {
        return $this->ownsOrSuperAdmin($user, $catatan);
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    private function ownsOrSuperAdmin(User $user, Catatan $catatan): bool
    {
        return $user->hasRole('super_admin') || (int) $catatan->user_id === (int) $user->getKey();
    }
}
