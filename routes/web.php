<?php

use App\Http\Controllers\FotoBarangMediaController;
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

Route::middleware('auth')->prefix('foto-barang-media')->group(function (): void {
    Route::post('/{session}/upload', [FotoBarangMediaController::class, 'store'])
        ->withoutMiddleware(AuditUserActivity::class)
        ->name('foto-barang.upload');
    Route::get('/{session}/foto/{photo}/preview', [FotoBarangMediaController::class, 'preview'])
        ->withoutMiddleware(AuditUserActivity::class)
        ->name('foto-barang.preview');
    Route::get('/{session}/foto/{photo}/download', [FotoBarangMediaController::class, 'download'])
        ->name('foto-barang.download');
    Route::get('/{session}/unduh-semua', [FotoBarangMediaController::class, 'archive'])
        ->name('foto-barang.archive');
});
