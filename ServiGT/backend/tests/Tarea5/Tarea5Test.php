<?php

namespace Tests\Tarea5;

use App\Models\Categoria;
use App\Models\CompraCredito;
use App\Models\CreditoProveedor;
use App\Models\Notificacion;
use App\Models\PaqueteCredito;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\TransaccionCredito;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Tarea5Test extends TestCase
{
    use DatabaseTransactions;

    public function test_I1_crear_pedido_persiste_relaciones_y_devuelve_recurso(): void
    {
        $cliente = User::factory()->create(['role' => 'cliente']);
        $categoria = Categoria::factory()->create();
        Sanctum::actingAs($cliente);

        $response = $this->postJson('/api/pedidos', $this->datosPedido($categoria))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pedido.estado', 'abierto')
            ->assertJsonPath('pedido.cliente.id', $cliente->id)
            ->assertJsonPath('pedido.categoria.id', $categoria->id);

        $this->assertDatabaseHas('pedidos', [
            'id' => $response->json('pedido.id'),
            'cliente_id' => $cliente->id,
            'categoria_id' => $categoria->id,
            'descripcion' => $this->datosPedido($categoria)['descripcion'],
        ]);
    }

    public function test_I2_compra_acredita_saldo_compra_e_historial(): void
    {
        [$proveedor, $paquete] = $this->compraFixture();
        Sanctum::actingAs($proveedor->user);

        $response = $this->postJson('/api/creditos/comprar', [
            'paquete_id' => $paquete->id,
            'idempotency_key' => 'tarea5-integracion',
        ])->assertCreated()->assertJsonPath('saldo', 32)
            ->assertJsonPath('compra.creditos_otorgados', 30)
            ->assertJsonPath('compra.estado', 'completada');

        $id = $response->json('compra.id');
        $this->assertDatabaseHas('compras_creditos', [
            'id' => $id, 'proveedor_id' => $proveedor->id,
            'paquete_id' => $paquete->id, 'creditos_otorgados' => 30,
        ]);
        $this->assertDatabaseHas('creditos_proveedor', [
            'proveedor_id' => $proveedor->id, 'saldo' => 32,
        ]);
        $this->assertDatabaseHas('transacciones_credito', [
            'proveedor_id' => $proveedor->id, 'tipo' => 'compra',
            'monto' => 30, 'referencia_id' => $id,
        ]);
    }

    public function test_I3_leer_notificacion_actualiza_persistencia_y_contador(): void
    {
        $user = User::factory()->create(['role' => 'cliente']);
        $notif = $this->notificacion($user);
        Sanctum::actingAs($user);

        $this->getJson('/api/notificaciones')->assertOk()->assertJsonPath('no_leidas', 1);
        $this->putJson("/api/notificaciones/{$notif->id}/leer")->assertOk();
        $this->assertTrue($notif->fresh()->leida);
        $this->getJson('/api/notificaciones')->assertOk()->assertJsonPath('no_leidas', 0);
    }

    public function test_R1_pedido_conserva_expiracion_exacta_de_siete_dias(): void
    {
        $this->travelTo(now()->startOfSecond());
        try {
            $esperada = now()->addDays(7);
            $cliente = User::factory()->create(['role' => 'cliente']);
            $categoria = Categoria::factory()->create();
            Sanctum::actingAs($cliente);

            $response = $this->postJson('/api/pedidos', $this->datosPedido($categoria))
                ->assertCreated();
            $pedido = Pedido::findOrFail($response->json('pedido.id'));
            $this->assertTrue($pedido->fecha_expiracion->equalTo($esperada),
                'El pedido debe expirar exactamente siete dias despues de su creacion.');
            $this->assertSame($esperada->toIso8601String(), $response->json('pedido.fecha_expiracion'));
        } finally {
            $this->travelBack();
        }
    }

    public function test_R2_reintento_de_compra_no_duplica_acreditacion(): void
    {
        [$proveedor, $paquete] = $this->compraFixture();
        Sanctum::actingAs($proveedor->user);
        $datos = ['paquete_id' => $paquete->id, 'idempotency_key' => 'tarea5-reintento'];
        $primera = $this->postJson('/api/creditos/comprar', $datos)->assertCreated();
        $segunda = $this->postJson('/api/creditos/comprar', $datos)->assertOk();

        $this->assertSame($primera->json('compra.id'), $segunda->json('compra.id'));
        $this->assertSame(32, (int) CreditoProveedor::where('proveedor_id', $proveedor->id)->firstOrFail()->saldo);
        $this->assertSame(1, CompraCredito::where('proveedor_id', $proveedor->id)->count());
        $this->assertSame(1, TransaccionCredito::where('proveedor_id', $proveedor->id)->where('tipo', 'compra')->count());
    }

    public function test_R3_usuario_ajeno_no_puede_leer_notificacion(): void
    {
        $dueno = User::factory()->create(['role' => 'cliente']);
        $ajeno = User::factory()->create(['role' => 'cliente']);
        $notif = $this->notificacion($dueno);
        Sanctum::actingAs($ajeno);

        $this->putJson("/api/notificaciones/{$notif->id}/leer")->assertNotFound();
        $this->assertFalse($notif->fresh()->leida);
        $this->getJson('/api/notificaciones')->assertOk()
            ->assertJsonPath('no_leidas', 0)->assertJsonCount(0, 'notificaciones');
    }

    private function datosPedido(Categoria $categoria): array
    {
        return [
            'descripcion' => 'Tarea5: reparar la instalacion electrica de la vivienda',
            'categoria_id' => $categoria->id,
            'direccion' => 'Zona 10, Guatemala', 'urgencia' => 'alta',
        ];
    }

    private function compraFixture(): array
    {
        $user = User::factory()->create(['role' => 'proveedor']);
        $categoria = Categoria::factory()->create();
        $proveedor = Proveedor::create([
            'user_id' => $user->id, 'nombre' => $user->name, 'email' => $user->email,
            'departamento' => 'Guatemala', 'municipio' => 'Guatemala',
            'categoria_id' => $categoria->id, 'descripcion' => 'Proveedor sintetico Tarea5',
        ]);
        CreditoProveedor::create(['proveedor_id' => $proveedor->id, 'saldo' => 2, 'updated_at' => now()]);
        $paquete = PaqueteCredito::create([
            'nombre' => 'Tarea5 Impulso', 'precio_gtq' => 115,
            'creditos_base' => 25, 'creditos_bonus' => 5, 'activo' => true, 'orden' => 99,
        ]);
        return [$proveedor->setRelation('user', $user), $paquete];
    }

    private function notificacion(User $user): Notificacion
    {
        return Notificacion::create([
            'destinatario_id' => $user->id, 'tipo' => 'nueva_solicitud',
            'titulo' => 'Tarea5', 'mensaje' => 'Aviso sintetico',
            'datos' => ['servicio_id' => 41], 'leida' => false,
        ]);
    }
}
