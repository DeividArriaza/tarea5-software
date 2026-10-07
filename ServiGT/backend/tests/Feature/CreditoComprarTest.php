<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CompraCredito;
use App\Models\CreditoProveedor;
use App\Models\PaqueteCredito;
use App\Models\Proveedor;
use App\Models\TransaccionCredito;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/creditos/comprar
 */
class CreditoComprarTest extends TestCase
{
    use DatabaseTransactions;

    public function test_compra_exitosa_acredita_saldo_y_registra_transaccion(): void
    {
        $proveedor = $this->crearProveedor(saldo: 2);
        $paquete = PaqueteCredito::where('nombre', 'Impulso')->first();

        Sanctum::actingAs($proveedor->user);

        $response = $this->postJson('/api/creditos/comprar', [
            'paquete_id'      => $paquete->id,
            'idempotency_key' => 'clave-' . uniqid(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('saldo', 32) // 2 + (25 base + 5 bonus)
            ->assertJsonPath('compra.estado', 'completada')
            ->assertJsonPath('compra.creditos_otorgados', 30);

        $this->assertMatchesRegularExpression('/^SGT-\d{10}$/', $response->json('compra.referencia'));

        $this->assertEquals(32, CreditoProveedor::where('proveedor_id', $proveedor->id)->first()->saldo);

        $this->assertDatabaseHas('transacciones_credito', [
            'proveedor_id' => $proveedor->id,
            'tipo'         => 'compra',
            'monto'        => 30,
        ]);
    }

    public function test_repetir_la_misma_idempotency_key_no_duplica_credito(): void
    {
        $proveedor = $this->crearProveedor(saldo: 0);
        $paquete = PaqueteCredito::where('nombre', 'Inicial')->first();
        $clave = 'clave-fija-' . uniqid();

        Sanctum::actingAs($proveedor->user);

        $primera = $this->postJson('/api/creditos/comprar', [
            'paquete_id'      => $paquete->id,
            'idempotency_key' => $clave,
        ]);
        $primera->assertCreated();

        $segunda = $this->postJson('/api/creditos/comprar', [
            'paquete_id'      => $paquete->id,
            'idempotency_key' => $clave,
        ]);
        $segunda->assertOk();

        $this->assertEquals(8, CreditoProveedor::where('proveedor_id', $proveedor->id)->first()->saldo);
        $this->assertEquals(1, CompraCredito::where('idempotency_key', $clave)->count());
        $this->assertEquals(
            1,
            TransaccionCredito::where('proveedor_id', $proveedor->id)->where('tipo', 'compra')->count()
        );
    }

    public function test_paquete_inexistente_o_inactivo_falla(): void
    {
        $proveedor = $this->crearProveedor();

        Sanctum::actingAs($proveedor->user);

        $this->postJson('/api/creditos/comprar', [
            'paquete_id'      => 999999,
            'idempotency_key' => 'clave-' . uniqid(),
        ])->assertNotFound();
    }

    public function test_cliente_no_puede_comprar_creditos(): void
    {
        Sanctum::actingAs($this->crearUsuario('cliente'));

        $this->postJson('/api/creditos/comprar', [
            'paquete_id'      => 1,
            'idempotency_key' => 'clave-' . uniqid(),
        ])->assertForbidden();
    }

    public function test_usuario_no_autenticado_recibe_401(): void
    {
        $this->postJson('/api/creditos/comprar', [
            'paquete_id'      => 1,
            'idempotency_key' => 'clave-' . uniqid(),
        ])->assertUnauthorized();
    }

    /**
     * Carrera por la misma clave.
     *
     * PHPUnit es monohilo, asi que no se pueden lanzar dos peticiones a la vez.
     * Lo que si se puede reproducir es la ventana exacta que las hace chocar:
     * la compra rival se inserta DESPUES del chequeo previo de idempotencia y
     * ANTES del INSERT, que es justo donde cabe la peticion concurrente. El
     * gancho se cuelga de PaqueteCredito::retrieved porque el paquete se carga
     * entre esos dos momentos y fuera de la transaccion, de modo que la fila
     * rival sobrevive al rollback del perdedor igual que sobreviviria si
     * viniera de otra conexion ya confirmada.
     *
     * Antes de la correccion esto devolvia 500 por el 23505 sin manejar.
     */
    public function test_una_carrera_por_la_misma_clave_devuelve_la_ganadora_sin_duplicar_credito(): void
    {
        $proveedor = $this->crearProveedor(saldo: 5);
        $paquete = PaqueteCredito::where('nombre', 'Inicial')->first();
        $clave = 'clave-carrera-' . uniqid();
        $referenciaGanadora = $this->referenciaDePrueba();

        $yaInsertada = false;

        PaqueteCredito::retrieved(function () use (
            &$yaInsertada, $proveedor, $paquete, $clave, $referenciaGanadora
        ) {
            if ($yaInsertada) {
                return;
            }
            $yaInsertada = true;

            // Insert directo: simula la peticion rival que ya confirmo su fila.
            DB::table('compras_creditos')->insert([
                'proveedor_id'       => $proveedor->id,
                'paquete_id'         => $paquete->id,
                'monto_gtq'          => $paquete->precio_gtq,
                'creditos_otorgados' => 8,
                'estado'             => 'completada',
                'referencia'         => $referenciaGanadora,
                'idempotency_key'    => $clave,
                'completada_at'      => now(),
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        });

        Sanctum::actingAs($proveedor->user);

        $response = $this->postJson('/api/creditos/comprar', [
            'paquete_id'      => $paquete->id,
            'idempotency_key' => $clave,
        ]);

        // La perdedora responde con la compra ganadora, no con un error.
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('compra.referencia', $referenciaGanadora);

        // Su transaccion se revirtio entera: ni fila duplicada, ni credito,
        // ni movimiento en el historial.
        $this->assertEquals(1, CompraCredito::where('proveedor_id', $proveedor->id)
            ->where('idempotency_key', $clave)->count());

        $this->assertEquals(5, CreditoProveedor::where('proveedor_id', $proveedor->id)->first()->saldo);

        $this->assertEquals(0, TransaccionCredito::where('proveedor_id', $proveedor->id)
            ->where('tipo', 'compra')->count());
    }

    /**
     * La clave es unica POR proveedor, no globalmente. Si el frontend genera
     * claves predecibles ("compra-1"), dos proveedores distintos las repiten
     * sin conocerse. Con el UNIQUE global original, el segundo recibia 500.
     */
    public function test_dos_proveedores_pueden_usar_la_misma_idempotency_key(): void
    {
        $primero = $this->crearProveedor(saldo: 0);
        $segundo = $this->crearProveedor(saldo: 0);
        $paquete = PaqueteCredito::where('nombre', 'Inicial')->first();
        $claveCompartida = 'compra-1';

        Sanctum::actingAs($primero->user);
        $this->postJson('/api/creditos/comprar', [
            'paquete_id'      => $paquete->id,
            'idempotency_key' => $claveCompartida,
        ])->assertCreated();

        Sanctum::actingAs($segundo->user);
        $this->postJson('/api/creditos/comprar', [
            'paquete_id'      => $paquete->id,
            'idempotency_key' => $claveCompartida,
        ])->assertCreated();

        // Cada uno recibio su credito y quedo con su propia fila de compra.
        $this->assertEquals(8, CreditoProveedor::where('proveedor_id', $primero->id)->first()->saldo);
        $this->assertEquals(8, CreditoProveedor::where('proveedor_id', $segundo->id)->first()->saldo);

        foreach ([$primero, $segundo] as $proveedor) {
            $this->assertEquals(1, CompraCredito::where('proveedor_id', $proveedor->id)
                ->where('idempotency_key', $claveCompartida)->count());
        }
    }

    /**
     * El endpoint de compra siempre acredita en el acto, asi que hoy solo
     * produce 'completada'. Los otros tres estados existen en el esquema para
     * cuando entre un pago real. Esta prueba fija dos cosas: que el CHECK los
     * admite y que el saldo no depende de la tabla de compras sino de
     * transacciones_credito, de modo que una compra no completada nunca puede
     * regalar creditos.
     */
    public function test_los_estados_no_completados_se_persisten_y_no_alteran_el_saldo(): void
    {
        $proveedor = $this->crearProveedor(saldo: 7);
        $paquete = PaqueteCredito::where('nombre', 'Inicial')->first();

        foreach (['pendiente', 'fallida', 'cancelada'] as $estado) {
            CompraCredito::create([
                'proveedor_id'       => $proveedor->id,
                'paquete_id'         => $paquete->id,
                'monto_gtq'          => $paquete->precio_gtq,
                'creditos_otorgados' => 8,
                'estado'             => $estado,
                'referencia'         => $this->referenciaDePrueba(),
                'idempotency_key'    => "estado-{$estado}-" . uniqid(),
                'completada_at'      => null,
            ]);

            $this->assertDatabaseHas('compras_creditos', [
                'proveedor_id' => $proveedor->id,
                'estado'       => $estado,
            ]);
        }

        $this->assertEquals(3, CompraCredito::where('proveedor_id', $proveedor->id)
            ->whereIn('estado', ['pendiente', 'fallida', 'cancelada'])->count());

        // 24 creditos en compras no completadas y el saldo sigue en 7.
        $this->assertEquals(7, CreditoProveedor::where('proveedor_id', $proveedor->id)->first()->saldo);

        $this->assertEquals(0, TransaccionCredito::where('proveedor_id', $proveedor->id)
            ->where('tipo', 'compra')->count());
    }

    /**
     * Referencia unica para las filas que las pruebas insertan a mano, con el
     * mismo ancho que genera el controlador.
     */
    private function referenciaDePrueba(): string
    {
        return 'SGT-' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
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
        $categoria = Categoria::create([
            'nombre'      => 'Categoria comprar ' . uniqid(),
            'descripcion' => 'Categoria para pruebas de compra de creditos',
        ]);

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion'  => 'Proveedor para pruebas de compra',
        ]);

        CreditoProveedor::create([
            'proveedor_id' => $proveedor->id,
            'saldo'        => $saldo,
            'updated_at'   => now(),
        ]);

        return $proveedor->setRelation('user', $user);
    }
}
