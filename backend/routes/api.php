<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UjianController;
use App\Http\Controllers\SettingsController;

/*
|--------------------------------------------------------------------------
| 1. PUBLIC ROUTES (Bisa diakses tanpa login)
|--------------------------------------------------------------------------
*/

// Login Admin (Sesuai panggilan di LoginAdmin.jsx: /api/admin/login)
Route::post('/admin/login', [AuthController::class, 'login']);

// Login Peserta (Sesuai panggilan di LoginPeserta.jsx: /api/invite/login)
Route::post('/invite/login', [AuthController::class, 'loginPeserta']);

// Pengaturan Public (Logo & Background, sesuai header.jsx)
Route::get('/settings', [SettingsController::class, 'index']);


/*
|--------------------------------------------------------------------------
| 2. PROTECTED ROUTES (Harus Login sebagai Admin)
|--------------------------------------------------------------------------
| Middleware 'auth:sanctum' akan memvalidasi token Bearer.
*/
Route::middleware(['auth:sanctum'])->group(function () {

    // Auth Admin (Cek User & Logout)
    Route::get('/auth/admin/me', [AuthController::class, 'me']);
    Route::post('/auth/admin/logout', [AuthController::class, 'logout']);

    // Ujian Routes (CRUD Ujian)
    Route::get('/ujian', [UjianController::class, 'index']);
    Route::post('/ujian', [UjianController::class, 'store']);
    Route::get('/ujian/{id}', [UjianController::class, 'show']);
    Route::delete('/ujian/{id}', [UjianController::class, 'destroy']);

    // Settings Routes (Update Pengaturan & SMTP)
    Route::post('/settings', [SettingsController::class, 'update']);
    Route::get('/settings/smtp', [SettingsController::class, 'getSmtp']);
    Route::put('/settings/smtp', [SettingsController::class, 'updateSmtp']);

});