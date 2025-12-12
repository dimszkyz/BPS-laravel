<?php

namespace App\Http\Controllers;

use App\Models\Admin;
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
        // 1. Validasi Input
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // 2. Cari Admin berdasarkan Email
        $admin = Admin::where('email', $request->email)->first();

        // 3. Cek Password & Keberadaan User
        // Hash::check otomatis mencocokkan password input dengan hash bcrypt di DB
        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            return response()->json([
                'message' => 'Email atau password salah.'
            ], 401);
        }

        // 4. Cek Status Aktif
        if (!$admin->is_active) {
            return response()->json([
                'message' => 'Akun Anda telah dinonaktifkan.'
            ], 403);
        }

        // 5. Generate Token (Pengganti jwt.sign)
        // 'admin-token' adalah nama token, bisa apa saja
        $token = $admin->createToken('admin-token')->plainTextToken;

        // 6. Kirim Response
        return response()->json([
            'message' => 'Login berhasil',
            'token' => $token, // Ini yang akan disimpan Frontend di localStorage
            'user' => [
                'id' => $admin->id,
                'username' => $admin->username,
                'email' => $admin->email,
                'role' => $admin->role,
            ]
        ]);
    }

    /**
     * Get Current User Info (Pengganti verifyAdmin middleware check)
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
        // Hapus token yang sedang digunakan saat ini
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout berhasil']);
    }
}