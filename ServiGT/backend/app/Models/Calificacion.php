<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Calificacion extends Model
{
    use HasFactory;

    protected $table = 'calificaciones';

    protected $fillable = [
        'servicio_id',
        'autor_id',
        'destinatario_id',
        'puntuacion',
        'comentario',
        'es_verificada',
    ];

    protected $casts = [
        'es_verificada' => 'boolean',
    ];

    public function autor()
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function destinatario()
    {
        return $this->belongsTo(User::class, 'destinatario_id');
    }

    public function servicio()
    {
        return $this->belongsTo(Servicio::class);
    }
}
