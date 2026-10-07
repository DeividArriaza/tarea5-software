<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CreditoProveedor;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\TransaccionCredito;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 5.5 — Premium no altera los slots de cotizacion.
 *
 * El Epic 5 promete que Premium NO cambia la regla de cotizaciones durante el
 * Sprint 6: 3 gratis por pedido, 1 credito de la 4ta a la 6ta, tope de 6.
 * Hoy la invariante se cumple por construccion, porque CotizacionController no
 * conoce Premium; esta suite existe para que siga siendo cierto manana.
 *
 * Matiz importante que estas pruebas separan: Premium SI cambia la capacidad de
 * pagar slots, porque entrega 10 creditos por ciclo. Lo que no puede cambiar es
 * el precio del slot ni el tope.
 */
class PremiumNoAlteraSlotsTest extends TestCase
{
    use DatabaseTransactions;

    private const MSG_SIN_SALDO = 'Saldo insuficiente para enviar esta cotización. Recarga créditos para continuar.';
    private const MSG_TOPE      = 'Este pedido ya alcanzó el máximo de 6 cotizaciones.';

    private Categoria $categoria;
    private User $cliente;
    private Pedido $pedido;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create([
            'nombre'      => 'Categoria premium slots ' . uniqid(),
            'descripcion' => 'Categoria para pruebas de slots con Premium',
        ]);

        $this->cliente = $this->crearUsuario('cliente');
        $this->pedido = Pedido::create([
            'cliente_id'       => $this->cliente->id,
            'categoria_id'     => $this->categoria->id,
            'descripcion'      => 'Pedido para verificar que Premium no altera los slots de cotizacion',
            'direccion'        => 'Zona 10, Ciudad de Guatemala',
            'urgencia'         => 'media',
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ]);
    }

    public function test_proveedor_premium_ocupa_slot_gratis_a_costo_cero(): void
    {
        $proveedor = $this->crearProveedorPremium();
        $saldoAntes = $this->saldoDe($proveedor); // 10 del bono del ciclo

        Sanctum::actingAs($proveedor->user);

        $this->cotizar(1)
            ->assertCreated()
            ->assertJsonPath('cotizacion.costo_creditos', 0);

        // El bono no se consume en un slot gratuito.
        $this->assertSame($saldoAntes, $this->saldoDe($proveedor));
        $this->assertSame(0, $this->gastosDe($proveedor));
    }

    public function test_proveedor_premium_paga_un_credito_en_el_cuarto_slot(): void
    {
        $this->ocuparSlotsGratuitos();

        $proveedor = $this->crearProveedorPremium();
        $saldoAntes = $this->saldoDe($proveedor);

        Sanctum::actingAs($proveedor->user);

        $this->cotizar(4)
            ->assertCreated()
            ->assertJsonPath('cotizacion.costo_creditos', 1)
            ->assertJsonPath('nuevo_saldo', $saldoAntes - 1);

        // Exactamente 1, ni 0 por ser Premium ni mas.
        $this->assertSame($saldoAntes - 1, $this->saldoDe($proveedor));
        $this->assertDatabaseHas('transacciones_credito', [
            'proveedor_id'  => $proveedor->id,
            'tipo'          => 'gasto',
            'monto'         => 1,
            'referencia_id' => $this->pedido->id,
        ]);
    }

    public function test_premium_no_cambia_el_precio_frente_a_un_proveedor_sin_premium(): void
    {
        $this->ocuparSlotsGratuitos();

        $conPremium = $this->crearProveedorPremium();
        $sinPremium = $this->crearProveedor(saldo: 10);

        Sanctum::actingAs($conPremium->user);
        $costoPremium = $this->cotizar(4)->assertCreated()->json('cotizacion.costo_creditos');

        Sanctum::actingAs($sinPremium->user);
        $costoNormal = $this->cotizar(5)->assertCreated()->json('cotizacion.costo_creditos');

        $this->assertSame($costoNormal, $costoPremium, 'Premium no puede pagar distinto por el mismo tipo de slot.');
        $this->assertSame(1, $costoPremium);
    }

    public function test_proveedor_premium_sin_saldo_queda_bloqueado_en_slot_pagado(): void
    {
        $this->ocuparSlotsGratuitos();

        $proveedor = $this->crearProveedorPremium();
        // Premium vigente pero sin creditos: se drena el bono a proposito.
        CreditoProveedor::where('proveedor_id', $proveedor->id)
            ->update(['saldo' => 0, 'updated_at' => now()]);

        Sanctum::actingAs($proveedor->user);

        $this->cotizar(4)
            ->assertStatus(422)
            ->assertJsonPath('message', self::MSG_SIN_SALDO);

        // Premium no regala el slot cuando no hay con que pagarlo.
        $this->assertDatabaseCount('cotizaciones', 3);
        $this->assertSame('activo', $proveedor->fresh()->premiumEstado());
    }

    public function test_el_tope_de_seis_se_mantiene_aunque_todos_sean_premium(): void
    {
        // 6 slots ocupados por proveedores Premium: 3 gratis y 3 pagados con bono.
        for ($slot = 1; $slot <= 6; $slot++) {
            $proveedor = $this->crearProveedorPremium();
            Sanctum::actingAs($proveedor->user);
            $this->cotizar($slot)->assertCreated();
        }

        $this->assertDatabaseCount('cotizaciones', 6);

        // El septimo tiene Premium y saldo de sobra: debe rebotar por el TOPE,
        // no por saldo. Distinguir los dos 422 es el punto de este test.
        $septimo = $this->crearProveedorPremium();
        CreditoProveedor::where('proveedor_id', $septimo->id)
            ->update(['saldo' => 99, 'updated_at' => now()]);

        Sanctum::actingAs($septimo->user);

        $this->cotizar(7)
            ->assertStatus(422)
            ->assertJsonPath('message', self::MSG_TOPE);

        $this->assertDatabaseCount('cotizaciones', 6);
        // No se cobro nada al rechazado.
        $this->assertSame(99, $this->saldoDe($septimo));
        $this->assertSame(0, $this->gastosDe($septimo));
    }

    public function test_mi_estado_declara_que_premium_no_altera_slots(): void
    {
        $proveedor = $this->crearProveedorPremium();

        Sanctum::actingAs($proveedor->user);

        $this->getJson('/api/premium/mi-estado')
            ->assertOk()
            ->assertJsonPath('estado', 'activo')
            ->assertJsonPath('creditos_por_ciclo', 10)
            ->assertJsonPath('nota_slots', 'Premium no modifica los slots de cotizacion durante Sprint 6.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Envia una cotizacion al pedido del setUp con el proveedor autenticado. */
    private function cotizar(int $slot)
    {
        return $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => 100 * $slot,
            'mensaje' => "Cotizacion de prueba para ocupar el slot numero {$slot} del pedido.",
        ]);
    }

    /** Llena los 3 slots gratuitos con proveedores sin Premium. */
    private function ocuparSlotsGratuitos(): void
    {
        for ($slot = 1; $slot <= 3; $slot++) {
            $proveedor = $this->crearProveedor();
            Sanctum::actingAs($proveedor->user);
            $this->cotizar($slot)->assertCreated();
        }
    }

    private function saldoDe(Proveedor $proveedor): int
    {
        return (int) (CreditoProveedor::where('proveedor_id', $proveedor->id)->first()?->saldo ?? 0);
    }

    private function gastosDe(Proveedor $proveedor): int
    {
        return TransaccionCredito::where('proveedor_id', $proveedor->id)
            ->where('tipo', 'gasto')
            ->count();
    }

    /**
     * Activa Premium por el endpoint real, no escribiendo columnas: asi la
     * prueba cubre tambien la acreditacion del bono de 10 creditos.
     */
    private function crearProveedorPremium(int $saldo = 0): Proveedor
    {
        $proveedor = $this->crearProveedor($saldo);

        Sanctum::actingAs($proveedor->user);
        $this->postJson('/api/premium/activar')
            ->assertOk()
            ->assertJsonPath('estado', 'activo')
            ->assertJsonPath('creditos_acreditados', 10);

        return $proveedor;
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
            'descripcion'  => 'Proveedor para pruebas de slots con Premium',
        ]);

        CreditoProveedor::create([
            'proveedor_id' => $proveedor->id,
            'saldo'        => $saldo,
            'updated_at'   => now(),
        ]);

        return $proveedor->setRelation('user', $user);
    }
}
