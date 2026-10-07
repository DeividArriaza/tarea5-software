<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Pedido;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PedidoExpirationTest extends TestCase
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

    public function test_comando_expira_pedidos_abiertos_vencidos(): void
    {
        $vencido  = $this->crearPedido(['fecha_expiracion' => now()->subHour()]);
        $vigente  = $this->crearPedido(['fecha_expiracion' => now()->addDays(3)]);

        $this->artisan('pedidos:expirar')->assertExitCode(0);

        $this->assertDatabaseHas('pedidos', ['id' => $vencido->id,  'estado' => 'expirado']);
        $this->assertDatabaseHas('pedidos', ['id' => $vigente->id,  'estado' => 'abierto']);
    }

    public function test_comando_no_modifica_pedidos_en_estados_finales(): void
    {
        // `adjudicado` en lugar de `cancelado`: init.sql solo admite
        // abierto/adjudicado/cerrado/expirado, y proteger un pedido ya
        // adjudicado del comando de expiracion es el caso que importa.
        $cerrado    = $this->crearPedido(['estado' => 'cerrado',    'fecha_expiracion' => now()->subDay()]);
        $adjudicado = $this->crearPedido(['estado' => 'adjudicado', 'fecha_expiracion' => now()->subDay()]);

        $this->artisan('pedidos:expirar')->assertExitCode(0);

        $this->assertDatabaseHas('pedidos', ['id' => $cerrado->id,    'estado' => 'cerrado']);
        $this->assertDatabaseHas('pedidos', ['id' => $adjudicado->id, 'estado' => 'adjudicado']);
    }

    public function test_comando_sin_pedidos_que_expirar_termina_exitosamente(): void
    {
        $this->artisan('pedidos:expirar')
            ->expectsOutput('0 pedido(s) expirados.')
            ->assertExitCode(0);
    }
}
