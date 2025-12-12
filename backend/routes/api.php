<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UjianController;
use App\Http\Controllers\SettingsController; // <-- Tambahkan Import

/*
|--------------------------------------------------------------------------
| 1. PUBLIC ROUTES (Bisa diakses tanpa login)
|--------------------------------------------------------------------------
*/

Route::post('/auth/admin/login', [AuthController::class, 'login']);

// Route Settings Public (Agar header & background bisa loading)
Route::get('/settings', [SettingsController::class, 'index']);


/*
|--------------------------------------------------------------------------
| 2. PROTECTED ROUTES (Harus Login sebagai Admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/auth/admin/me', [AuthController::class, 'me']);
    Route::post('/auth/admin/logout', [AuthController::class, 'logout']);

    // Ujian Routes
    Route::get('/ujian', [UjianController::class, 'index']);
    Route::post('/ujian', [UjianController::class, 'store']);
    Route::get('/ujian/{id}', [UjianController::class, 'show']);
    Route::delete('/ujian/{id}', [UjianController::class, 'destroy']);

    // Settings Routes (Update)
    Route::post('/settings', [SettingsController::class, 'update']);
    Route::get('/settings/smtp', [SettingsController::class, 'getSmtp']);
    Route::put('/settings/smtp', [SettingsController::class, 'updateSmtp']);

});