<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetRequest;
use App\Models\Admin;
use App\Models\SmtpSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ForgotPasswordController extends Controller
{
    private function setupMailer($userId)
    {
        try {
            $smtp = SmtpSetting::where('user_id', $userId)->first(); 

            if (!$smtp || empty($smtp->auth_user) || empty($smtp->auth_pass) || empty($smtp->host)) {
                return false;
            }

            // [PERBAIKAN DISINI] 
            // Jangan gunakan $smtp->service (karena isinya 'gmail'), tapi paksa 'smtp'
            Config::set('mail.mailers.smtp.transport', 'smtp'); 
            
            Config::set('mail.mailers.smtp.host', $smtp->host);
            Config::set('mail.mailers.smtp.port', $smtp->port);
            
            $encryption = $smtp->port == 465 ? 'ssl' : 'tls';
            Config::set('mail.mailers.smtp.encryption', $encryption);
            
            Config::set('mail.mailers.smtp.username', $smtp->auth_user);
            Config::set('mail.mailers.smtp.password', $smtp->auth_pass);
            Config::set('mail.from.address', $smtp->auth_user);
            Config::set('mail.from.name', $smtp->from_name ?? 'Admin Sistem');

            app()->forgetInstance('mailer');
            Mail::clearResolvedInstances();
            
            return true;
        } catch (\Throwable $e) {
            Log::error("Setup Mailer Error: " . $e->getMessage());
            return false;
        }
    }

    public function index(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $requests = PasswordResetRequest::orderBy('created_at', 'desc')->get();
        return response()->json($requests);
    }

    public function approve(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'id' => 'required',
            'newPassword' => 'required|min:6'
        ]);

        // Cek SMTP dulu
        if (!$this->setupMailer($request->user()->id)) {
            return response()->json([
                'message' => 'Gagal! Konfigurasi Email belum disetting. Silakan ke menu Pengaturan Email.'
            ], 400);
        }

        DB::beginTransaction();

        try {
            $resetRequest = PasswordResetRequest::find($request->id);
            if (!$resetRequest) {
                return response()->json(['message' => 'Permintaan tidak ditemukan'], 404);
            }

            $targetAdmin = Admin::where('email', $resetRequest->email)->first();
            
            if (!$targetAdmin) {
                return response()->json(['message' => 'Admin tidak ditemukan.'], 404);
            }

            // 1. Ubah Password Sementara
            $targetAdmin->password = Hash::make($request->newPassword);
            $targetAdmin->save();
            
            // 2. Ubah Status Request
            $resetRequest->status = 'approved'; 
            $resetRequest->save();

            // 3. Kirim Email
            $details = [
                'username' => $targetAdmin->username,
                'newPassword' => $request->newPassword,
                'loginLink' => $request->header('origin') . '/admin/login'
            ];

            Mail::send([], [], function ($message) use ($targetAdmin, $details) {
                $message->to($targetAdmin->email)
                        ->subject("Reset Password Berhasil")
                        ->html("
                            <div style='font-family: sans-serif; padding: 20px; border: 1px solid #eee;'>
                                <h3>Password Anda Telah Direset</h3>
                                <p>Halo <b>{$details['username']}</b>,</p>
                                <p>Permintaan reset password Anda telah disetujui oleh Superadmin.</p>
                                <p>Password Baru: <b>{$details['newPassword']}</b></p>
                                <br/>
                                <a href='{$details['loginLink']}' style='background: #2563eb; color: #fff; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>Login Sekarang</a>
                            </div>
                        ");
            });

            // Jika email berhasil dikirim, baru commit ke database
            DB::commit();

            return response()->json([
                'message' => 'Sukses! Password direset dan email notifikasi terkirim ke ' . $targetAdmin->email
            ]);

        } catch (\Throwable $e) {
            DB::rollBack(); // Batalkan perubahan jika email gagal
            Log::error("Approve Failed: " . $e->getMessage());

            $errMsg = $e->getMessage();
            if (str_contains($errMsg, 'Connection could not be established')) {
                $errMsg = 'Koneksi SMTP gagal. Cek internet atau App Password Gmail Anda.';
            }

            return response()->json([
                'message' => 'Gagal mengirim email. Perubahan password dibatalkan. Error: ' . $errMsg
            ], 500);
        }
    }

    public function reject(Request $request)
    {
        if ($request->user()->role !== 'superadmin') return response()->json(['message' => 'Unauthorized'], 403);
        try {
            $req = PasswordResetRequest::find($request->id);
            if ($req) { 
                $req->status = 'rejected'; 
                $req->save(); 
                return response()->json(['message' => 'Permintaan ditolak.']); 
            }
            return response()->json(['message' => 'Data tidak ditemukan'], 404);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function requestReset(Request $request)
    {
        $request->validate([
            'identifier' => 'required',
            'whatsapp' => 'required',
            'reason' => 'nullable'
        ]);

        try {
            $admin = Admin::where('email', $request->identifier)
                        ->orWhere('username', $request->identifier)
                        ->first();

            if (!$admin) {
                return response()->json(['message' => 'Username atau Email tidak terdaftar.'], 404);
            }

            PasswordResetRequest::create([
                'email' => $admin->email,
                'username' => $admin->username,
                'whatsapp' => $request->whatsapp,
                'reason' => $request->reason,
                'status' => 'pending'
            ]);

            return response()->json(['message' => 'Permintaan reset password berhasil dikirim.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}