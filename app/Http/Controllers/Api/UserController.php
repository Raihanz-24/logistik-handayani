<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends BaseApiController
{
    public function index()
    {
        $users = User::all();
        return $this->success($users, 'Daftar user');
    }

    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        return $this->success($user, 'User berhasil ditambahkan', 201);
    }

    public function show(User $user)
    {
        return $this->success($user, 'Detail user');
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        $user->update($data);
        return $this->success($user, 'User berhasil diperbarui');
    }

    public function destroy(User $user)
    {
        $user->delete();
        return $this->success(null, 'User berhasil dihapus');
    }

    public function history(User $user)
    {
        $mutasi = $user->mutasi()->with('produk', 'lokasi')->get();
        return $this->success($mutasi, 'Histori mutasi oleh user: ' . $user->name);
    }
}
