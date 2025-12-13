<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\Exam;
use App\Models\SmtpSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Auth; 
use Illuminate\Support\Str;

class InviteController extends Controller
{
    /**
     * Helper: Setup Email Config Dinamis
     */
    private function setupMailer()
    {
        $userId = Auth::id();
        $smtp = SmtpSetting::where('user_id', $userId)->first(); 

        if (!$smtp || empty($smtp->auth_user) || empty($smtp->auth_pass)) {
            throw new \Exception("Gagal mengirim undangan! Harap konfigurasi terlebih dahulu di pengaturan email!");
        }

        // [PERBAIKAN] Paksa gunakan driver 'smtp' meskipun di DB tertulis 'gmail'
        // Laravel tidak memiliki driver bawaan bernama 'gmail', harus 'smtp' dengan host gmail.
        Config::set('mail.mailers.smtp.transport', 'smtp');
        
        Config::set('mail.mailers.smtp.host', $smtp->host);
        Config::set('mail.mailers.smtp.port', $smtp->port);
        
        $encryption = $smtp->port == 465 ? 'ssl' : 'tls';
        Config::set('mail.mailers.smtp.encryption', $encryption);
        
        Config::set('mail.mailers.smtp.username', $smtp->auth_user);
        Config::set('mail.mailers.smtp.password', $smtp->auth_pass);
        Config::set('mail.from.address', $smtp->auth_user);
        Config::set('mail.from.name', $smtp->from_name);

        app()->forgetInstance('mailer');
        Mail::clearResolvedInstances();
    }

    /**
     * POST /api/invite
     * Kirim Undangan
     */
    public function sendInvite(Request $request)
    {
        $request->validate([
            'exam_id' => 'required|exists:exams,id',
            'emails' => 'required|array',
            'pesan' => 'required',
            'max_logins' => 'required|integer|min:1'
        ]);

        $exam = Exam::find($request->exam_id);
        
        if ($request->user()->role !== 'superadmin' && $exam->admin_id !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        // Setup Mailer sebelum loop
        try {
            $this->setupMailer(); 
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }

        $successCount = 0;
        $errors = [];

        foreach ($request->emails as $email) {
            $loginCode = strtoupper(Str::random(6));
            
            // Generate kode unik
            while(Invitation::where('login_code', $loginCode)->exists()) {
                $loginCode = strtoupper(Str::random(6));
            }

            DB::beginTransaction();
            try {
                // 1. Simpan Data Undangan
                Invitation::create([
                    'email' => $email,
                    'exam_id' => $exam->id,
                    'login_code' => $loginCode,
                    'max_logins' => $request->max_logins,
                    'admin_id' => $request->user()->id,
                    'login_count' => 0
                ]);

                // 2. Siapkan Data Email
                $details = [
                    'exam_name' => $exam->keterangan,
                    'code' => $loginCode,
                    'message' => $request->pesan,
                    'max_logins' => $request->max_logins,
                    'link' => $request->header('origin') ?? 'http://localhost:5173'
                ];

                // 3. Kirim Email
                Mail::send([], [], function ($message) use ($email, $details, $exam) {
                    $message->to($email)
                            ->subject("Undangan Ujian: " . $exam->keterangan)
                            ->html("
                                <div style='font-family: sans-serif; line-height: 1.6;'>
                                    <p>{$details['message']}</p>
                                    <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'/>
                                    <p>Anda diundang untuk mengikuti ujian: <b>{$details['exam_name']}</b></p>
                                    <div style='background: #f4f6f8; padding: 15px; border-radius: 8px; text-align: center;'>
                                        <p style='margin: 0; color: #666;'>Kode Login Anda:</p>
                                        <h2 style='color: #2563eb; font-size: 24px; margin: 10px 0; letter-spacing: 2px;'>{$details['code']}</h2>
                                        <p style='font-size: 12px; color: #888;'>(Berlaku untuk {$details['max_logins']} kali login)</p>
                                    </div>
                                    <br/>
                                    <div style='text-align: center;'>
                                        <a href='{$details['link']}' style='display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;'>Mulai Ujian</a>
                                    </div>
                                </div>
                            ");
                });

                DB::commit();
                $successCount++;

            } catch (\Exception $e) {
                DB::rollBack();
                $errors[] = ['email' => $email, 'error' => $e->getMessage()];
            }
        }

        if ($successCount === 0 && count($errors) > 0) {
            // Jika semua gagal, return 500
            return response()->json([
                'message' => 'Gagal mengirim undangan. Periksa koneksi internet atau password aplikasi email Anda.', 
                'errors' => $errors
            ], 500);
        }

        return response()->json([
            'message' => "Berhasil mengirim $successCount undangan.",
            'errors' => $errors
        ]);
    }

    /**
     * POST /api/invite/login
     * Login Peserta
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'login_code' => 'required'
        ]);

        $invitation = Invitation::where('email', $request->email)
            ->where('login_code', trim($request->login_code))
            ->first();

        if (!$invitation) {
            return response()->json(['message' => 'Kredensial tidak cocok. Cek Email & Kode Login.'], 404);
        }

        if ($invitation->login_count >= $invitation->max_logins) {
            return response()->json(['message' => "Kuota login habis ({$invitation->max_logins}x)."], 403);
        }

        $invitation->increment('login_count');

        return response()->json([
            'message' => 'Login berhasil',
            'examId' => $invitation->exam_id,
            'email' => $invitation->email
        ]);
    }

    /**
     * GET /api/invite/list
     * Daftar Undangan
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $targetAdminId = $request->query('target_admin_id');

        $query = Invitation::with('exam:id,keterangan')
            ->orderBy('created_at', 'desc')
            ->limit(100);

        // Jika Superadmin & ada target_admin_id, filter berdasarkan target
        if ($user->role === 'superadmin' && $targetAdminId) {
            $query->where('admin_id', $targetAdminId);
        } 
        // Default: filter punya sendiri
        else {
            $query->where('admin_id', $user->id);
        }

        $data = $query->get()->map(function($inv) {
            return [
                'id' => $inv->id,
                'email' => $inv->email,
                'exam_id' => $inv->exam_id,
                'login_code' => $inv->login_code,
                'max_logins' => $inv->max_logins,
                'login_count' => $inv->login_count,
                'sent_at' => $inv->created_at ? $inv->created_at->format('Y-m-d H:i:s') : '-', 
                'keterangan_ujian' => $inv->exam->keterangan ?? 'Ujian Terhapus'
            ];
        });

        return response()->json($data);
    }

    /**
     * DELETE /api/invite/:id
     * Hapus Undangan
     */
    public function destroy(Request $request, $id)
    {
        $invitation = Invitation::find($id);

        if (!$invitation) {
            return response()->json(['message' => 'Undangan tidak ditemukan'], 404);
        }

        // Cek kepemilikan
        if ($request->user()->role !== 'superadmin' && $invitation->admin_id !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $invitation->delete();
        return response()->json(['message' => 'Undangan berhasil dihapus']);
    }
}