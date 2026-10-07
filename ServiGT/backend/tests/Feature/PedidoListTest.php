<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PedidoListTest extends TestCase
{
    // Ver nota en PedidoCreationTest: el esquema viene de database/init.sql.
    use DatabaseTransactions;

    private User $cliente;
    private Categoria $categoria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cliente   = User::factory()->create(['role' => 'cliente']);
        $this->categoria = Categoria::factory()->create();
    }

    private function crearPedido(array $overrides = []): Pedido
    {
        return Pedido::create(array_merge([
            'cliente_id'       => $this->cliente->id,
            'categoria_id'     => $this->categoria->id,
            'descripcion'      => 'Descripcion de prueba suficientemente larga',
            'direccion'        => 'Zona 1, Guatemala',
            'urgencia'         => 'baja',
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ], $overrides));
    }

    public function test_listado_abiertos_solo_muestra_pedidos_abiertos(): void
    {
        $this->crearPedido(['estado' => 'abierto']);
        $this->crearPedido(['estado' => 'adjudicado']);
        $this->crearPedido(['estado' => 'expirado']);
        $this->crearPedido(['estado' => 'cerrado']);

        $response = $this->getJson('/api/pedidos/abiertos');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $pedidos = $response->json('pedidos');
        $this->assertCount(1, $pedidos);
        $this->assertSame('abierto', $pedidos[0]['estado']);
    }

    public function test_listado_abiertos_excluye_expirados_por_fecha(): void
    {
        $this->crearPedido(['estado' => 'abierto', 'fecha_expiracion' => now()->subHour()]);
        $this->crearPedido(['estado' => 'abierto', 'fecha_expiracion' => now()->addDays(3)]);

        $pedidos = $this->getJson('/api/pedidos/abiertos')->json('pedidos');
        $this->assertCount(1, $pedidos);
    }

    public function test_listado_abiertos_filtra_por_categoria(): void
    {
        $otraCategoria = Categoria::factory()->create();
        $this->crearPedido(['categoria_id' => $this->categoria->id]);
        $this->crearPedido(['categoria_id' => $otraCategoria->id]);

        $pedidos = $this->getJson("/api/pedidos/abiertos?categoria_id={$this->categoria->id}")
            ->json('pedidos');

        $this->assertCount(1, $pedidos);
    }

    public function test_listado_abiertos_incluye_cotizaciones_count(): void
    {
        $this->crearPedido();

        $pedidos = $this->getJson('/api/pedidos/abiertos')->json('pedidos');
        $this->assertArrayHasKey('cotizaciones_count', $pedidos[0]);
    }

    public function test_mis_pedidos_requiere_autenticacion(): void
    {
        $this->getJson('/api/pedidos/mios')->assertStatus(401);
    }

    public function test_mis_pedidos_solo_devuelve_pedidos_del_usuario(): void
    {
        $otro = User::factory()->create();
        $this->crearPedido();
        Pedido::create([
            'cliente_id'       => $otro->id,
            'categoria_id'     => $this->categoria->id,
            'descripcion'      => 'Pedido de otro usuario para prueba',
            'direccion'        => 'Zona 3',
            'urgencia'         => 'baja',
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ]);

        $pedidos = $this->actingAs($this->cliente)
            ->getJson('/api/pedidos/mios')
            ->json('pedidos');

        $this->assertCount(1, $pedidos);
    }

    public function test_mis_pedidos_devuelve_cualquier_estado(): void
    {
        foreach (['abierto', 'expirado', 'cerrado', 'adjudicado'] as $estado) {
            $this->crearPedido(['estado' => $estado]);
        }

        $pedidos = $this->actingAs($this->cliente)
            ->getJson('/api/pedidos/mios')
            ->json('pedidos');

        $this->assertCount(4, $pedidos);
    }
}
