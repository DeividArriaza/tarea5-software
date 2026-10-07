<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cotizacion;
use App\Models\CreditoProveedor;
use App\Models\PaqueteCredito;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 7.1 — La monetizacion no rompe servicios ni cotizaciones.
 *
 * Recorre Flow A y Flow B completos con proveedores que compraron creditos y
 * tienen Premium vigente, y verifica en ambos sentidos:
 *   - monetizar no altera el ciclo de un servicio ni el precio de un slot;
 *   - operar un servicio o adjudicar un pedido no altera saldo ni vigencia.
 *
 * Nota para quien extienda esta suite: el campo del codigo de inicio y de fin
 * se envia como `codigo`, no como `codigo_inicio` ni `codigo_fin`
 * (ServicioController::iniciar y ::confirmarFin).
 */
class MonetizacionNoRompeFlujosTest extends TestCase
{
    use DatabaseTransactions;

    private Categoria $categoria;
    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create([
            'nombre'      => 'Categoria e2e monetizacion ' . uniqid(),
            'descripcion' => 'Categoria para pruebas integrales de monetizacion',
        ]);

        $this->cliente = $this->crearUsuario('cliente');
    }

    public function test_flow_a_completo_con_proveedor_que_compro_creditos_y_tiene_premium(): void
    {
        $proveedor = $this->crearProveedor();
        $this->comprarPaquete($proveedor, 'Inicial');   // +8
        $this->activarPremium($proveedor);              // +10
        $saldoAntes = $this->saldoDe($proveedor);
        $venceAntes = $proveedor->fresh()->premium_vence_at;

        $this->assertSame(18, $saldoAntes, 'Compra y bono Premium deben sumar 8 + 10.');

        // A1 — el cliente solicita el servicio
        Sanctum::actingAs($this->cliente);
        $servicioId = $this->postJson('/api/servicios', [
            'proveedor_id' => $proveedor->id,
            'categoria_id' => $this->categoria->id,
            'descripcion'  => 'Servicio integral para validar Flow A con proveedor monetizado',
            'direccion'    => 'Zona 10, Ciudad de Guatemala',
        ])->assertCreated()->json('servicio.id');

        // A2 — el proveedor acepta
        Sanctum::actingAs($proveedor->user);
        $this->postJson("/api/servicios/{$servicioId}/aceptar")->assertOk();

        // A3 — el cliente lee el codigo de inicio
        Sanctum::actingAs($this->cliente);
        $codigoInicio = $this->getJson("/api/servicios/{$servicioId}")
            ->assertOk()->json('servicio.codigo_inicio');
        $this->assertNotEmpty($codigoInicio);

        // A4 y A5 — el proveedor inicia y finaliza
        Sanctum::actingAs($proveedor->user);
        $this->postJson("/api/servicios/{$servicioId}/iniciar", ['codigo' => $codigoInicio])->assertOk();
        $codigoFin = $this->postJson("/api/servicios/{$servicioId}/finalizar")
            ->assertOk()->json('codigo_fin');

        // A6 — el cliente confirma
        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/servicios/{$servicioId}/confirmar-fin", ['codigo' => $codigoFin])->assertOk();

        $this->assertDatabaseHas('servicios', ['id' => $servicioId, 'estado' => 'completado']);

        // El ciclo de servicio no toca creditos ni vigencia Premium.
        $this->assertSame($saldoAntes, $this->saldoDe($proveedor), 'Flow A no debe mover el saldo.');
        $this->assertEquals($venceAntes, $proveedor->fresh()->premium_vence_at, 'Flow A no debe mover la vigencia.');
        $this->assertSame('activo', $proveedor->fresh()->premiumEstado());
    }

    public function test_comprar_creditos_no_altera_el_precio_de_los_slots(): void
    {
        $pedido = $this->crearPedido();

        // 3 slots gratuitos ocupados por proveedores sin compras.
        for ($slot = 1; $slot <= 3; $slot++) {
            $otro = $this->crearProveedor();
            Sanctum::actingAs($otro->user);
            $this->cotizar($pedido, $slot)->assertCreated();
        }

        $comprador = $this->crearProveedor();
        $this->comprarPaquete($comprador, 'Impulso');   // +30
        $saldoAntes = $this->saldoDe($comprador);
        $this->assertSame(30, $saldoAntes);

        Sanctum::actingAs($comprador->user);
        $this->cotizar($pedido, 4)
            ->assertCreated()
            ->assertJsonPath('cotizacion.costo_creditos', 1)
            ->assertJsonPath('nuevo_saldo', 29);

        // Comprar 30 creditos no compra descuentos: el slot sigue costando 1.
        $this->assertSame(29, $this->saldoDe($comprador));
    }

    public function test_adjudicar_en_flow_b_no_toca_saldo_ni_vigencia_premium_del_ganador(): void
    {
        $pedido = $this->crearPedido();

        $ganador = $this->crearProveedor();
        $this->activarPremium($ganador);
        $saldoAntes = $this->saldoDe($ganador);
        $venceAntes = $ganador->fresh()->premium_vence_at;

        Sanctum::actingAs($ganador->user);
        $cotizacionId = $this->cotizar($pedido, 1)->assertCreated()->json('cotizacion.id');

        // Un perdedor, para que la adjudicacion tambien recorra el rechazo.
        $perdedor = $this->crearProveedor();
        Sanctum::actingAs($perdedor->user);
        $this->cotizar($pedido, 2)->assertCreated();

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/pedidos/{$pedido->id}/cotizaciones/{$cotizacionId}/aceptar")->assertOk();

        // El servicio nacio y el pedido quedo adjudicado.
        $this->assertDatabaseHas('pedidos', ['id' => $pedido->id, 'estado' => 'adjudicado']);
        $this->assertDatabaseHas('cotizaciones', ['id' => $cotizacionId, 'estado' => 'aceptada']);
        $this->assertSame(1, Servicio::where('proveedor_id', $ganador->id)->count());

        // Adjudicar no acredita, no cobra y no extiende Premium.
        $this->assertSame($saldoAntes, $this->saldoDe($ganador));
        $this->assertEquals($venceAntes, $ganador->fresh()->premium_vence_at);
        $this->assertSame(1, (int) $ganador->fresh()->premium_renovaciones);
    }

    public function test_activar_premium_no_altera_un_servicio_en_curso(): void
    {
        $proveedor = $this->crearProveedor();

        Sanctum::actingAs($this->cliente);
        $servicioId = $this->postJson('/api/servicios', [
            'proveedor_id' => $proveedor->id,
            'categoria_id' => $this->categoria->id,
            'descripcion'  => 'Servicio en curso para verificar que activar Premium no lo altera',
            'direccion'    => 'Zona 15, Ciudad de Guatemala',
        ])->assertCreated()->json('servicio.id');

        Sanctum::actingAs($proveedor->user);
        $this->postJson("/api/servicios/{$servicioId}/aceptar")->assertOk();

        $antes = Servicio::find($servicioId);
        Sanctum::actingAs($this->cliente);
        $codigoInicio = $this->getJson("/api/servicios/{$servicioId}")->json('servicio.codigo_inicio');

        // Premium se activa en medio del servicio.
        $this->activarPremium($proveedor);

        $despues = Servicio::find($servicioId);
        $this->assertSame($antes->estado, $despues->estado, 'Activar Premium no puede cambiar el estado.');
        $this->assertSame($antes->codigo_inicio, $despues->codigo_inicio, 'Ni invalidar el codigo de inicio.');

        // Y el servicio sigue completable con el codigo original.
        Sanctum::actingAs($proveedor->user);
        $this->postJson("/api/servicios/{$servicioId}/iniciar", ['codigo' => $codigoInicio])->assertOk();
        $codigoFin = $this->postJson("/api/servicios/{$servicioId}/finalizar")->assertOk()->json('codigo_fin');

        Sanctum::actingAs($this->cliente);
        $this->postJson("/api/servicios/{$servicioId}/confirmar-fin", ['codigo' => $codigoFin])->assertOk();

        $this->assertDatabaseHas('servicios', ['id' => $servicioId, 'estado' => 'completado']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function crearPedido(): Pedido
    {
        return Pedido::create([
            'cliente_id'       => $this->cliente->id,
            'categoria_id'     => $this->categoria->id,
            'descripcion'      => 'Pedido integral para verificar monetizacion contra el Flow B',
            'direccion'        => 'Zona 4, Ciudad de Guatemala',
            'urgencia'         => 'media',
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ]);
    }

    private function cotizar(Pedido $pedido, int $slot)
    {
        return $this->postJson("/api/pedidos/{$pedido->id}/cotizaciones", [
            'monto'   => 100 * $slot,
            'mensaje' => "Cotizacion integral numero {$slot} para el pedido en prueba.",
        ]);
    }

    /** Compra por el endpoint real, para cubrir acreditacion e idempotencia. */
    private function comprarPaquete(Proveedor $proveedor, string $nombrePaquete): void
    {
        $paquete = PaqueteCredito::where('nombre', $nombrePaquete)->firstOrFail();

        Sanctum::actingAs($proveedor->user);
        $this->postJson('/api/creditos/comprar', [
            'paquete_id'      => $paquete->id,
            'idempotency_key' => 'e2e-' . uniqid('', true),
        ])->assertCreated()->assertJsonPath('compra.estado', 'completada');
    }

    private function activarPremium(Proveedor $proveedor): void
    {
        Sanctum::actingAs($proveedor->user);
        $this->postJson('/api/premium/activar')
            ->assertOk()
            ->assertJsonPath('estado', 'activo')
            ->assertJsonPath('creditos_acreditados', 10);
    }

    private function saldoDe(Proveedor $proveedor): int
    {
        return (int) (CreditoProveedor::where('proveedor_id', $proveedor->id)->first()?->saldo ?? 0);
    }

    private function crearUsuario(string $role): User
    {
        $uid = uniqid($role . '.', true);

        return User::create([
            'name'     => ucfirst($role) . ' Test',
            'email'    => "{$uid}@servigt.test",
            'password' => 'password-test',
            'role'     => $role,
        ]);
    }

    private function crearProveedor(int $saldo = 0): Proveedor
    {
        $user = $this->crearUsuario('proveedor');

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => $this->categoria->id,
            'descripcion'  => 'Proveedor para pruebas integrales de monetizacion',
        ]);

        CreditoProveedor::create([
            'proveedor_id' => $proveedor->id,
            'saldo'        => $saldo,
            'updated_at'   => now(),
        ]);

        return $proveedor->setRelation('user', $user);
    }
}
