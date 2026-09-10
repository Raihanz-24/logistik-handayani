<?php

namespace App\Policies;

use App\Models\KalkulatorBelanja;
use App\Models\User;

class KalkulatorBelanjaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_kalkulator_belanja');
    }

    public function view(User $user, KalkulatorBelanja $record): bool
    {
        return $user->can('view_kalkulator_belanja');
    }

    public function create(User $user): bool
    {
        return $user->can('create_kalkulator_belanja');
    }

    public function update(User $user, KalkulatorBelanja $record): bool
    {
        return $user->can('update_kalkulator_belanja');
    }

    public function delete(User $user, KalkulatorBelanja $record): bool
    {
        return $user->can('delete_kalkulator_belanja');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_kalkulator_belanja');
    }
}
