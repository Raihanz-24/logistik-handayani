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
*/

Route::get('/sso/login', SsoLoginController::class)->name('sso.login');
Route::get('/sso/callback', SsoCallbackController::class)->name('sso.callback');
