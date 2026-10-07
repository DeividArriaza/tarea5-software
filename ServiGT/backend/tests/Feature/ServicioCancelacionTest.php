<?php

namespace Tests\Feature;

use App\Models\Proveedor;
use App\Models\Categoria;
use App\Models\Cotizacion;
use App\Models\Pedido;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServicioCancelacionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cliente_propietario_cancela_y_notifica_sin_reabrir_pedido(): void
    {
        [$servicio, $cliente, $proveedor] = $this->servicio('aceptado');
        Sanctum::actingAs($cliente);

        $this->postJson("/api/servicios/{$servicio->id}/cancelar", ['motivo' => 'Ya no lo necesito'])
            ->assertOk()
            ->assertJsonPath('servicio.estado', 'cancelado');

        $this->assertDatabaseHas('servicios', ['id' => $servicio->id, 'estado' => 'cancelado']);
        $this->assertDatabaseHas('notificaciones', [
            'destinatario_id' => $proveedor->user_id,
            'tipo' => 'servicio_cancelado',
        ]);

        $this->postJson("/api/servicios/{$servicio->id}/cancelar")->assertStatus(422);
        $this->assertDatabaseCount('notificaciones', 1);
    }

    public function test_proveedor_tercero_y_no_autenticado_no_pueden_cancelar(): void
    {
        [$servicio, $cliente] = $this->servicio('pendiente');
        $tercero = User::factory()->cliente()->create();

        $this->postJson("/api/servicios/{$servicio->id}/cancelar")->assertUnauthorized();
        Sanctum::actingAs($tercero);
        $this->postJson("/api/servicios/{$servicio->id}/cancelar")->assertForbidden();
        Sanctum::actingAs($servicio->proveedor->user);
        $this->postJson("/api/servicios/{$servicio->id}/cancelar")->assertForbidden();
    }

    public function test_servicio_inexistente_responde_404(): void
    {
        $cliente = User::factory()->cliente()->create();
        Sanctum::actingAs($cliente);

        $this->postJson('/api/servicios/999999/cancelar')->assertNotFound();
    }

    public function test_estado_general_no_puede_completar_ni_cancelar_y_en_camino_exige_aceptado(): void
    {
        [$servicio, , $proveedor] = $this->servicio('pendiente');
        Sanctum::actingAs($proveedor->user);
        $this->putJson("/api/servicios/{$servicio->id}/estado", ['estado' => 'completado'])->assertStatus(422);
        $this->putJson("/api/servicios/{$servicio->id}/estado", ['estado' => 'cancelado'])->assertStatus(422);
        $this->putJson("/api/servicios/{$servicio->id}/estado", ['estado' => 'en_camino'])->assertStatus(422);
        $servicio->update(['estado' => 'aceptado']);
        $this->putJson("/api/servicios/{$servicio->id}/estado", ['estado' => 'en_camino'])->assertOk();
        $this->postJson("/api/servicios/{$servicio->id}/iniciar", ['codigo' => $servicio->codigo_inicio])->assertOk();
    }

    public function test_servicio_de_cotizacion_se_cancela_sin_reabrir_pedido_ni_cotizacion(): void
    {
        $categoria = Categoria::create([
            'nombre' => 'Categoria Flow B ' . uniqid(),
            'descripcion' => 'Categoria para el flujo de cotizacion',
        ]);
        $cliente = User::factory()->cliente()->create();
        $proveedorUser = User::factory()->proveedor()->create();
        $proveedor = Proveedor::create([
            'user_id' => $proveedorUser->id,
            'nombre' => 'Proveedor Flow B',
            'email' => $proveedorUser->email,
            'departamento' => 'Guatemala',
            'categoria_id' => $categoria->id,
        ]);
        $pedido = Pedido::create([
            'cliente_id' => $cliente->id,
            'categoria_id' => $categoria->id,
            'descripcion' => 'Pedido Flow B para cancelacion',
            'direccion' => 'Zona 10',
            'urgencia' => 'media',
            'estado' => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ]);
        $cotizacion = Cotizacion::create([
            'pedido_id' => $pedido->id,
            'proveedor_id' => $proveedor->id,
            'monto' => 250,
            'mensaje' => 'Cotizacion para verificar cancelacion del Flow B',
            'estado' => 'enviada',
            'costo_creditos' => 0,
        ]);

        Sanctum::actingAs($cliente);
        $servicioId = $this->postJson(
            "/api/pedidos/{$pedido->id}/cotizaciones/{$cotizacion->id}/aceptar"
        )->assertOk()->json('servicio.id');

        $this->postJson("/api/servicios/{$servicioId}/cancelar")
            ->assertOk()
            ->assertJsonPath('servicio.estado', 'cancelado');

        $this->assertDatabaseHas('pedidos', ['id' => $pedido->id, 'estado' => 'adjudicado']);
        $this->assertDatabaseHas('cotizaciones', ['id' => $cotizacion->id, 'estado' => 'aceptada']);
        $this->assertDatabaseHas('notificaciones', [
            'destinatario_id' => $proveedorUser->id,
            'tipo' => 'servicio_cancelado',
        ]);
    }

    private function servicio(string $estado): array
    {
        $proveedorUser = User::factory()->proveedor()->create();
        $proveedor = Proveedor::create([
            'user_id' => $proveedorUser->id, 'nombre' => 'Proveedor Sprint 8',
            'email' => $proveedorUser->email, 'telefono' => '55550101',
            'descripcion' => 'Proveedor para pruebas de Sprint 8', 'departamento' => 'Guatemala',
        ]);
        $cliente = User::factory()->cliente()->create();
        $servicio = Servicio::create([
            'cliente_id' => $cliente->id, 'proveedor_id' => $proveedor->id,
            'descripcion' => 'Servicio de prueba de cancelacion', 'estado' => $estado,
            'codigo_inicio' => '123456',
        ]);
        return [$servicio->fresh(), $cliente, $proveedor->fresh(['user'])];
    }
}
