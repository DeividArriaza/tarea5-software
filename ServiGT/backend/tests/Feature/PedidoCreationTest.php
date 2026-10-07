<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PedidoCreationTest extends TestCase
{
    // El esquema de pruebas lo provee database/init.sql (fuente unica del
    // proyecto). No se usa RefreshDatabase porque migrate:fresh borraria las
    // 19 tablas de init.sql y las migraciones solo cubren 3 de ellas.
    use DatabaseTransactions;

    private User $cliente;
    private Categoria $categoria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente   = User::factory()->create(['role' => 'cliente']);
        $this->categoria = Categoria::factory()->create();
    }

    public function test_cliente_autenticado_puede_crear_pedido(): void
    {
        $response = $this->actingAs($this->cliente)
            ->postJson('/api/pedidos', [
                'descripcion'  => 'Necesito un electricista para mi casa urgente',
                'categoria_id' => $this->categoria->id,
                'direccion'    => 'Zona 10, Ciudad de Guatemala',
                'urgencia'     => 'alta',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('pedido.estado', 'abierto')
            ->assertJsonPath('pedido.urgencia', 'alta');

        $this->assertDatabaseHas('pedidos', [
            'cliente_id'   => $this->cliente->id,
            'categoria_id' => $this->categoria->id,
            'estado'        => 'abierto',
        ]);
    }

    public function test_usuario_no_autenticado_no_puede_crear_pedido(): void
    {
        $this->postJson('/api/pedidos', [
            'descripcion'  => 'Pedido sin auth',
            'categoria_id' => $this->categoria->id,
            'direccion'    => 'Zona 1',
            'urgencia'     => 'baja',
        ])->assertStatus(401);
    }

    public function test_descripcion_demasiado_corta_falla_validacion(): void
    {
        $this->actingAs($this->cliente)
            ->postJson('/api/pedidos', [
                'descripcion'  => 'Corto',
                'categoria_id' => $this->categoria->id,
                'direccion'    => 'Zona 1',
                'urgencia'     => 'baja',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_categoria_inexistente_falla_validacion(): void
    {
        $this->actingAs($this->cliente)
            ->postJson('/api/pedidos', [
                'descripcion'  => 'Necesito ayuda con plomeria en mi casa',
                'categoria_id' => 9999,
                'direccion'    => 'Zona 1',
                'urgencia'     => 'media',
            ])
            ->assertStatus(422);
    }

    public function test_urgencia_invalida_falla_validacion(): void
    {
        $this->actingAs($this->cliente)
            ->postJson('/api/pedidos', [
                'descripcion'  => 'Necesito ayuda con plomeria en mi casa',
                'categoria_id' => $this->categoria->id,
                'direccion'    => 'Zona 1',
                'urgencia'     => 'extrema',
            ])
            ->assertStatus(422);
    }

    public function test_pedido_se_crea_con_expiracion_7_dias(): void
    {
        $before = now()->addDays(6);
        $after  = now()->addDays(8);

        $this->actingAs($this->cliente)
            ->postJson('/api/pedidos', [
                'descripcion'  => 'Necesito carpintero para reparar puerta',
                'categoria_id' => $this->categoria->id,
                'direccion'    => 'Zona 5, Guatemala',
                'urgencia'     => 'media',
            ])
            ->assertStatus(201);

        $pedido = Pedido::latest()->first();
        $this->assertTrue($pedido->fecha_expiracion->between($before, $after));
    }
}
