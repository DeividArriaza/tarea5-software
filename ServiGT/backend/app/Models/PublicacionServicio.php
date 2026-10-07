<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicacionServicio extends Model
{
    use HasFactory;

    protected $table = 'publicaciones_servicio';

    protected $fillable = [
        'proveedor_id',
        'categoria_id',
        'titulo',
        'descripcion',
        'precio_referencial',
        'imagen',
        'estado',
    ];

    protected $casts = [
        'precio_referencial' => 'decimal:2',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }
}
