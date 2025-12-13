<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UjianController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\PesertaController;
use App\Http\Controllers\HasilController;
use App\Http\Controllers\AdminController; 
use App\Http\Controllers\InviteController;
use App\Http\Controllers\ForgotPasswordController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/
Route::post('/admin/login', [AuthController::class, 'login']);
Route::post('/invite/login', [InviteController::class, 'login']);
Route::get('/settings', [SettingsController::class, 'index']);

// Public Ujian Routes (Untuk Peserta yang sudah login di frontend tapi fetch data publik)
Route::get('/ujian/check-active/{id}', [UjianController::class, 'checkActive']);
Route::get('/ujian/public/{id}', [UjianController::class, 'showPublic']);
Route::post('/admin/forgot-password', [ForgotPasswordController::class, 'requestReset']);


/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES (ADMIN)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {

    Route::get('/auth/admin/me', [AuthController::class, 'me']);
    Route::post('/auth/admin/logout', [AuthController::class, 'logout']);
    
    // Ping Sidebar
    Route::get('/admin/ping', [AdminController::class, 'ping']);

    // --- ADMIN MANAGEMENT (Superadmin) ---
    // Route standar RESTful
    Route::get('/admins', [AdminController::class, 'index']); 
    Route::post('/admins', [AdminController::class, 'store']);
    
    // [FIX 1] Alias untuk "Tambah Admin" (Frontend memanggil /api/admin/register)
    Route::post('/admin/register', [AdminController::class, 'store']);

    // [FIX 2] Alias untuk "Daftar Admin" (Frontend memanggil /api/admin/invite-history)
    // Note: Di Node.js 'invite-history' pada authAdmin.js mengembalikan list admin.
    Route::get('/admin/invite-history', [AdminController::class, 'index']);

    // [FIX 3] Route legacy/alias lain agar frontend admin-list tetap jalan
    Route::get('/admin-list', [AdminController::class, 'index']); 
    
    // Route untuk update role, username, dan toggle status
    Route::put('/admin/update-role/{id}', [AdminController::class, 'updateRole']);
    Route::put('/admin/update-username/{id}', [AdminController::class, 'updateUsername']);
    Route::put('/admin/toggle-status/{id}', [AdminController::class, 'toggleStatus']);
    
    // Delete Admin
    Route::delete('/admins/{id}', [AdminController::class, 'destroy']);
    Route::delete('/admin/delete/{id}', [AdminController::class, 'destroy']); // Alias

    // --- UJIAN (Admin) ---
    Route::get('/ujian', [UjianController::class, 'index']);
    Route::post('/ujian', [UjianController::class, 'store']);
    Route::get('/ujian/{id}', [UjianController::class, 'show']);
    Route::put('/ujian/{id}', [UjianController::class, 'update']);
    Route::delete('/ujian/{id}', [UjianController::class, 'destroy']);

    // --- PESERTA ---
    Route::get('/peserta', [PesertaController::class, 'index']);
    Route::post('/peserta', [PesertaController::class, 'store']);
    Route::get('/peserta/{id}', [PesertaController::class, 'show']);
    Route::put('/peserta/{id}', [PesertaController::class, 'update']);
    Route::delete('/peserta/{id}', [PesertaController::class, 'destroy']);
    Route::post('/peserta/import', [PesertaController::class, 'import']);

    // --- HASIL UJIAN ---
    Route::post('/hasil/draft', [HasilController::class, 'storeDraft']);
    Route::post('/hasil', [HasilController::class, 'store']);
    Route::get('/hasil', [HasilController::class, 'index']);
    Route::get('/hasil/peserta/{peserta_id}', [HasilController::class, 'showByPeserta']);
    Route::put('/hasil/nilai-manual', [HasilController::class, 'updateNilaiManual']);

    // --- SETTINGS & EMAIL ---
    Route::post('/settings', [SettingsController::class, 'update']);
    // [FIX 4] Route Email SMTP sesuai frontend React
    Route::get('/email/smtp', [SettingsController::class, 'getSmtp']);
    Route::put('/email/smtp', [SettingsController::class, 'updateSmtp']);

    // --- INVITE / UNDANGAN PESERTA ---
    // [FIX 5] Route Invite List sesuai frontend React (/api/invite/list)
    Route::get('/invite/list', [InviteController::class, 'index']);
    Route::post('/invite', [InviteController::class, 'sendInvite']);
    Route::delete('/invite/{id}', [InviteController::class, 'destroy']);

    // --- RESET PASSWORD MANAGEMENT (Superadmin) ---
    Route::get('/admin/forgot-password/requests', [ForgotPasswordController::class, 'index']);
    Route::post('/admin/forgot-password/approve', [ForgotPasswordController::class, 'approve']);
    Route::post('/admin/forgot-password/reject', [ForgotPasswordController::class, 'reject']);
});