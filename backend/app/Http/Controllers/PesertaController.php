<?php

namespace App\Http\Controllers;

use App\Models\Peserta;
use App\Imports\PesertaImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Log;

class PesertaController extends Controller
{
    /**
     * GET /api/peserta
     * Ambil semua data peserta (Superadmin Only)
     */
    public function index(Request $request)
    {
        // Cek Role (Sesuai logic Node.js: req.admin.role !== 'superadmin')
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Hanya Superadmin yang dapat melihat semua peserta.'], 403);
        }

        $peserta = Peserta::orderBy('created_at', 'desc')->get();
        return response()->json($peserta);
    }

    /**
     * GET /api/peserta/:id
     * Ambil detail peserta
     */
    public function show(Request $request, $id)
    {
        // Validasi akses admin (semua admin bisa lihat detail untuk keperluan edit/ujian)
        
        $peserta = Peserta::find($id);
        if (!$peserta) {
            return response()->json(['message' => 'Peserta tidak ditemukan'], 404);
        }

        return response()->json($peserta);
    }

    /**
     * POST /api/peserta
     * Simpan peserta baru (Manual)
     */
    public function store(Request $request)
    {
        $request->validate([
            'nama' => 'required',
            'nohp' => 'required',
            'email' => 'required|email|unique:peserta,email',
        ]);

        try {
            $peserta = Peserta::create([
                'nama' => $request->nama,
                'nohp' => $request->nohp,
                'email' => $request->email,
                // Default password atau login code bisa diset disini
                'password' => $request->password ?? '123456', 
            ]);

            return response()->json([
                'id' => $peserta->id, 
                'message' => 'Peserta berhasil disimpan ✅'
            ], 201);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menyimpan data peserta.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * PUT /api/peserta/:id
     * Update data peserta
     */
    public function update(Request $request, $id)
    {
        $peserta = Peserta::find($id);
        if (!$peserta) {
            return response()->json(['message' => 'Peserta tidak ditemukan'], 404);
        }

        $request->validate([
            'nama' => 'required',
            'nohp' => 'required',
            'email' => 'required|email|unique:peserta,email,' . $id, // Ignore unique check for current user
        ]);

        try {
            $peserta->update([
                'nama' => $request->nama,
                'nohp' => $request->nohp,
                'email' => $request->email,
            ]);

            return response()->json([
                'id' => $peserta->id,
                'message' => 'Peserta berhasil diperbarui ✅'
            ]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal memperbarui data peserta.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /api/peserta/:id
     * Hapus peserta (Superadmin Only)
     */
    public function destroy(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Hanya Superadmin yang dapat menghapus peserta.'], 403);
        }

        $peserta = Peserta::find($id);
        if (!$peserta) {
            return response()->json(['message' => 'Peserta tidak ditemukan'], 404);
        }

        $peserta->delete();
        return response()->json(['message' => 'Peserta berhasil dihapus']);
    }

    /**
     * POST /api/peserta/import
     * Import Excel
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls,csv'
        ]);

        try {
            Excel::import(new PesertaImport, $request->file('file'));
            return response()->json(['message' => 'Data peserta berhasil diimport!']);
        } catch (\Exception $e) {
            Log::error("Import Error: " . $e->getMessage());
            return response()->json(['message' => 'Gagal import data.', 'error' => $e->getMessage()], 500);
        }
    }
}