<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    use HasFactory;

    protected $fillable = ['nombre', 'descripcion', 'icono'];

    public function proveedores(): HasMany
    {
        return $this->hasMany(Proveedor::class);
    }

    public function publicaciones(): HasMany
    {
        return $this->hasMany(PublicacionServicio::class);
    }
}
