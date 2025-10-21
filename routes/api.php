<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KategoriController;
use App\Http\Controllers\Api\LokasiController;
use App\Http\Controllers\Api\MutasiController;
use App\Http\Controllers\Api\ProdukController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('produk', ProdukController::class);
    Route::get('/produk/{produk}/mutasi', [ProdukController::class, 'history']);

    Route::apiResource('lokasi', LokasiController::class);

    Route::apiResource('kategori', KategoriController::class);

    Route::apiResource('mutasi', MutasiController::class);
    Route::post('/mutasi/{mutasi}/approve', [MutasiController::class, 'approve']);
    Route::post('/mutasi/{mutasi}/cancel', [MutasiController::class, 'cancel']);

    Route::apiResource('user', UserController::class);
    Route::get('/user/{user}/mutasi', [UserController::class, 'history']);
});
