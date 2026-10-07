<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditoProveedor extends Model
{
    use HasFactory;

    protected $table = 'creditos_proveedor';
    protected $primaryKey = 'proveedor_id';
    public $incrementing = false;
    protected $keyType = 'int';

    // Solo updated_at, no created_at
    public $timestamps = false;

    protected $fillable = [
        'proveedor_id',
        'saldo',
        'updated_at',
    ];

    protected $casts = [
        'saldo'      => 'integer',
        'updated_at' => 'datetime',
    ];

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function transacciones()
    {
        return $this->hasMany(TransaccionCredito::class, 'proveedor_id', 'proveedor_id');
    }
}
