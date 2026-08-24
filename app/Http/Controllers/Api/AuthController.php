<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AuthLoginRequest;
use App\Http\Requests\StoreUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseApiController
{
    public function login(AuthLoginRequest $request)
    {
        $data = $request->validated();

        $user = User::where('username', strtolower(trim($data['username'])))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Login gagal'], 401);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        app(AuditLogger::class)->authentication($user, 'login_api', $request);

        return $this->success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 'Login berhasil');

    }

    public function register(StoreUserRequest $request)
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);

        return $this->success($user, 'Registrasi berhasil', 201);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil',
        ]);
    }
}
