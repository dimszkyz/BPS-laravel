<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\Question;
use App\Models\Option;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class UjianController extends Controller
{
    /**
     * Helper: Parse data dari request (mirip logic parseBodyData di Node.js)
     * Frontend mengirim 'data' sebagai JSON String di dalam FormData
     */
    private function parseData($request)
    {
        if ($request->has('data')) {
            $data = $request->input('data');
            return is_string($data) ? json_decode($data, true) : $data;
        }
        return $request->all();
    }

    /**
     * GET /api/ujian
     * List semua ujian (Admin)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Logika Superadmin melihat ujian admin lain
        $targetAdminId = $request->query('target_admin_id');
        $adminIdToQuery = $user->id;

        if ($user->role === 'superadmin' && $targetAdminId) {
            $adminIdToQuery = $targetAdminId;
        }

        $exams = Exam::where('is_deleted', false)
            ->where('admin_id', $adminIdToQuery)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($exams);
    }

    /**
     * POST /api/ujian
     * Simpan ujian baru beserta soal & opsi
     */
    public function store(Request $request)
    {
        // 1. Ambil data JSON dari FormData
        $data = $this->parseData($request);

        // Validasi manual karena data ada dalam JSON string
        if (empty($data['keterangan']) || empty($data['durasi']) || empty($data['soalList'])) {
            return response()->json(['message' => 'Data ujian tidak lengkap.'], 400);
        }

        DB::beginTransaction();
        try {
            // 2. Simpan Header Ujian
            $exam = Exam::create([
                'keterangan' => $data['keterangan'],
                'tanggal' => $data['tanggal'],
                'tanggal_berakhir' => $data['tanggalBerakhir'],
                'jam_mulai' => $data['jamMulai'],
                'jam_berakhir' => $data['jamBerakhir'],
                'durasi' => (int) $data['durasi'],
                'acak_soal' => filter_var($data['acakSoal'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'acak_opsi' => filter_var($data['acakOpsi'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'admin_id' => $request->user()->id,
            ]);

            // 3. Loop Soal-soal
            foreach ($data['soalList'] as $index => $soalData) {
                // Handle Upload Gambar per Soal
                // Frontend mengirim file dengan key: gambar_0, gambar_1, dst.
                $gambarPath = null;
                $fileKey = "gambar_" . $index;
                
                if ($request->hasFile($fileKey)) {
                    // Upload ke folder public/uploads
                    $path = $request->file($fileKey)->store('uploads', 'public');
                    $gambarPath = '/storage/' . $path; // URL yang bisa diakses frontend
                } elseif (!empty($soalData['gambar'])) {
                    // Jika gambar berupa string (URL lama/text)
                    $gambarPath = $soalData['gambar'];
                }

                // Config untuk tipe soal dokumen
                $fileConfig = null;
                if (($soalData['tipeSoal'] ?? '') === 'soalDokumen') {
                    $fileConfig = [
                        'allowedTypes' => $soalData['allowedTypes'] ?? [],
                        'maxSize' => $soalData['maxSize'] ?? 5,
                        'maxCount' => $soalData['maxCount'] ?? 1
                    ];
                }

                // Simpan Soal
                $question = Question::create([
                    'exam_id' => $exam->id,
                    'tipe_soal' => $soalData['tipeSoal'] ?? '',
                    'soal_text' => $soalData['soalText'] ?? '',
                    'gambar' => $gambarPath,
                    'file_config' => $fileConfig, // Cast otomatis ke JSON oleh Model
                    'bobot' => (int) ($soalData['bobot'] ?? 1),
                ]);

                // 4. Simpan Opsi Jawaban (Pilihan Ganda / Teks Singkat)
                if (in_array($soalData['tipeSoal'], ['pilihanGanda', 'teksSingkat'])) {
                    
                    // Logika Pilihan Ganda
                    if ($soalData['tipeSoal'] === 'pilihanGanda' && !empty($soalData['pilihan'])) {
                        $kunci = trim($soalData['kunciJawabanText'] ?? '');
                        
                        foreach ($soalData['pilihan'] as $opsiItem) {
                            $teksOpsi = is_array($opsiItem) ? ($opsiItem['text'] ?? '') : $opsiItem;
                            
                            Option::create([
                                'question_id' => $question->id,
                                'opsi_text' => $teksOpsi,
                                'is_correct' => trim($teksOpsi) === $kunci
                            ]);
                        }
                    } 
                    // Logika Teks Singkat
                    elseif ($soalData['tipeSoal'] === 'teksSingkat' && !empty($soalData['kunciJawabanText'])) {
                        Option::create([
                            'question_id' => $question->id,
                            'opsi_text' => $soalData['kunciJawabanText'],
                            'is_correct' => true
                        ]);
                    }
                }
            }

            DB::commit();
            return response()->json(['id' => $exam->id, 'message' => 'Ujian tersimpan.'], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal simpan ujian: ' . $e->getMessage());
            return response()->json(['message' => 'Gagal menyimpan ujian.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/ujian/:id
     * Detail Ujian untuk Edit (Admin)
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        
        $exam = Exam::with(['questions.options'])->find($id);

        if (!$exam) {
            return response()->json(['message' => 'Ujian tidak ditemukan'], 404);
        }

        // Cek kepemilikan
        if ($user->role !== 'superadmin' && $exam->admin_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        // Format data agar sesuai dengan format yang diharapkan Frontend React
        $soalList = $exam->questions->map(function($q) {
            $pilihan = [];
            
            // Format ulang pilihan ganda
            if ($q->tipe_soal === 'pilihanGanda' || $q->tipe_soal === 'teksSingkat') {
                $pilihan = $q->options->map(function($opt) {
                    return [
                        'id' => $opt->id,
                        'text' => $opt->opsi_text,
                        'isCorrect' => $opt->is_correct
                    ];
                });
            }

            // Ambil config file jika ada
            $config = $q->file_config ?? [];

            return [
                'id' => $q->id,
                'tipeSoal' => $q->tipe_soal,
                'bobot' => $q->bobot,
                'soalText' => $q->soal_text,
                'gambar' => $q->gambar,
                'allowedTypes' => $config['allowedTypes'] ?? [],
                'maxSize' => $config['maxSize'] ?? 5,
                'maxCount' => $config['maxCount'] ?? 1,
                'pilihan' => $pilihan
            ];
        });

        return response()->json([
            'id' => $exam->id,
            'keterangan' => $exam->keterangan,
            'tanggal' => $exam->tanggal,
            'tanggal_berakhir' => $exam->tanggal_berakhir,
            'jam_mulai' => $exam->jam_mulai, // Laravel return format H:i:s
            'jam_berakhir' => $exam->jam_berakhir,
            'acak_soal' => $exam->acak_soal,
            'acak_opsi' => $exam->acak_opsi,
            'durasi' => $exam->durasi,
            'soalList' => $soalList
        ]);
    }

    /**
     * DELETE /api/ujian/:id
     * Soft delete ujian
     */
    public function destroy(Request $request, $id)
    {
        $exam = Exam::find($id);
        if (!$exam) {
            return response()->json(['message' => 'Ujian tidak ditemukan'], 404);
        }

        // Cek kepemilikan
        if ($request->user()->role !== 'superadmin' && $exam->admin_id !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $exam->update(['is_deleted' => true]);
        return response()->json(['message' => 'Ujian berhasil diarsipkan']);
    }
}