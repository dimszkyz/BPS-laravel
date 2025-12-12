<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $fillable = [
        'exam_id',
        'tipe_soal',
        'soal_text',
        'gambar',
        'file_config',
        'bobot',
    ];

    protected $casts = [
        'file_config' => 'array', // Otomatis convert JSON ke Array PHP
        'bobot' => 'integer',
    ];

    // Relasi ke Ujian
    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }

    // Relasi ke Pilihan Jawaban
    public function options()
    {
        return $this->hasMany(Option::class);
    }
}