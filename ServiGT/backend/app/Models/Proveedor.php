<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    use HasFactory;

    protected $table = 'proveedores';

    protected $fillable = [
        'user_id',
        'nombre',
        'email',
        'telefono',
        'descripcion',
        'departamento',
        'municipio',
        'categoria_id',
        'foto_perfil',
        'tarifa_hora',
        'tarifa_proyecto',
        'nivel',
        'premium_inicio_at',
        'premium_vence_at',
        'premium_ciclo_key',
        'premium_renovaciones',
        'portada',
        'color_acento',
    ];

    protected $casts = [
        'tarifa_hora'           => 'decimal:2',
        'tarifa_proyecto'       => 'decimal:2',
        'calificacion_promedio' => 'decimal:2',
        'premium_inicio_at'     => 'datetime',
        'premium_vence_at'      => 'datetime',
        'premium_renovaciones'  => 'integer',
    ];

    protected $appends = [
        'premium_estado',
        'premium_dias_restantes',
    ];

    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }

    public function categorias()
    {
        return $this->belongsToMany(Categoria::class, 'proveedor_categorias');
    }

    public function documentos()
    {
        return $this->hasMany(DocumentoProveedor::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function disponibilidad()
    {
        return $this->hasMany(Disponibilidad::class)->orderBy('dia_semana');
    }

    public function servicios()
    {
        return $this->hasMany(Servicio::class);
    }

    public function publicaciones()
    {
        return $this->hasMany(PublicacionServicio::class, 'proveedor_id');
    }

    public function credito()
    {
        return $this->hasOne(CreditoProveedor::class, 'proveedor_id');
    }

    public function transaccionesCredito()
    {
        return $this->hasMany(TransaccionCredito::class, 'proveedor_id');
    }

    public function cotizaciones()
    {
        return $this->hasMany(Cotizacion::class, 'proveedor_id');
    }

    public function compras()
    {
        return $this->hasMany(CompraCredito::class, 'proveedor_id');
    }

    /**
     * Estado Premium derivado de premium_vence_at: no se guarda por separado
     * para evitar que ambos campos se desincronicen.
     */
    public function premiumEstado(): string
    {
        if (!$this->premium_vence_at) {
            return 'nunca';
        }

        return $this->premium_vence_at->isFuture() ? 'activo' : 'vencido';
    }

    public function getPremiumEstadoAttribute(): string
    {
        return $this->premiumEstado();
    }

    public function getPremiumDiasRestantesAttribute(): int
    {
        if ($this->premiumEstado() !== 'activo') {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInDays($this->premium_vence_at, false)));
    }
}
