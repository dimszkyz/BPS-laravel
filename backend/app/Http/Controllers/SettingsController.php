<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\SmtpSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth; // Tambahkan import ini

class SettingsController extends Controller
{
    /**
     * GET /api/settings
     * Mengambil semua pengaturan umum (Public)
     */
    public function index()
    {
        // Ambil semua setting dari DB
        $settings = AppSetting::all()->pluck('setting_value', 'setting_key');

        // Tambahkan path lengkap untuk gambar jika ada
        $response = $settings->toArray();
        
        // Return format key-value object
        return response()->json($response);
    }

    /**
     * POST /api/settings
     * Simpan pengaturan (Admin Only)
     */
    public function update(Request $request)
    {
        // Handle Uploads
        $this->handleUpload($request, 'adminBgImage');
        $this->handleUpload($request, 'pesertaBgImage');
        $this->handleUpload($request, 'headerLogo');

        // Handle Text Header
        if ($request->has('headerText')) {
            AppSetting::updateOrCreate(
                ['setting_key' => 'headerText'],
                ['setting_value' => $request->headerText]
            );
        }

        return response()->json(['message' => 'Pengaturan berhasil diperbarui!']);
    }

    // Helper untuk upload gambar settings
    private function handleUpload($request, $key)
    {
        if ($request->hasFile($key)) {
            $path = $request->file($key)->store('uploads', 'public');
            AppSetting::updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => '/storage/' . $path]
            );
        }
    }

    /**
     * GET /api/settings/smtp
     * Ambil setting SMTP (Admin Only)
     */
    public function getSmtp()
    {
        // Ubah pencarian menggunakan Auth::id() agar dikenali editor
        $smtp = SmtpSetting::where('user_id', Auth::id())->first();

        if (!$smtp) {
            // Jika user ini belum punya setting, return default kosong
            return response()->json([
                'service' => 'gmail',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'secure' => false,
                'auth_user' => '',
                'auth_pass' => '',
                'from_name' => 'Admin Ujian',
            ]);
        }
        return response()->json($smtp);
    }

    /**
     * PUT /api/settings/smtp
     * Update setting SMTP (Admin Only) - UNIK PER USER
     */
    public function updateSmtp(Request $request)
    {
        $validated = $request->validate([
            'auth_user' => 'required',
            'auth_pass' => 'required',
        ]);

        // Gunakan updateOrCreate dengan Auth::id()
        SmtpSetting::updateOrCreate(
            ['user_id' => Auth::id()], // Kunci pencarian: user yang sedang login
            [
                'service' => $request->service ?? 'gmail',
                'host' => $request->host ?? 'smtp.gmail.com',
                'port' => $request->port ?? 587,
                'secure' => filter_var($request->secure, FILTER_VALIDATE_BOOLEAN),
                'auth_user' => $request->auth_user,
                'auth_pass' => $request->auth_pass,
                'from_name' => $request->from_name ?? 'Admin Ujian',
            ]
        );

        return response()->json(['message' => 'Pengaturan email berhasil disimpan untuk akun ini.']);
    }
}