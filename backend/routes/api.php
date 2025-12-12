<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UjianController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\PesertaController; // <-- Jangan lupa import ini

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

    // --- AUTH ADMIN ---
    Route::get('/auth/admin/me', [AuthController::class, 'me']);
    Route::post('/auth/admin/logout', [AuthController::class, 'logout']);

    // --- UJIAN ROUTES ---
    Route::get('/ujian', [UjianController::class, 'index']);       // List Ujian
    Route::post('/ujian', [UjianController::class, 'store']);      // Buat Baru
    Route::get('/ujian/{id}', [UjianController::class, 'show']);   // Detail Ujian
    Route::put('/ujian/{id}', [UjianController::class, 'update']); // Edit Ujian (BARU)
    Route::delete('/ujian/{id}', [UjianController::class, 'destroy']); // Hapus (Soft Delete)

    // --- PESERTA ROUTES (BARU) ---
    Route::get('/peserta', [PesertaController::class, 'index']);       // List Peserta
    Route::post('/peserta', [PesertaController::class, 'store']);      // Tambah Manual
    Route::get('/peserta/{id}', [PesertaController::class, 'show']);   // Detail Peserta
    Route::put('/peserta/{id}', [PesertaController::class, 'update']); // Edit Peserta
    Route::delete('/peserta/{id}', [PesertaController::class, 'destroy']); // Hapus Peserta
    
    // Route khusus Import Excel
    Route::post('/peserta/import', [PesertaController::class, 'import']);

    // --- SETTINGS ROUTES ---
    Route::post('/settings', [SettingsController::class, 'update']);
    Route::get('/settings/smtp', [SettingsController::class, 'getSmtp']);
    Route::put('/settings/smtp', [SettingsController::class, 'updateSmtp']);

});