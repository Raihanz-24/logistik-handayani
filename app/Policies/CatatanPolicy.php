<?php

namespace App\Policies;

use App\Models\Catatan;
use App\Models\User;

class CatatanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_catatan');
    }

    public function view(User $user, Catatan $catatan): bool
    {
        return $user->can('view_catatan');
    }

    public function create(User $user): bool
    {
        return $user->can('create_catatan');
    }

    public function update(User $user, Catatan $catatan): bool
    {
        return $user->can('update_catatan');
    }

    public function delete(User $user, Catatan $catatan): bool
    {
        return $user->can('delete_catatan');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_catatan');
    }
}
