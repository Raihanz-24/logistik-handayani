<?php

use App\Http\Controllers\PublicStorageController;
use App\Http\Middleware\AuditUserActivity;
use Illuminate\Support\Facades\Route;

Route::get('/media/{path}', PublicStorageController::class)
    ->where('path', '.*')
    ->withoutMiddleware(AuditUserActivity::class)
    ->name('media.show');

Route::get('/', function () {
    return redirect(route('filament.admin.pages.dashboard'));
});
