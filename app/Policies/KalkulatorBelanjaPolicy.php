<?php

namespace App\Policies;

use App\Models\KalkulatorBelanja;
use App\Models\User;

class KalkulatorBelanjaPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, KalkulatorBelanja $record): bool
    {
        return $this->ownsOrSuperAdmin($user, $record);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, KalkulatorBelanja $record): bool
    {
        return $this->ownsOrSuperAdmin($user, $record);
    }

    public function delete(User $user, KalkulatorBelanja $record): bool
    {
        return $this->ownsOrSuperAdmin($user, $record);
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }

    private function ownsOrSuperAdmin(User $user, KalkulatorBelanja $record): bool
    {
        return $user->hasRole('super_admin') || (int) $record->user_id === (int) $user->getKey();
    }
}
