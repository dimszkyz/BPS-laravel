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
     * Helper: Parse data dari request
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
     * List semua ujian
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
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
     * Simpan ujian baru
     */
    public function store(Request $request)
    {
        $data = $this->parseData($request);

        if (empty($data['keterangan']) || empty($data['durasi']) || empty($data['soalList'])) {
            return response()->json(['message' => 'Data ujian tidak lengkap.'], 400);
        }

        DB::beginTransaction();
        try {
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

            foreach ($data['soalList'] as $index => $soalData) {
                $gambarPath = null;
                $fileKey = "gambar_" . $index;
                
                if ($request->hasFile($fileKey)) {
                    $path = $request->file($fileKey)->store('uploads', 'public');
                    $gambarPath = '/storage/' . $path;
                } elseif (!empty($soalData['gambar'])) {
                    $gambarPath = $soalData['gambar'];
                }

                $fileConfig = null;
                if (($soalData['tipeSoal'] ?? '') === 'soalDokumen') {
                    $fileConfig = [
                        'allowedTypes' => $soalData['allowedTypes'] ?? [],
                        'maxSize' => $soalData['maxSize'] ?? 5,
                        'maxCount' => $soalData['maxCount'] ?? 1
                    ];
                }

                $question = Question::create([
                    'exam_id' => $exam->id,
                    'tipe_soal' => $soalData['tipeSoal'] ?? '',
                    'soal_text' => $soalData['soalText'] ?? '',
                    'gambar' => $gambarPath,
                    'file_config' => $fileConfig,
                    'bobot' => (int) ($soalData['bobot'] ?? 1),
                ]);

                if (in_array($soalData['tipeSoal'], ['pilihanGanda', 'teksSingkat'])) {
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
                    } elseif ($soalData['tipeSoal'] === 'teksSingkat' && !empty($soalData['kunciJawabanText'])) {
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
     * PUT /api/ujian/:id
     * Update ujian (Logika Baru)
     */
    public function update(Request $request, $id)
    {
        $data = $this->parseData($request);
        $user = $request->user();

        $exam = Exam::find($id);
        if (!$exam) {
            return response()->json(['message' => 'Ujian tidak ditemukan'], 404);
        }

        if ($user->role !== 'superadmin' && $exam->admin_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        DB::beginTransaction();
        try {
            // Update Header
            $exam->update([
                'keterangan' => $data['keterangan'],
                'tanggal' => $data['tanggal'],
                'tanggal_berakhir' => $data['tanggalBerakhir'],
                'jam_mulai' => $data['jamMulai'],
                'jam_berakhir' => $data['jamBerakhir'],
                'durasi' => (int) $data['durasi'],
                'acak_soal' => filter_var($data['acakSoal'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'acak_opsi' => filter_var($data['acakOpsi'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);

            // Sinkronisasi Soal
            $sentSoalIds = [];
            
            if (isset($data['soalList']) && is_array($data['soalList'])) {
                foreach ($data['soalList'] as $index => $soalData) {
                    $question = null;

                    // Cek ID soal lama
                    if (!empty($soalData['id']) && $soalData['id'] > 0) {
                        $question = Question::where('id', $soalData['id'])
                                            ->where('exam_id', $exam->id)
                                            ->first();
                        if ($question) {
                            $sentSoalIds[] = $question->id;
                        }
                    }

                    // Handle Gambar
                    $fileKey = "gambar_" . $index;
                    $gambarPath = null;
                    if ($request->hasFile($fileKey)) {
                        $path = $request->file($fileKey)->store('uploads', 'public');
                        $gambarPath = '/storage/' . $path;
                    } elseif (!empty($soalData['gambar'])) {
                        $gambarPath = $soalData['gambar'];
                    }

                    // Config
                    $fileConfig = null;
                    if (($soalData['tipeSoal'] ?? '') === 'soalDokumen') {
                        $fileConfig = [
                            'allowedTypes' => $soalData['allowedTypes'] ?? [],
                            'maxSize' => $soalData['maxSize'] ?? 5,
                            'maxCount' => $soalData['maxCount'] ?? 1
                        ];
                    }

                    $qData = [
                        'exam_id' => $exam->id,
                        'tipe_soal' => $soalData['tipeSoal'] ?? '',
                        'soal_text' => $soalData['soalText'] ?? '',
                        'gambar' => $gambarPath,
                        'file_config' => $fileConfig,
                        'bobot' => (int) ($soalData['bobot'] ?? 1),
                    ];

                    if ($question) {
                        $question->update($qData);
                        $question->options()->delete(); // Reset opsi lama
                    } else {
                        $question = Question::create($qData);
                        $sentSoalIds[] = $question->id;
                    }

                    // Simpan Opsi Baru
                    if (in_array($soalData['tipeSoal'], ['pilihanGanda', 'teksSingkat'])) {
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
                        } elseif ($soalData['tipeSoal'] === 'teksSingkat' && !empty($soalData['kunciJawabanText'])) {
                            Option::create([
                                'question_id' => $question->id,
                                'opsi_text' => $soalData['kunciJawabanText'],
                                'is_correct' => true
                            ]);
                        }
                    }
                }
            }

            // Hapus Soal yang tidak dikirim (dihapus user)
            Question::where('exam_id', $exam->id)
                    ->whereNotIn('id', $sentSoalIds)
                    ->delete();

            DB::commit();
            return response()->json(['message' => 'Ujian berhasil diperbarui']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Gagal update ujian: ' . $e->getMessage());
            return response()->json(['message' => 'Gagal update ujian.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/ujian/:id
     * Detail Ujian
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $exam = Exam::with(['questions.options'])->find($id);

        if (!$exam) {
            return response()->json(['message' => 'Ujian tidak ditemukan'], 404);
        }

        if ($user->role !== 'superadmin' && $exam->admin_id !== $user->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $soalList = $exam->questions->map(function($q) {
            $pilihan = [];
            if ($q->tipe_soal === 'pilihanGanda' || $q->tipe_soal === 'teksSingkat') {
                $pilihan = $q->options->map(function($opt) {
                    return [
                        'id' => $opt->id,
                        'text' => $opt->opsi_text,
                        'isCorrect' => $opt->is_correct
                    ];
                });
            }

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
            'jam_mulai' => $exam->jam_mulai,
            'jam_berakhir' => $exam->jam_berakhir,
            'acak_soal' => $exam->acak_soal,
            'acak_opsi' => $exam->acak_opsi,
            'durasi' => $exam->durasi,
            'soalList' => $soalList
        ]);
    }

    /**
     * DELETE /api/ujian/:id
     * Soft delete
     */
    public function destroy(Request $request, $id)
    {
        $exam = Exam::find($id);
        if (!$exam) {
            return response()->json(['message' => 'Ujian tidak ditemukan'], 404);
        }

        if ($request->user()->role !== 'superadmin' && $exam->admin_id !== $request->user()->id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $exam->update(['is_deleted' => true]);
        return response()->json(['message' => 'Ujian berhasil diarsipkan']);
    }

    public function checkActive($id)
    {
        $exam = Exam::where('id', $id)->where('is_deleted', 0)->first();

        if (!$exam) {
            return response()->json(['message' => 'Ujian tidak ditemukan'], 404);
        }

        $now = now(); // Waktu server (pastikan timezone server WIB atau sesuai)
        // Jika settingan timezone Laravel sudah 'Asia/Jakarta', $now sudah benar.
        
        $msg = null;
        
        // Logika tanggal & jam
        $startDateTime = \Carbon\Carbon::parse($exam->tanggal . ' ' . $exam->jam_mulai);
        $endDateTime = \Carbon\Carbon::parse($exam->tanggal_berakhir . ' ' . $exam->jam_berakhir);

        if ($now->lessThan($startDateTime)) {
             $msg = "Ujian belum dimulai.";
        } elseif ($now->greaterThan($endDateTime)) {
             $msg = "Ujian sudah berakhir.";
        } else {
            // Cek jam harian (jika range tanggal > 1 hari, biasanya jam operasional per hari dicek)
            // Logika sederhana: jika dalam range tanggal, cek jam saat ini
            $currentTime = $now->format('H:i:s');
            if ($currentTime < $exam->jam_mulai || $currentTime > $exam->jam_berakhir) {
                $msg = "Jam ujian saat ini ditutup. Akses: " . $exam->jam_mulai . " - " . $exam->jam_berakhir;
            }
        }

        if ($msg) {
            return response()->json(['message' => $msg], 403);
        }

        return response()->json($exam);
    }

    /**
     * GET /api/ujian/public/:id
     * Ambil soal untuk dikerjakan (Tanpa Kunci Jawaban)
     */
    public function showPublic($id)
    {
        $exam = Exam::with(['questions.options'])->find($id);
        if (!$exam) return response()->json(['message' => 'Ujian tidak ditemukan'], 404);

        // Format data aman untuk peserta
        $soalList = $exam->questions->map(function($q) {
            $pilihan = $q->options->map(function($opt) {
                return [
                    'id' => $opt->id,
                    'text' => $opt->opsi_text,
                    // PENTING: Jangan kirim is_correct ke frontend peserta!
                ];
            });
            
            // Acak pilihan jika setting ujian mengizinkan
            // if ($exam->acak_opsi) { $pilihan = $pilihan->shuffle(); }

            return [
                'id' => $q->id,
                'tipeSoal' => $q->tipe_soal,
                'soalText' => $q->soal_text,
                'gambar' => $q->gambar,
                'bobot' => $q->bobot,
                'fileConfig' => $q->file_config,
                'pilihan' => $pilihan
            ];
        });
        
        // Acak soal jika perlu
        // if ($exam->acak_soal) { $soalList = $soalList->shuffle(); }

        return response()->json([
            'id' => $exam->id,
            'keterangan' => $exam->keterangan,
            'durasi' => $exam->durasi,
            'soalList' => $soalList
        ]);
    }
}