<?php

use App\Http\Controllers\BackupDownloadController;
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

// Filament Shield's generated role route was replaced with the application-owned
// permission page. Keep old bookmarked Shield URLs from ending in a 404.
Route::redirect('/admin/shield/roles/{legacyPath?}', '/admin/peran')
    ->where('legacyPath', '.*');

Route::middleware('auth')->get('/backup/{backup}/{file}', BackupDownloadController::class)
    ->whereIn('file', ['database', 'files'])
    ->name('backup.download');

Route::middleware('auth')->prefix('foto-barang-media')->group(function (): void {
    Route::post('/{session}/upload', [FotoBarangMediaController::class, 'store'])
        ->withoutMiddleware(AuditUserActivity::class)
        ->name('foto-barang.upload');
    Route::get('/{session}/foto/{photo}/preview', [FotoBarangMediaController::class, 'preview'])
        ->withoutMiddleware(AuditUserActivity::class)
        ->name('foto-barang.preview');
    Route::get('/{session}/foto/{photo}/thumbnail', [FotoBarangMediaController::class, 'thumbnail'])
        ->withoutMiddleware(AuditUserActivity::class)
        ->name('foto-barang.thumbnail');
    Route::get('/{session}/foto/{photo}/download', [FotoBarangMediaController::class, 'download'])
        ->name('foto-barang.download');
    Route::get('/{session}/hasil-edit/{edit}/preview', [FotoBarangMediaController::class, 'previewEdit'])
        ->withoutMiddleware(AuditUserActivity::class)
        ->name('foto-barang.edit-preview');
    Route::get('/{session}/hasil-edit/{edit}/download', [FotoBarangMediaController::class, 'downloadEdit'])
        ->name('foto-barang.edit-download');
    Route::get('/{session}/unduh-semua', [FotoBarangMediaController::class, 'archive'])
        ->name('foto-barang.archive');
    Route::get('/{session}/unduh-terpilih', [FotoBarangMediaController::class, 'archiveSelected'])
        ->name('foto-barang.selected-archive');
});
