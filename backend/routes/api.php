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
// Atau bisa dimasukkan ke middleware sanctum jika peserta login pakai token
// Di sistem lama sepertinya '/public/:id' itu terbuka atau semi-protected.
// Kita taruh luar dulu atau buat middleware khusus peserta nanti.
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

    // Admin Management (Superadmin)// Admin Management (Superadmin)
    Route::get('/admins', [AdminController::class, 'index']); 
    Route::post('/admins', [AdminController::class, 'store']);
    
    // [FIX 404] Tambahkan route ini agar frontend yang memanggil /api/admin-list bisa jalan
    Route::get('/admin-list', [AdminController::class, 'index']); 
    
    // Route untuk update role, username, dan toggle status (sesuai kode DaftarAdmin.jsx)
    Route::put('/admin/update-role/{id}', [AdminController::class, 'updateRole']);
    Route::put('/admin/update-username/{id}', [AdminController::class, 'updateUsername']);
    Route::put('/admin/toggle-status/{id}', [AdminController::class, 'toggleStatus']);
    Route::delete('/admins/{id}', [AdminController::class, 'destroy']);

    // Ujian (Admin)
    Route::get('/ujian', [UjianController::class, 'index']);
    Route::post('/ujian', [UjianController::class, 'store']);
    Route::get('/ujian/{id}', [UjianController::class, 'show']);
    Route::put('/ujian/{id}', [UjianController::class, 'update']);
    Route::delete('/ujian/{id}', [UjianController::class, 'destroy']);

    // Peserta
    Route::get('/peserta', [PesertaController::class, 'index']);
    Route::post('/peserta', [PesertaController::class, 'store']);
    Route::get('/peserta/{id}', [PesertaController::class, 'show']);
    Route::put('/peserta/{id}', [PesertaController::class, 'update']);
    Route::delete('/peserta/{id}', [PesertaController::class, 'destroy']);
    Route::post('/peserta/import', [PesertaController::class, 'import']);

    // Hasil
    Route::post('/hasil/draft', [HasilController::class, 'storeDraft']);
    Route::post('/hasil', [HasilController::class, 'store']);
    Route::get('/hasil', [HasilController::class, 'index']);
    Route::get('/hasil/peserta/{peserta_id}', [HasilController::class, 'showByPeserta']);
    Route::put('/hasil/nilai-manual', [HasilController::class, 'updateNilaiManual']);

    // Settings
    Route::post('/settings', [SettingsController::class, 'update']);
    Route::get('/settings/smtp', [SettingsController::class, 'getSmtp']);
    Route::put('/settings/smtp', [SettingsController::class, 'updateSmtp']);

    // --- INVITE / UNDANGAN ---
    Route::get('/admin/invite-history', [InviteController::class, 'index']); // <--- INI SOLUSINYA
    Route::post('/invite', [InviteController::class, 'sendInvite']);
    Route::delete('/invite/{id}', [InviteController::class, 'destroy']);

    // --- RESET PASSWORD MANAGEMENT (Superadmin) ---
    Route::get('/admin/forgot-password/requests', [ForgotPasswordController::class, 'index']);
    Route::post('/admin/forgot-password/approve', [ForgotPasswordController::class, 'approve']);
    Route::post('/admin/forgot-password/reject', [ForgotPasswordController::class, 'reject']);
});