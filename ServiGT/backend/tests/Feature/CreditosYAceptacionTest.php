<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cotizacion;
use App\Models\CreditoProveedor;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditosYAceptacionTest extends TestCase
{
    use DatabaseTransactions;

    private Categoria $categoria;
    private User $cliente;
    private Pedido $pedido;
    private int $secuencia = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoria = Categoria::create([
            'nombre'      => 'Categoria test ' . uniqid(),
            'descripcion' => 'Categoria para pruebas del marketplace',
        ]);

        $this->cliente = $this->crearUsuario('cliente');
        $this->pedido = Pedido::create([
            'cliente_id'       => $this->cliente->id,
            'categoria_id'     => $this->categoria->id,
            'descripcion'      => 'Reparacion electrica completa para vivienda de prueba',
            'direccion'        => 'Zona 10, Ciudad de Guatemala',
            'urgencia'         => 'media',
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ]);
    }

    public function test_aceptar_cotizacion_crea_servicio_y_actualiza_estados(): void
    {
        $ganador = $this->crearProveedor();
        $otroProveedor = $this->crearProveedor();
        $cotizacionGanadora = $this->crearCotizacion($ganador, 475.50);
        $cotizacionPerdedora = $this->crearCotizacion($otroProveedor, 520.00);

        Sanctum::actingAs($this->cliente);

        $response = $this->postJson(
            "/api/pedidos/{$this->pedido->id}/cotizaciones/{$cotizacionGanadora->id}/aceptar"
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pedido.estado', 'adjudicado')
            ->assertJsonPath('cotizacion.estado', 'aceptada')
            ->assertJsonPath('servicio.cliente_id', $this->cliente->id)
            ->assertJsonPath('servicio.proveedor_id', $ganador->id)
            ->assertJsonPath('servicio.categoria_id', $this->categoria->id)
            ->assertJsonPath('servicio.direccion', $this->pedido->direccion)
            ->assertJsonPath('servicio.descripcion', $this->pedido->descripcion)
            ->assertJsonPath('servicio.monto_acordado', '475.50')
            ->assertJsonPath('servicio.estado', 'aceptado');

        $servicio = Servicio::sole();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $servicio->codigo_inicio);
        $this->assertDatabaseHas('pedidos', [
            'id'     => $this->pedido->id,
            'estado' => 'adjudicado',
        ]);
        $this->assertDatabaseHas('cotizaciones', [
            'id'     => $cotizacionGanadora->id,
            'estado' => 'aceptada',
        ]);
        $this->assertDatabaseHas('cotizaciones', [
            'id'     => $cotizacionPerdedora->id,
            'estado' => 'rechazada',
        ]);
    }

    public function test_usuario_que_no_es_dueno_no_puede_aceptar_cotizacion(): void
    {
        $cotizacion = $this->crearCotizacion($this->crearProveedor(), 350.00);

        Sanctum::actingAs($this->crearUsuario('cliente'));

        $this->postJson(
            "/api/pedidos/{$this->pedido->id}/cotizaciones/{$cotizacion->id}/aceptar"
        )->assertForbidden();

        $this->assertDatabaseCount('servicios', 0);
        $this->assertDatabaseHas('pedidos', [
            'id'     => $this->pedido->id,
            'estado' => 'abierto',
        ]);
        $this->assertDatabaseHas('cotizaciones', [
            'id'     => $cotizacion->id,
            'estado' => 'enviada',
        ]);
    }

    public function test_primeras_tres_cotizaciones_son_gratuitas(): void
    {
        for ($slot = 1; $slot <= 3; $slot++) {
            $proveedor = $this->crearProveedor(saldo: 2);

            Sanctum::actingAs($proveedor->user);

            $response = $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
                'monto'   => 100 * $slot,
                'mensaje' => "Cotizacion valida para ocupar el slot gratuito numero {$slot}.",
            ]);

            $response->assertCreated()
                ->assertJsonPath('cotizacion.costo_creditos', 0);

            $this->assertDatabaseHas('creditos_proveedor', [
                'proveedor_id' => $proveedor->id,
                'saldo'        => 2,
            ]);
        }

        $this->assertDatabaseCount('cotizaciones', 3);
        $this->assertDatabaseCount('transacciones_credito', 0);
    }

    public function test_cuarta_cotizacion_descuenta_un_credito_y_registra_transaccion(): void
    {
        for ($slot = 1; $slot <= 3; $slot++) {
            $this->crearCotizacion($this->crearProveedor(), 100 * $slot);
        }

        $proveedor = $this->crearProveedor(saldo: 2);
        Sanctum::actingAs($proveedor->user);

        $response = $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => 450,
            'mensaje' => 'Cuarta cotizacion que debe consumir un credito del proveedor.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('cotizacion.costo_creditos', 1)
            ->assertJsonPath('nuevo_saldo', 1);

        $this->assertDatabaseHas('creditos_proveedor', [
            'proveedor_id' => $proveedor->id,
            'saldo'        => 1,
        ]);
        $this->assertDatabaseHas('transacciones_credito', [
            'proveedor_id'  => $proveedor->id,
            'tipo'          => 'gasto',
            'monto'         => 1,
            'referencia_id' => $this->pedido->id,
        ]);
    }

    public function test_septima_cotizacion_se_bloquea_por_limite_maximo(): void
    {
        for ($slot = 1; $slot <= 6; $slot++) {
            $this->crearCotizacion($this->crearProveedor(saldo: 2), 100 * $slot);
        }

        $proveedor = $this->crearProveedor(saldo: 2);
        Sanctum::actingAs($proveedor->user);

        $response = $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => 700,
            'mensaje' => 'Septima cotizacion que debe ser rechazada por limite maximo.',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        $this->assertDatabaseCount('cotizaciones', 6);
        $this->assertDatabaseHas('creditos_proveedor', [
            'proveedor_id' => $proveedor->id,
            'saldo'        => 2,
        ]);
    }

    public function test_cotizacion_pagada_sin_saldo_se_bloquea(): void
    {
        for ($slot = 1; $slot <= 3; $slot++) {
            $this->crearCotizacion($this->crearProveedor(), 100 * $slot);
        }

        $proveedor = $this->crearProveedor(saldo: 0);
        Sanctum::actingAs($proveedor->user);

        $response = $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => 450,
            'mensaje' => 'Cuarta cotizacion sin saldo suficiente para cubrir el costo.',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        $this->assertDatabaseCount('cotizaciones', 3);
        $this->assertDatabaseCount('transacciones_credito', 0);
        $this->assertDatabaseHas('creditos_proveedor', [
            'proveedor_id' => $proveedor->id,
            'saldo'        => 0,
        ]);
    }

    public function test_aceptar_cotizacion_pagada_crea_servicio_y_mantiene_credito_descontado(): void
    {
        for ($slot = 1; $slot <= 3; $slot++) {
            $this->crearCotizacion($this->crearProveedor(), 100 * $slot);
        }

        $ganador = $this->crearProveedor(saldo: 2);
        Sanctum::actingAs($ganador->user);

        $envio = $this->postJson("/api/pedidos/{$this->pedido->id}/cotizaciones", [
            'monto'   => 480,
            'mensaje' => 'Cuarta cotizacion pagada que luego sera aceptada por el cliente.',
        ]);

        $envio->assertCreated()
            ->assertJsonPath('cotizacion.costo_creditos', 1)
            ->assertJsonPath('nuevo_saldo', 1);

        $cotizacionGanadora = Cotizacion::where('pedido_id', $this->pedido->id)
            ->where('proveedor_id', $ganador->id)
            ->sole();

        Sanctum::actingAs($this->cliente);

        $aceptacion = $this->postJson(
            "/api/pedidos/{$this->pedido->id}/cotizaciones/{$cotizacionGanadora->id}/aceptar"
        );

        $aceptacion->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('pedido.estado', 'adjudicado')
            ->assertJsonPath('cotizacion.estado', 'aceptada')
            ->assertJsonPath('servicio.proveedor_id', $ganador->id)
            ->assertJsonPath('servicio.monto_acordado', '480.00');

        $this->assertDatabaseCount('servicios', 1);
        // El credito ya cobrado al enviar la cotizacion no se reembolsa al aceptarla.
        $this->assertDatabaseHas('creditos_proveedor', [
            'proveedor_id' => $ganador->id,
            'saldo'        => 1,
        ]);
        $this->assertDatabaseCount('transacciones_credito', 1);
    }

    public function test_no_se_puede_aceptar_cotizacion_de_pedido_ya_adjudicado(): void
    {
        $primeraGanadora = $this->crearCotizacion($this->crearProveedor(), 300.00);
        $segunda = $this->crearCotizacion($this->crearProveedor(), 350.00);

        Sanctum::actingAs($this->cliente);

        $this->postJson(
            "/api/pedidos/{$this->pedido->id}/cotizaciones/{$primeraGanadora->id}/aceptar"
        )->assertOk();

        $response = $this->postJson(
            "/api/pedidos/{$this->pedido->id}/cotizaciones/{$segunda->id}/aceptar"
        );

        $response->assertStatus(422)->assertJsonPath('success', false);

        $this->assertDatabaseCount('servicios', 1);
        $this->assertDatabaseHas('cotizaciones', [
            'id'     => $segunda->id,
            'estado' => 'rechazada',
        ]);
    }

    public function test_no_se_puede_aceptar_pedido_inexistente(): void
    {
        $cotizacion = $this->crearCotizacion($this->crearProveedor(), 300.00);

        Sanctum::actingAs($this->cliente);

        $this->postJson(
            "/api/pedidos/999999/cotizaciones/{$cotizacion->id}/aceptar"
        )->assertStatus(404);

        $this->assertDatabaseCount('servicios', 0);
    }

    public function test_no_se_puede_aceptar_cotizacion_de_otro_pedido(): void
    {
        $otroPedido = Pedido::create([
            'cliente_id'       => $this->cliente->id,
            'categoria_id'     => $this->categoria->id,
            'descripcion'      => 'Otro pedido distinto para probar aislamiento entre pedidos',
            'direccion'        => 'Zona 4, Ciudad de Guatemala',
            'urgencia'         => 'baja',
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ]);

        $cotizacionDeOtroPedido = Cotizacion::create([
            'pedido_id'      => $otroPedido->id,
            'proveedor_id'   => $this->crearProveedor()->id,
            'monto'          => 300,
            'mensaje'        => 'Cotizacion enviada al otro pedido, no al pedido principal de la prueba.',
            'estado'         => 'enviada',
            'costo_creditos' => 0,
        ]);

        Sanctum::actingAs($this->cliente);

        $this->postJson(
            "/api/pedidos/{$this->pedido->id}/cotizaciones/{$cotizacionDeOtroPedido->id}/aceptar"
        )->assertStatus(404);

        $this->assertDatabaseCount('servicios', 0);
        $this->assertDatabaseHas('cotizaciones', [
            'id'     => $cotizacionDeOtroPedido->id,
            'estado' => 'enviada',
        ]);
    }

    public function test_no_se_puede_aceptar_cotizacion_que_no_esta_enviada(): void
    {
        $cotizacion = $this->crearCotizacion($this->crearProveedor(), 300.00);
        $cotizacion->update(['estado' => 'retirada']);

        Sanctum::actingAs($this->cliente);

        $response = $this->postJson(
            "/api/pedidos/{$this->pedido->id}/cotizaciones/{$cotizacion->id}/aceptar"
        );

        $response->assertStatus(422)->assertJsonPath('success', false);

        $this->assertDatabaseCount('servicios', 0);
        $this->assertDatabaseHas('cotizaciones', [
            'id'     => $cotizacion->id,
            'estado' => 'retirada',
        ]);
    }

    public function test_adjudicar_notifica_al_ganador_y_a_los_no_seleccionados(): void
    {
        $ganador   = $this->crearProveedor();
        $perdedor1 = $this->crearProveedor();
        $perdedor2 = $this->crearProveedor();

        $cotizacionGanadora = $this->crearCotizacion($ganador, 475.50);
        $cotizacionPerdedora1 = $this->crearCotizacion($perdedor1, 520.00);
        $cotizacionPerdedora2 = $this->crearCotizacion($perdedor2, 610.00);

        Sanctum::actingAs($this->cliente);

        $this->postJson(
            "/api/pedidos/{$this->pedido->id}/cotizaciones/{$cotizacionGanadora->id}/aceptar"
        )->assertOk();

        $servicio = Servicio::sole();

        // El ganador recibe la notificacion con los tres ids acordados.
        $this->assertDatabaseHas('notificaciones', [
            'destinatario_id' => $ganador->user->id,
            'tipo'            => 'cotizacion_aceptada',
        ]);

        $notifGanador = Notificacion::where('destinatario_id', $ganador->user->id)
            ->where('tipo', 'cotizacion_aceptada')
            ->sole();

        $this->assertSame($this->pedido->id, $notifGanador->datos['pedido_id']);
        $this->assertSame($cotizacionGanadora->id, $notifGanador->datos['cotizacion_id']);
        $this->assertSame($servicio->id, $notifGanador->datos['servicio_id']);

        // Cada perdedor recibe la suya, referida a su propia cotizacion.
        foreach ([[$perdedor1, $cotizacionPerdedora1], [$perdedor2, $cotizacionPerdedora2]] as [$prov, $cot]) {
            $notif = Notificacion::where('destinatario_id', $prov->user->id)
                ->where('tipo', 'cotizacion_rechazada')
                ->sole();

            $this->assertSame($this->pedido->id, $notif->datos['pedido_id']);
            $this->assertSame($cot->id, $notif->datos['cotizacion_id']);
        }

        // El cliente no se notifica a si mismo.
        $this->assertDatabaseMissing('notificaciones', [
            'destinatario_id' => $this->cliente->id,
            'tipo'            => 'cotizacion_rechazada',
        ]);
    }

    public function test_proveedor_que_retiro_su_cotizacion_no_recibe_notificacion_de_rechazo(): void
    {
        $ganador  = $this->crearProveedor();
        $retirado = $this->crearProveedor();

        $cotizacionGanadora = $this->crearCotizacion($ganador, 300.00);
        $this->crearCotizacion($retirado, 450.00)->update(['estado' => 'retirada']);

        Sanctum::actingAs($this->cliente);

        $this->postJson(
            "/api/pedidos/{$this->pedido->id}/cotizaciones/{$cotizacionGanadora->id}/aceptar"
        )->assertOk();

        $this->assertDatabaseMissing('notificaciones', [
            'destinatario_id' => $retirado->user->id,
        ]);
    }

    private function crearUsuario(string $role): User
    {
        $this->secuencia++;

        return User::create([
            'name'     => ucfirst($role) . " Test {$this->secuencia}",
            'email'    => "{$role}.{$this->secuencia}." . uniqid() . '@servigt.test',
            'password' => 'password-test',
            'role'     => $role,
        ]);
    }

    private function crearProveedor(int $saldo = 0): Proveedor
    {
        $user = $this->crearUsuario('proveedor');
        $proveedor = Proveedor::create([
            'user_id'       => $user->id,
            'nombre'        => $user->name,
            'email'         => $user->email,
            'departamento'  => 'Guatemala',
            'municipio'     => 'Guatemala',
            'categoria_id'  => $this->categoria->id,
            'descripcion'   => 'Proveedor creado para pruebas automatizadas',
        ]);

        CreditoProveedor::create([
            'proveedor_id' => $proveedor->id,
            'saldo'        => $saldo,
            'updated_at'   => now(),
        ]);

        return $proveedor->setRelation('user', $user);
    }

    private function crearCotizacion(Proveedor $proveedor, float $monto): Cotizacion
    {
        return Cotizacion::create([
            'pedido_id'      => $this->pedido->id,
            'proveedor_id'   => $proveedor->id,
            'monto'          => $monto,
            'mensaje'        => 'Propuesta de trabajo creada para pruebas automatizadas.',
            'estado'         => 'enviada',
            'costo_creditos' => 0,
        ]);
    }
}
