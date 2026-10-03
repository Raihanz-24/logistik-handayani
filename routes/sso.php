<?php

use App\Http\Controllers\Sso\SsoCallbackController;
use App\Http\Controllers\Sso\SsoLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SSO — Logistik sebagai aplikasi client (Opsi B)
|--------------------------------------------------------------------------
| - GET /sso/login    : mulai flow (PKCE + state) → Portal /oauth/authorize.
| - GET /sso/callback : terima code+state → tukar token (server-to-server)
|                       → resolusi user → login.
|
| ADDITIVE: route lama (Filament admin, dsb.) TIDAK diubah.
| Tidak ada user_id di URL.
|
| Throttle: batasi agar tidak jadi vektor DoS/amplifikasi (callback memicu
| panggilan keluar ke Portal). Per-IP.
*/

Route::get('/sso/login', SsoLoginController::class)
    ->middleware('throttle:20,1')
    ->name('sso.login');

Route::get('/sso/callback', SsoCallbackController::class)
    ->middleware('throttle:20,1')
    ->name('sso.callback');
