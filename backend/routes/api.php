<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UjianController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\PesertaController;
use App\Http\Controllers\HasilController; // <-- Jangan lupa import ini

/*
|--------------------------------------------------------------------------
| 1. PUBLIC ROUTES (Bisa diakses tanpa login)
|--------------------------------------------------------------------------
*/

// Login Admin (Sesuai panggilan Frontend: /api/admin/login)
Route::post('/admin/login', [AuthController::class, 'login']);

// Login Peserta (Sesuai panggilan Frontend: /api/invite/login)
Route::post('/invite/login', [AuthController::class, 'loginPeserta']);

// Pengaturan Public (Logo & Background, agar bisa dimuat di halaman login)
Route::get('/settings', [SettingsController::class, 'index']);


/*
|--------------------------------------------------------------------------
| 2. PROTECTED ROUTES (Harus Login / Punya Token)
|--------------------------------------------------------------------------
| Middleware 'auth:sanctum' akan memvalidasi token Bearer.
*/
Route::middleware(['auth:sanctum'])->group(function () {

    // --- AUTH ADMIN ---
    Route::get('/auth/admin/me', [AuthController::class, 'me']);
    Route::post('/auth/admin/logout', [AuthController::class, 'logout']);

    // --- MANAJEMEN UJIAN ---
    Route::get('/ujian', [UjianController::class, 'index']);       // List Ujian
    Route::post('/ujian', [UjianController::class, 'store']);      // Buat Ujian Baru
    Route::get('/ujian/{id}', [UjianController::class, 'show']);   // Detail Ujian
    Route::put('/ujian/{id}', [UjianController::class, 'update']); // Edit Ujian
    Route::delete('/ujian/{id}', [UjianController::class, 'destroy']); // Hapus Ujian

    // --- MANAJEMEN PESERTA ---
    Route::get('/peserta', [PesertaController::class, 'index']);       // List Peserta
    Route::post('/peserta', [PesertaController::class, 'store']);      // Tambah Peserta Manual
    Route::get('/peserta/{id}', [PesertaController::class, 'show']);   // Detail Peserta
    Route::put('/peserta/{id}', [PesertaController::class, 'update']); // Edit Peserta
    Route::delete('/peserta/{id}', [PesertaController::class, 'destroy']); // Hapus Peserta
    Route::post('/peserta/import', [PesertaController::class, 'import']); // Import Excel

    // --- HASIL UJIAN (BARU) ---
    Route::post('/hasil/draft', [HasilController::class, 'storeDraft']); // Autosave Jawaban
    Route::post('/hasil', [HasilController::class, 'store']);            // Submit Final
    Route::get('/hasil', [HasilController::class, 'index']);             // Rekap Nilai (Admin)
    Route::get('/hasil/peserta/{peserta_id}', [HasilController::class, 'showByPeserta']); // Detail Jawaban Peserta
    Route::put('/hasil/nilai-manual', [HasilController::class, 'updateNilaiManual']); // Koreksi Manual

    // --- PENGATURAN (SETTINGS) ---
    Route::post('/settings', [SettingsController::class, 'update']);
    Route::get('/settings/smtp', [SettingsController::class, 'getSmtp']);
    Route::put('/settings/smtp', [SettingsController::class, 'updateSmtp']);

});