<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\Exam;
use App\Models\SmtpSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class InviteController extends Controller
{
    /**
     * Helper: Setup Email Config Dinamis dari Database
     */
    private function setupMailer()
    {
        // Ambil setting global (ID 1) atau sesuaikan jika per-admin
        $smtp = SmtpSetting::first(); 

        if (!$smtp) {
            throw new \Exception("Harap Konfigurasi Email Pengirim di Pengaturan Email.");
        }

        // Override konfigurasi mailer Laravel saat runtime
        Config::set('mail.mailers.smtp.transport', 'smtp');
        Config::set('mail.mailers.smtp.host', $smtp->host);
        Config::set('mail.mailers.smtp.port', $smtp->port);
        Config::set('mail.mailers.smtp.encryption', $smtp->secure ? 'tls' : null); // 1=TLS/SSL
        Config::set('mail.mailers.smtp.username', $smtp->auth_user);
        Config::set('mail.mailers.smtp.password', $smtp->auth_pass);
        Config::set('mail.from.address', $smtp->auth_user);
        Config::set('mail.from.name', $smtp->from_name);
    }

    /**
     * POST /api/invite
     * Kirim Undangan via Email
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
        
        // Cek kepemilikan
        if ($request->user()->role !== 'superadmin' && $exam->admin_id !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        try {
            $this->setupMailer(); // Setup SMTP
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }

        $successCount = 0;
        $errors = [];

        foreach ($request->emails as $email) {
            // Generate Kode Unik
            $loginCode = strtoupper(Str::random(6));
            
            // Cek duplikat kode (simple check)
            while(Invitation::where('login_code', $loginCode)->exists()) {
                $loginCode = strtoupper(Str::random(6));
            }

            DB::beginTransaction();
            try {
                // 1. Simpan ke DB
                Invitation::create([
                    'email' => $email,
                    'exam_id' => $exam->id,
                    'login_code' => $loginCode,
                    'max_logins' => $request->max_logins,
                    'admin_id' => $request->user()->id,
                    'login_count' => 0
                ]);

                // 2. Kirim Email
                $details = [
                    'exam_name' => $exam->keterangan,
                    'code' => $loginCode,
                    'message' => $request->pesan,
                    'max_logins' => $request->max_logins,
                    'link' => $request->header('origin') ?? 'http://localhost:5173' // Link frontend
                ];

                Mail::send([], [], function ($message) use ($email, $details, $exam) {
                    $message->to($email)
                            ->subject("Undangan Ujian: " . $exam->keterangan)
                            ->html("
                                <p>{$details['message']}</p>
                                <hr/>
                                <p>Anda diundang untuk ujian: <b>{$details['exam_name']}</b></p>
                                <p>Silakan login dengan Email Anda dan Kode Login berikut:</p>
                                <h2 style='color:blue'>{$details['code']}</h2>
                                <p>(Kode ini berlaku untuk {$details['max_logins']} kali login)</p>
                                <br/>
                                <a href='{$details['link']}' style='padding:10px 20px; background:blue; color:white; text-decoration:none; border-radius:5px;'>Mulai Ujian</a>
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
            return response()->json(['message' => 'Gagal mengirim semua undangan', 'errors' => $errors], 500);
        }

        return response()->json([
            'message' => "Berhasil mengirim $successCount undangan.",
            'errors' => $errors
        ]);
    }

    /**
     * POST /api/invite/login
     * Login Peserta menggunakan Kode Undangan
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

        // Update login count
        $invitation->increment('login_count');

        return response()->json([
            'message' => 'Login berhasil',
            'examId' => $invitation->exam_id,
            'email' => $invitation->email
        ]);
    }

    /**
     * GET /api/invite/list
     * Daftar Undangan (Admin)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $targetAdminId = $request->query('target_admin_id');

        $query = Invitation::with('exam:id,keterangan')
            ->orderBy('sent_at', 'desc')
            ->limit(100);

        if ($user->role !== 'superadmin') {
            $query->where('admin_id', $user->id);
        } else if ($targetAdminId) {
            $query->where('admin_id', $targetAdminId);
        }

        // Format data agar sesuai frontend (flat object)
        $data = $query->get()->map(function($inv) {
            return [
                'id' => $inv->id,
                'email' => $inv->email,
                'exam_id' => $inv->exam_id,
                'login_code' => $inv->login_code,
                'max_logins' => $inv->max_logins,
                'login_count' => $inv->login_count,
                'sent_at' => $inv->sent_at,
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