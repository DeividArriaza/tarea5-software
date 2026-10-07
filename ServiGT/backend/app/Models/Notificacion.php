<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notificacion extends Model
{
    use HasFactory;

    protected $table = 'notificaciones';

    protected $fillable = [
        'destinatario_id',
        'tipo',
        'titulo',
        'mensaje',
        'datos',
        'leida',
    ];

    protected $casts = [
        'leida' => 'boolean',
        'datos' => 'array',
    ];

    public function destinatario()
    {
        return $this->belongsTo(User::class, 'destinatario_id');
    }
}
