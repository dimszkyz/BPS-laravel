<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetRequest;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ForgotPasswordController extends Controller
{
    /**
     * GET /api/admin/forgot-password/requests
     * List semua permintaan reset (Admin Only)
     */
    public function index(Request $request)
    {
        // Fitur ini hanya untuk Superadmin (biasanya)
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $requests = PasswordResetRequest::where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($requests);
    }

    /**
     * POST /api/admin/forgot-password/approve
     * Setujui permintaan reset -> Reset password user jadi default
     */
    public function approve(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'id' => 'required', // ID Request
            'newPassword' => 'required|min:6'
        ]);

        $resetRequest = PasswordResetRequest::find($request->id);
        if (!$resetRequest) {
            return response()->json(['message' => 'Permintaan tidak ditemukan'], 404);
        }

        // Cari admin yang emailnya sesuai request
        $targetAdmin = Admin::where('email', $resetRequest->email)->first();
        
        if ($targetAdmin) {
            // Reset Password
            $targetAdmin->password = Hash::make($request->newPassword);
            $targetAdmin->save();
            
            // Update status request
            $resetRequest->status = 'approved';
            $resetRequest->save();

            return response()->json(['message' => 'Password berhasil direset.']);
        } else {
            return response()->json(['message' => 'Admin dengan email tersebut tidak ditemukan.'], 404);
        }
    }

    /**
     * POST /api/admin/forgot-password/reject
     * Tolak permintaan
     */
    public function reject(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['id' => 'required']);

        $resetRequest = PasswordResetRequest::find($request->id);
        if ($resetRequest) {
            $resetRequest->status = 'rejected';
            $resetRequest->save();
            return response()->json(['message' => 'Permintaan ditolak.']);
        }

        return response()->json(['message' => 'Permintaan tidak ditemukan'], 404);
    }

    /**
     * POST /api/admin/forgot-password
     * User (Non-login) meminta reset password
     * Route: Public
     */
    public function requestReset(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // Cek apakah email terdaftar sebagai admin
        $exists = Admin::where('email', $request->email)->exists();
        if (!$exists) {
            // Demi keamanan, tetap return success agar tidak bisa enumerasi email
            return response()->json(['message' => 'Jika email terdaftar, permintaan akan diproses.']);
        }

        PasswordResetRequest::create([
            'email' => $request->email,
            'status' => 'pending'
        ]);

        return response()->json(['message' => 'Permintaan reset password terkirim. Hubungi Superadmin.']);
    }
}