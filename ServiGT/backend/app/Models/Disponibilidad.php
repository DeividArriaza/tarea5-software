<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Disponibilidad extends Model
{
    use HasFactory;

    protected $table = 'disponibilidad';

    protected $fillable = [
        'proveedor_id',
        'dia_semana',
        'hora_inicio',
        'hora_fin',
        'disponible',
    ];

    protected $casts = [
        'disponible' => 'boolean',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }
}
