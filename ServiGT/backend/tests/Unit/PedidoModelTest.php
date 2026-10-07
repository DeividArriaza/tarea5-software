<?php

namespace Tests\Unit;

use App\Models\Pedido;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

/**
 * Pruebas unitarias de la configuracion del modelo Pedido.
 * No tocan la base de datos: inspeccionan fillable, casts y relaciones.
 */
class PedidoModelTest extends TestCase
{
    public function test_campos_asignables_masivamente(): void
    {
        $esperados = [
            'cliente_id', 'categoria_id', 'descripcion',
            'direccion', 'urgencia', 'estado', 'fecha_expiracion',
        ];

        $this->assertSame($esperados, (new Pedido())->getFillable());
    }

    public function test_fecha_expiracion_se_castea_a_datetime(): void
    {
        $this->assertArrayHasKey('fecha_expiracion', (new Pedido())->getCasts());
        $this->assertSame('datetime', (new Pedido())->getCasts()['fecha_expiracion']);
    }

    public function test_relacion_cliente_es_belongs_to_por_cliente_id(): void
    {
        $rel = (new Pedido())->cliente();

        $this->assertInstanceOf(BelongsTo::class, $rel);
        $this->assertSame('cliente_id', $rel->getForeignKeyName());
    }

    public function test_relacion_cotizaciones_es_has_many(): void
    {
        $this->assertInstanceOf(HasMany::class, (new Pedido())->cotizaciones());
    }
}
