<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Mensaje extends Model
{
    use HasFactory;

    protected $table = 'mensajes';

    protected $fillable = [
        'emisor_id',
        'receptor_id',
        'servicio_id',
        'contenido',
        'leido',
    ];

    protected $casts = [
        'leido' => 'boolean',
    ];

    public function emisor()
    {
        return $this->belongsTo(User::class, 'emisor_id');
    }

    public function receptor()
    {
        return $this->belongsTo(User::class, 'receptor_id');
    }

    public function servicio()
    {
        return $this->belongsTo(Servicio::class);
    }
}
