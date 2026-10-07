<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CotizacionCreationTest extends TestCase
{
    use DatabaseTransactions;

    private Categoria $categoria;
    private Pedido $pedido;
    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create([
            'nombre'      => 'Categoria test ' . uniqid(),
            'descripcion' => 'Categoria para pruebas de cotizaciones',
        ]);
        $this->pedido = $this->crearPedido();
    }

    public function test_proveedor_autenticado_puede_enviar_cotizacion_valida(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $response = $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => 350,
            'mensaje' => 'Puedo realizar el trabajo esta misma semana con garantia.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('cotizacion.costo_creditos', 0)
            ->assertJsonPath('cotizacion.estado', 'enviada');

        $this->assertDatabaseHas('cotizaciones', [
            'pedido_id'    => $this->pedido->id,
            'proveedor_id' => $proveedor->id,
        ]);
    }

    public function test_usuario_no_autenticado_no_puede_cotizar(): void
    {
        $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => 350,
            'mensaje' => 'Cotizacion enviada sin sesion iniciada.',
        ])->assertStatus(401);
    }

    public function test_proveedor_no_puede_cotizar_dos_veces_el_mismo_pedido(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => 300,
            'mensaje' => 'Primera cotizacion enviada para este pedido de prueba.',
        ])->assertStatus(201);

        $response = $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => 320,
            'mensaje' => 'Segunda cotizacion duplicada para el mismo pedido.',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        $this->assertDatabaseCount('cotizaciones', 1);
    }

    public function test_no_se_puede_cotizar_un_pedido_cerrado(): void
    {
        $pedido = $this->crearPedido(['estado' => 'cerrado']);
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->postJson("/api/pedidos/{$pedido->id}/cotizaciones", [
            'monto'   => 300,
            'mensaje' => 'Intento de cotizar un pedido que ya no esta abierto.',
        ])->assertStatus(404);
    }

    public function test_no_se_puede_cotizar_un_pedido_expirado(): void
    {
        $pedido = $this->crearPedido(['fecha_expiracion' => now()->subHour()]);
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->postJson("/api/pedidos/{$pedido->id}/cotizaciones", [
            'monto'   => 300,
            'mensaje' => 'Intento de cotizar un pedido que ya expiro por fecha.',
        ])->assertStatus(404);
    }

    public function test_no_se_puede_cotizar_un_pedido_inexistente(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->postJson('/api/pedidos/999999/cotizaciones', [
            'monto'   => 300,
            'mensaje' => 'Intento de cotizar un pedido que no existe en la base de datos.',
        ])->assertStatus(404);

        $this->assertDatabaseCount('cotizaciones', 0);
    }

    public function test_mensaje_demasiado_corto_falla_validacion(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => 300,
            'mensaje' => 'Muy corto',
        ])->assertStatus(422);
    }

    public function test_monto_invalido_falla_validacion(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => -10,
            'mensaje' => 'Cotizacion con un monto invalido para probar validacion.',
        ])->assertStatus(422);
    }

    private function crearPedido(array $overrides = []): Pedido
    {
        $this->secuencia++;

        $cliente = User::create([
            'name'     => "Cliente Test {$this->secuencia}",
            'email'    => "cliente.{$this->secuencia}." . uniqid() . '@servigt.test',
            'password' => 'password-test',
            'role'     => 'cliente',
        ]);

        return Pedido::create(array_merge([
            'cliente_id'       => $cliente->id,
            'categoria_id'     => $this->categoria->id,
            'descripcion'      => 'Descripcion de prueba suficientemente larga para el pedido',
            'direccion'        => 'Zona 1, Guatemala',
            'urgencia'         => 'media',
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ], $overrides));
    }

    private function crearProveedor(): Proveedor
    {
        $this->secuencia++;

        $user = User::create([
            'name'     => "Proveedor Test {$this->secuencia}",
            'email'    => "proveedor.{$this->secuencia}." . uniqid() . '@servigt.test',
            'password' => 'password-test',
            'role'     => 'proveedor',
        ]);

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => $this->categoria->id,
            'descripcion'  => 'Proveedor creado para pruebas automatizadas',
        ]);

        return $proveedor->setRelation('user', $user);
    }
}
