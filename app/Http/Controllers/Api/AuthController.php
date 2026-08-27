<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AuthLoginRequest;
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

        $token = $user->createToken(
            'api-token',
            ['api:access'],
            now()->addHours(8),
        )->plainTextToken;

        app(AuditLogger::class)->authentication($user, 'login_api', $request);

        return $this->success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
        ], 'Login berhasil');

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
