<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pago extends Model
{
    use HasFactory;

    protected $table = 'pagos';

    protected $fillable = [
        'servicio_id',
        'monto',
        'metodo_pago',
        'estado',
        'referencia',
        'fecha_pago',
    ];

    protected $casts = [
        'fecha_pago' => 'datetime',
    ];

    public function servicio()
    {
        return $this->belongsTo(Servicio::class);
    }
}
