<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    // List semua admin (Kecuali diri sendiri opsional, atau semua)
    public function index(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $admins = Admin::orderBy('created_at', 'desc')->get();
        return response()->json($admins);
    }

    // Tambah Admin Baru
    public function store(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'username' => 'required|unique:admins,username',
            'email' => 'required|email|unique:admins,email',
            'password' => 'required|min:6',
        ]);

        $admin = Admin::create([
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'admin', // Default role
            'is_active' => true
        ]);

        return response()->json(['message' => 'Admin berhasil ditambahkan', 'data' => $admin], 201);
    }

    // Hapus Admin
    public function destroy(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $admin = Admin::find($id);
        if (!$admin) return response()->json(['message' => 'Admin not found'], 404);
        
        // Cegah hapus diri sendiri
        if ($admin->id === $request->user()->id) {
            return response()->json(['message' => 'Tidak bisa menghapus akun sendiri'], 400);
        }

        $admin->delete();
        return response()->json(['message' => 'Admin berhasil dihapus']);
    }

    // Endpoint Ping untuk Sidebar
    public function ping() {
        return response()->json(['message' => 'pong', 'time' => now()]);
    }
    public function updateRole(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') return response()->json(['message' => 'Unauthorized'], 403);
        
        $admin = Admin::find($id);
        if (!$admin) return response()->json(['message' => 'Admin not found'], 404);
        
        $admin->role = $request->role;
        $admin->save();
        
        return response()->json(['message' => 'Role updated']);
    }

    // Update Username
    public function updateUsername(Request $request, $id)
    {
        // Izinkan superadmin ATAU diri sendiri untuk ubah username
        if ($request->user()->role !== 'superadmin' && $request->user()->id != $id) {
             return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['username' => 'required|unique:admins,username,' . $id]);
        
        $admin = Admin::find($id);
        if (!$admin) return response()->json(['message' => 'Admin not found'], 404);

        $admin->username = $request->username;
        $admin->save();

        return response()->json(['message' => 'Username updated', 'username' => $admin->username]);
    }

    // Toggle Status Aktif/Nonaktif
    public function toggleStatus(Request $request, $id)
    {
        if ($request->user()->role !== 'superadmin') return response()->json(['message' => 'Unauthorized'], 403);

        $admin = Admin::find($id);
        if (!$admin) return response()->json(['message' => 'Admin not found'], 404);

        // Cegah nonaktifkan diri sendiri
        if ($admin->id === $request->user()->id) {
            return response()->json(['message' => 'Tidak bisa menonaktifkan akun sendiri'], 400);
        }

        $admin->is_active = !$admin->is_active;
        $admin->save();

        $status = $admin->is_active ? 'diaktifkan' : 'dinonaktifkan';
        return response()->json(['message' => "Akun berhasil $status"]);
    }
}