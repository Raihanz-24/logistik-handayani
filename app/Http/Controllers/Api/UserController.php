<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

class UserController extends BaseApiController
{
    public function index()
    {
        Gate::authorize('viewAny', User::class);

        $users = User::all();

        app(AuditLogger::class)->userCrud('read_list', null, auth()->user(), [
            'result_count' => $users->count(),
        ]);

        return $this->success($users, 'Daftar user');
    }

    public function store(StoreUserRequest $request)
    {
        Gate::authorize('create', User::class);

        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);

        return $this->success($user, 'User berhasil ditambahkan', 201);
    }

    public function show(User $user)
    {
        Gate::authorize('view', $user);
        app(AuditLogger::class)->userCrud('read', $user, auth()->user());

        return $this->success($user, 'Detail user');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        Gate::authorize('update', $user);

        $data = $request->validated();
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $user->update($data);

        return $this->success($user, 'User berhasil diperbarui');
    }

    public function destroy(User $user)
    {
        Gate::authorize('delete', $user);

        $user->delete();

        return $this->success(null, 'User berhasil dihapus');
    }

    public function history(User $user)
    {
        Gate::authorize('view', $user);
        app(AuditLogger::class)->userCrud('read_history', $user, auth()->user());

        $mutasi = $user->mutasi()->with('barang', 'lokasi')->get();

        return $this->success($mutasi, 'Histori mutasi oleh user: '.$user->name);
    }
}
