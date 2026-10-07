<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaqueteCredito extends Model
{
    use HasFactory;

    protected $table = 'paquetes_creditos';

    protected $fillable = [
        'nombre',
        'precio_gtq',
        'creditos_base',
        'creditos_bonus',
        'activo',
        'orden',
    ];

    protected $casts = [
        'precio_gtq'     => 'decimal:2',
        'creditos_base'  => 'integer',
        'creditos_bonus' => 'integer',
        'activo'         => 'boolean',
        'orden'          => 'integer',
    ];

    public function scopeActivos($query)
    {
        return $query->where('activo', true)->orderBy('orden');
    }

    public function compras()
    {
        return $this->hasMany(CompraCredito::class, 'paquete_id');
    }
}
