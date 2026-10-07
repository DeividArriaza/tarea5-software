<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransaccionCredito extends Model
{
    use HasFactory;

    protected $table = 'transacciones_credito';

    // Solo created_at, no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'proveedor_id',
        'tipo',
        'monto',
        'motivo',
        'referencia_id',
    ];

    protected $casts = [
        'monto'         => 'integer',
        'referencia_id' => 'integer',
        'created_at'    => 'datetime',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }
}
