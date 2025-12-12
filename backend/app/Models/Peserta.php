<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Peserta extends Model
{
    protected $table = 'peserta'; // Laravel biasanya mencari 'pesertas', jadi kita paksa ke 'peserta'

    protected $fillable = [
        'nama',
        'nohp',
        'email',
        'password',
    ];

    // Relasi: Peserta punya banyak hasil ujian
    public function hasilUjian()
    {
        return $this->hasMany(HasilUjian::class);
    }
}