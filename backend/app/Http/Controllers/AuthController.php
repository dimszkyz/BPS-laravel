<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Peserta;
use App\Models\HasilUjian;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle Admin Login
     */
    public function login(Request $request)
    {
        // 1. Validasi Input (HAPUS '|email' agar username biasa diterima)
        $request->validate([
            'email' => 'required', // Tidak perlu |email, agar string biasa bisa masuk
            'password' => 'required',
        ]);

        // 2. Cari Admin (Cek di kolom email ATAU username)
        $input = $request->email; // Frontend mengirim key 'email', isinya bisa username/email

        $admin = Admin::where(function($query) use ($input) {
                        $query->where('email', $input)
                              ->orWhere('username', $input);
                    })->first();

        // 3. Cek Password & Keberadaan User
        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            return response()->json([
                // Pesan error lebih umum
                'message' => 'Username/Email atau password salah.' 
            ], 401);
        }

        // 4. Cek Status Aktif
        if (!$admin->is_active) {
            return response()->json([
                'message' => 'Akun Anda telah dinonaktifkan.'
            ], 403);
        }

        // 5. Generate Token
        $token = $admin->createToken('admin-token')->plainTextToken;

        // 6. Kirim Response
        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token,
            'admin' => [
                'id' => $admin->id,
                'username' => $admin->username,
                'email' => $admin->email,
                'role' => $admin->role,
            ]
        ]);
    }

    /**
     * Login Peserta (Berdasarkan Email & Kode Login)
     */
    public function loginPeserta(Request $request)
    {
        // 1. Validasi
        $request->validate([
            'email' => 'required|email',
            'login_code' => 'required',
        ]);

        // 2. Cari Peserta
        $peserta = Peserta::where('email', $request->email)->first();

        // 3. Cek Kecocokan Password / Kode Login
        // Menggunakan perbandingan langsung string (sesuai logika kode login)
        if (!$peserta || $peserta->password !== $request->login_code) {
             return response()->json(['message' => 'Email atau Kode Login salah.'], 401);
        }

        // 4. Cari Ujian Aktif untuk Peserta ini (mengambil ujian terakhir yang dikerjakan)
        $activeExamId = HasilUjian::where('peserta_id', $peserta->id)
                        ->latest('created_at')
                        ->value('exam_id'); 

        return response()->json([
            'message' => 'Login berhasil',
            'email' => $peserta->email,
            'examId' => $activeExamId ?? 0,
            'peserta' => $peserta
        ]);
    }

    /**
     * Get Current User Info
     */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    /**
     * Handle Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout berhasil']);
    }
}