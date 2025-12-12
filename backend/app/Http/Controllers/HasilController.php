<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\HasilUjian;
use App\Models\Option;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HasilController extends Controller
{
    /**
     * Helper: Normalisasi input jawaban
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
     * POST /api/hasil/draft
     * Simpan Draft (Autosave) - Tanpa penilaian
     */
    public function storeDraft(Request $request)
    {
        $data = $request->all(); // Biasanya JSON raw

        if (empty($data['peserta_id']) || empty($data['exam_id']) || empty($data['jawaban'])) {
            return response()->json(['message' => 'Data draft tidak lengkap'], 400);
        }

        DB::beginTransaction();
        try {
            foreach ($data['jawaban'] as $j) {
                if (empty($j['question_id'])) continue;

                // Gunakan updateOrCreate agar tidak duplikat
                HasilUjian::updateOrCreate(
                    [
                        'peserta_id' => $data['peserta_id'],
                        'exam_id' => $data['exam_id'],
                        'question_id' => $j['question_id']
                    ],
                    [
                        'jawaban_text' => $j['jawaban_text'] ?? null,
                        'benar' => false // Draft belum dinilai
                    ]
                );
            }
            DB::commit();
            return response()->json(['message' => '✅ Draft jawaban tersimpan']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal simpan draft', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/hasil
     * Submit Jawaban Final & Penilaian Otomatis
     */
    public function store(Request $request)
    {
        // Handle FormData (File Upload)
        $data = $this->parseData($request);
        
        if (empty($data['peserta_id']) || empty($data['exam_id']) || empty($data['jawaban'])) {
            return response()->json(['message' => 'Data ujian tidak lengkap'], 400);
        }

        DB::beginTransaction();
        try {
            foreach ($data['jawaban'] as $j) {
                if (empty($j['question_id'])) continue;

                $questionId = $j['question_id'];
                $tipeSoal = $j['tipe_soal'] ?? '';
                $jawabanText = $j['jawaban_text'] ?? null;
                $isCorrect = false;

                // --- 1. Logika Soal Dokumen (Upload File) ---
                if ($tipeSoal === 'soalDokumen') {
                    // Cek apakah ada file yang diupload untuk soal ini
                    // Frontend mengirim dengan name: dokumen_{question_id}
                    $fileKey = "dokumen_" . $questionId;
                    
                    if ($request->hasFile($fileKey)) {
                        // Bisa multiple files
                        $files = $request->file($fileKey);
                        $paths = [];
                        
                        if (is_array($files)) {
                            foreach ($files as $file) {
                                $path = $file->store('uploads_jawaban', 'public');
                                $paths[] = '/storage/' . $path;
                            }
                        } else {
                            $path = $files->store('uploads_jawaban', 'public');
                            $paths[] = '/storage/' . $path;
                        }
                        $jawabanText = json_encode($paths); // Simpan path sebagai JSON array
                    } else {
                        // Jika tidak ada file baru, mungkin mempertahankan jawaban lama (draft)
                        $jawabanText = $j['jawaban_text'] ?? null;
                    }
                    $isCorrect = false; // Harus dinilai manual
                }

                // --- 2. Logika Pilihan Ganda ---
                else if ($tipeSoal === 'pilihanGanda' && $jawabanText) {
                    $optionId = (int) $jawabanText;
                    $opsi = Option::find($optionId);
                    
                    if ($opsi) {
                        $isCorrect = $opsi->is_correct;
                        $jawabanText = $opsi->opsi_text; // Simpan teks opsinya, bukan ID (sesuai logic lama)
                    }
                }

                // --- 3. Logika Teks Singkat ---
                else if ($tipeSoal === 'teksSingkat' && $jawabanText) {
                    $kunci = Option::where('question_id', $questionId)->where('is_correct', true)->first();
                    
                    if ($kunci) {
                        $kunciArr = explode(',', strtolower(str_replace(' ', '', $kunci->opsi_text)));
                        $userAnswer = strtolower(str_replace(' ', '', $jawabanText));
                        
                        if (in_array($userAnswer, $kunciArr)) {
                            $isCorrect = true;
                        }
                    }
                }

                // Simpan Hasil
                HasilUjian::updateOrCreate(
                    [
                        'peserta_id' => $data['peserta_id'],
                        'exam_id' => $data['exam_id'],
                        'question_id' => $questionId
                    ],
                    [
                        'jawaban_text' => $jawabanText,
                        'benar' => $isCorrect,
                        'created_at' => now(), // Update timestamp submit
                    ]
                );
            }

            DB::commit();
            return response()->json(['message' => '✅ Hasil ujian berhasil disimpan dan dinilai']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Submit Ujian Error: " . $e->getMessage());
            return response()->json(['message' => 'Gagal menyimpan hasil ujian', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/hasil
     * Rekap Hasil Ujian (Admin)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $targetAdminId = $request->query('target_admin_id');
        
        $query = DB::table('hasil_ujian as h')
            ->join('peserta as p', 'p.id', '=', 'h.peserta_id')
            ->join('exams as e', 'e.id', '=', 'h.exam_id')
            ->join('questions as q', 'q.id', '=', 'h.question_id')
            ->select(
                'p.id as peserta_id', 'p.nama', 'p.email', 'p.nohp',
                'e.id as exam_id', 'e.keterangan as ujian',
                'q.id as question_id', 'q.soal_text', 'q.tipe_soal', 'q.bobot',
                'h.jawaban_text', 'h.benar', 'h.created_at'
            );

        // Filter berdasarkan Admin pembuat soal
        if ($user->role !== 'superadmin') {
            $query->where('e.admin_id', $user->id);
        } else if ($targetAdminId) {
            $query->where('e.admin_id', $targetAdminId);
        }

        $rows = $query->orderBy('e.id')->orderBy('p.id')->orderBy('q.id')->get();

        // Normalisasi Output (terutama untuk file path)
        $normalized = $rows->map(function ($row) {
            if ($row->tipe_soal === 'soalDokumen') {
                $files = [];
                try {
                    $decoded = json_decode($row->jawaban_text);
                    if (is_array($decoded)) $files = $decoded;
                    else if ($row->jawaban_text) $files = [$row->jawaban_text];
                } catch (\Exception $e) {
                    $files = [$row->jawaban_text];
                }

                $row->jawaban_files = $files;
                $row->jawaban_text = $files[0] ?? null; // Ambil file pertama sebagai preview
            }
            // Tambahkan kunci jawaban untuk referensi admin
            $kunci = Option::where('question_id', $row->question_id)
                           ->where('is_correct', true)
                           ->pluck('opsi_text')
                           ->implode(', ');
            $row->kunci_jawaban_text = $kunci;

            return $row;
        });

        return response()->json($normalized);
    }

    /**
     * GET /api/hasil/peserta/:peserta_id
     * Detail Hasil Satu Peserta
     */
    public function showByPeserta(Request $request, $pesertaId)
    {
        $user = $request->user();
        
        $query = DB::table('hasil_ujian as h')
            ->join('questions as q', 'q.id', '=', 'h.question_id')
            ->join('exams as e', 'e.id', '=', 'h.exam_id')
            ->where('h.peserta_id', $pesertaId)
            ->select(
                'q.id as question_id', 'q.soal_text', 'q.tipe_soal', 'q.bobot',
                'h.jawaban_text', 'h.benar', 'h.created_at', 'h.exam_id',
                'e.keterangan as keterangan_ujian', 'e.admin_id'
            );

        if ($user->role !== 'superadmin') {
            $query->where('e.admin_id', $user->id);
        }

        $rows = $query->orderBy('q.id')->get();

        if ($rows->isEmpty()) {
            return response()->json(['message' => 'Hasil ujian tidak ditemukan'], 404);
        }

        // Format data agar sesuai frontend (tambahkan pilihan ganda)
        foreach ($rows as $row) {
            $row->pilihan = [];
            if (in_array($row->tipe_soal, ['pilihanGanda', 'teksSingkat'])) {
                $options = Option::where('question_id', $row->question_id)
                    ->select('id', 'opsi_text', 'is_correct')
                    ->get();
                
                $row->pilihan = $options->map(function($opt) {
                    return [
                        'id' => $opt->id,
                        'text' => $opt->opsi_text, // Sesuaikan key dengan frontend
                        'opsi_text' => $opt->opsi_text,
                        'is_correct' => (bool)$opt->is_correct
                    ];
                });
            }

            if ($row->tipe_soal === 'soalDokumen') {
                $files = [];
                try {
                    $decoded = json_decode($row->jawaban_text);
                    if (is_array($decoded)) $files = $decoded;
                    else if ($row->jawaban_text) $files = [$row->jawaban_text];
                } catch (\Exception $e) {}
                $row->jawaban_files = $files;
            }
        }

        return response()->json($rows);
    }

    /**
     * PUT /api/hasil/nilai-manual
     * Penilaian Manual (Update status Benar/Salah)
     */
    public function updateNilaiManual(Request $request)
    {
        $request->validate([
            'peserta_id' => 'required',
            'exam_id' => 'required',
            'question_id' => 'required',
            'benar' => 'required|boolean'
        ]);

        $hasil = HasilUjian::where([
            'peserta_id' => $request->peserta_id,
            'exam_id' => $request->exam_id,
            'question_id' => $request->question_id
        ])->first();

        if (!$hasil) {
            return response()->json(['message' => 'Data hasil tidak ditemukan'], 404);
        }

        // Cek hak akses admin terhadap ujian ini
        $exam = Exam::find($request->exam_id);
        if ($request->user()->role !== 'superadmin' && $exam->admin_id !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $hasil->update(['benar' => $request->benar]);

        return response()->json(['message' => 'Nilai berhasil diperbarui', 'status' => $hasil->benar]);
    }
}