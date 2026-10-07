<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cotizacion;
use App\Models\Notificacion;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Task 2.3 — matriz automatizada de autorizacion por rol y recurso.
 *
 * Cubre los cinco casos que exige el criterio —no autenticado, rol incorrecto,
 * ownership ajeno, ID inexistente y estado invalido— sobre servicios, pedidos,
 * cotizaciones, mensajes, creditos, Premium, publicaciones y admin.
 *
 * Esta dirigida por tablas y no por un test por caso a proposito: la gracia de
 * una matriz es que agregar un endpoint sea agregar una fila, y que el fallo
 * diga cual fila se rompio. Los guards concretos de autocontratacion y
 * mensajeria viven en `AutocontratacionYMensajeriaTest`; aqui solo se verifica
 * la reja perimetral.
 */
class MatrizAutorizacionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // El limitador comparte el store `array` durante toda la corrida; sin
        // esto una prueba previa puede dejar contadores que devuelvan 429
        // donde esta matriz espera 401 o 403.
        Cache::flush();
    }

    // ── Caso 1 · No autenticado ─────────────────────────────────────────

    /**
     * Toda ruta detras de `auth:sanctum` responde 401 sin token, incluidas las
     * de admin: el rechazo por falta de sesion ocurre antes del rol.
     *
     * PHPUnit 12 ya no lee `@dataProvider` desde el docblock: debe ser atributo.
     */
    #[DataProvider('rutasProtegidas')]
    public function test_ruta_protegida_sin_token_responde_401(string $metodo, string $uri): void
    {
        $this->json($metodo, $uri)->assertUnauthorized();
    }

    public static function rutasProtegidas(): array
    {
        $rutas = [
            ['GET', '/api/me'],
            ['POST', '/api/logout'],

            ['GET', '/api/providers/me'],
            ['GET', '/api/providers/user/1'],
            ['POST', '/api/providers'],
            ['PUT', '/api/providers/1'],
            ['POST', '/api/providers/1/foto'],
            ['POST', '/api/providers/1/portada'],
            ['DELETE', '/api/providers/1/portada'],
            ['GET', '/api/providers/1/documentos'],
            ['POST', '/api/providers/1/documentos'],
            ['GET', '/api/providers/1/documentos/1/descargar'],

            ['POST', '/api/servicios'],
            ['GET', '/api/servicios/proveedor'],
            ['GET', '/api/servicios/cliente'],
            ['GET', '/api/servicios/1'],
            ['POST', '/api/servicios/1/aceptar'],
            ['POST', '/api/servicios/1/rechazar'],
            ['POST', '/api/servicios/1/iniciar'],
            ['POST', '/api/servicios/1/finalizar'],
            ['POST', '/api/servicios/1/confirmar-fin'],
            ['PUT', '/api/servicios/1/estado'],

            ['GET', '/api/disponibilidad/mia'],
            ['POST', '/api/disponibilidad'],

            ['POST', '/api/calificaciones'],
            ['POST', '/api/servicios/1/calificar'],

            ['POST', '/api/mensajes'],
            ['GET', '/api/mensajes/conversacion/1'],
            ['GET', '/api/mensajes/conversaciones'],

            ['POST', '/api/pedidos'],
            ['GET', '/api/pedidos/mios'],
            ['POST', '/api/pedidos/1/cotizaciones'],
            ['PUT', '/api/pedidos/1/cotizaciones/1'],
            ['POST', '/api/pedidos/1/cotizaciones/1/aceptar'],

            ['GET', '/api/mi-credito'],
            ['GET', '/api/creditos/paquetes'],
            ['POST', '/api/creditos/comprar'],
            ['GET', '/api/creditos/transacciones'],

            ['POST', '/api/premium/activar'],
            ['GET', '/api/premium/mi-estado'],

            ['GET', '/api/publicaciones/mias'],
            ['POST', '/api/publicaciones'],
            ['PUT', '/api/publicaciones/1'],
            ['POST', '/api/publicaciones/1/activar'],
            ['POST', '/api/publicaciones/1/desactivar'],
            ['DELETE', '/api/publicaciones/1'],

            ['GET', '/api/notificaciones'],
            ['PUT', '/api/notificaciones/1/leer'],
            ['PUT', '/api/notificaciones/leer-todas'],

            ['GET', '/api/admin/stats'],
            ['GET', '/api/admin/usuarios'],
            ['GET', '/api/admin/proveedores'],
            ['GET', '/api/admin/creditos-premium'],
            ['POST', '/api/admin/proveedores/1/creditos'],
        ];

        $casos = [];
        foreach ($rutas as [$metodo, $uri]) {
            $casos["{$metodo} {$uri}"] = [$metodo, $uri];
        }

        return $casos;
    }

    // ── Caso 2 · Rol incorrecto ─────────────────────────────────────────

    public function test_un_cliente_no_alcanza_endpoints_exclusivos_de_proveedor(): void
    {
        Sanctum::actingAs(User::factory()->cliente()->create());

        $this->assertDenegado('GET', '/api/publicaciones/mias');
        $this->assertDenegado('POST', '/api/publicaciones');
        $this->assertDenegado('GET', '/api/mi-credito');
        $this->assertDenegado('POST', '/api/creditos/comprar');
        $this->assertDenegado('GET', '/api/creditos/transacciones');
        $this->assertDenegado('POST', '/api/premium/activar');
        $this->assertDenegado('GET', '/api/premium/mi-estado');
        $this->assertDenegado('GET', '/api/servicios/proveedor');
        $this->assertDenegado('GET', '/api/disponibilidad/mia');
        $this->assertDenegado('POST', '/api/disponibilidad');
    }

    public function test_un_proveedor_no_alcanza_endpoints_exclusivos_de_cliente(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        // Contratar es del cliente (guard de la task 2.3).
        $this->assertDenegado('POST', '/api/servicios', [
            'proveedor_id' => $proveedor->id,
            'descripcion'  => 'Un proveedor no contrata servicios',
        ]);
    }

    public function test_ningun_rol_distinto_de_admin_alcanza_el_area_administrativa(): void
    {
        $rutasAdmin = [
            ['GET', '/api/admin/stats'],
            ['GET', '/api/admin/usuarios'],
            ['GET', '/api/admin/proveedores'],
            ['GET', '/api/admin/creditos-premium'],
            ['POST', '/api/admin/proveedores/1/creditos'],
        ];

        foreach (['cliente', 'proveedor'] as $rol) {
            Sanctum::actingAs(User::factory()->state(['role' => $rol])->create());

            foreach ($rutasAdmin as [$metodo, $uri]) {
                $this->json($metodo, $uri)->assertForbidden();
            }
        }
    }

    // ── Caso 3 · Ownership ajeno ────────────────────────────────────────

    public function test_no_se_puede_operar_un_servicio_de_terceros(): void
    {
        $servicio = $this->crearServicio('aceptado');
        $tercero  = User::factory()->cliente()->create();

        Sanctum::actingAs($tercero);

        foreach ([
            ['GET',  "/api/servicios/{$servicio->id}"],
            ['POST', "/api/servicios/{$servicio->id}/aceptar"],
            ['POST', "/api/servicios/{$servicio->id}/rechazar"],
            ['POST', "/api/servicios/{$servicio->id}/iniciar"],
            ['POST', "/api/servicios/{$servicio->id}/finalizar"],
            ['POST', "/api/servicios/{$servicio->id}/confirmar-fin"],
            ['PUT',  "/api/servicios/{$servicio->id}/estado"],
            ['POST', "/api/servicios/{$servicio->id}/calificar"],
        ] as [$metodo, $uri]) {
            $this->assertDenegado($metodo, $uri, ['codigo' => '000000', 'estado' => 'completado', 'puntuacion' => 5]);
        }
    }

    public function test_no_se_puede_adjudicar_ni_editar_cotizaciones_de_un_pedido_ajeno(): void
    {
        ['pedido' => $pedido, 'cotizacion' => $cotizacion] = $this->crearPedidoConCotizacion();
        $tercero = User::factory()->cliente()->create();

        Sanctum::actingAs($tercero);

        $this->assertDenegado('POST', "/api/pedidos/{$pedido->id}/cotizaciones/{$cotizacion->id}/aceptar");
        $this->assertDenegado('PUT', "/api/pedidos/{$pedido->id}/cotizaciones/{$cotizacion->id}", [
            'monto'   => 500,
            'mensaje' => 'Intento de editar una cotizacion ajena',
        ]);
    }

    public function test_una_notificacion_ajena_no_se_puede_marcar_como_leida(): void
    {
        $dueno   = User::factory()->cliente()->create();
        $tercero = User::factory()->cliente()->create();

        $notificacion = Notificacion::create([
            'destinatario_id' => $dueno->id,
            'tipo'            => 'nueva_solicitud',
            'titulo'          => 'Notificacion del dueno',
            'mensaje'         => 'Solo su destinatario deberia poder leerla.',
        ]);

        Sanctum::actingAs($tercero);
        $this->assertDenegado('PUT', "/api/notificaciones/{$notificacion->id}/leer");

        $this->assertDatabaseHas('notificaciones', [
            'id'    => $notificacion->id,
            'leida' => false,
        ]);
    }

    // ── Caso 4 · ID inexistente ─────────────────────────────────────────

    public function test_recursos_inexistentes_responden_404(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        foreach ([
            ['GET', '/api/providers/999999'],
            ['PUT', '/api/providers/999999'],
            ['GET', '/api/servicios/999999'],
            ['GET', '/api/pedidos/999999'],
            ['PUT', '/api/notificaciones/999999/leer'],
            ['PUT', '/api/pedidos/999999/cotizaciones/999999'],
        ] as [$metodo, $uri]) {
            $this->json($metodo, $uri, [
                'nombre'       => 'Nombre valido',
                'departamento' => 'Guatemala',
                'monto'        => 100,
                'mensaje'      => 'Mensaje suficientemente largo para la cotizacion.',
            ])->assertNotFound();
        }
    }

    public function test_un_admin_recargando_creditos_a_un_proveedor_inexistente_recibe_404(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/proveedores/999999/creditos', [
            'monto'  => 5,
            'motivo' => 'Recarga a un proveedor que no existe',
        ])->assertNotFound();
    }

    // ── Caso 5 · Estado invalido ────────────────────────────────────────

    public function test_las_transiciones_de_servicio_validan_el_estado_previo(): void
    {
        // Pendiente: no se puede iniciar, finalizar, confirmar ni calificar.
        $servicio = $this->crearServicio('pendiente');
        Sanctum::actingAs($servicio->proveedor->user);

        $this->postJson("/api/servicios/{$servicio->id}/iniciar", ['codigo' => $servicio->codigo_inicio])
            ->assertStatus(422);
        $this->postJson("/api/servicios/{$servicio->id}/finalizar")->assertStatus(422);

        Sanctum::actingAs($servicio->cliente);
        $this->postJson("/api/servicios/{$servicio->id}/confirmar-fin", ['codigo' => '000000'])
            ->assertStatus(422);
        $this->postJson("/api/servicios/{$servicio->id}/calificar", ['puntuacion' => 5])
            ->assertStatus(422);
    }

    public function test_iniciar_con_codigo_incorrecto_no_avanza_el_servicio(): void
    {
        $servicio = $this->crearServicio('aceptado');
        Sanctum::actingAs($servicio->proveedor->user);

        $this->postJson("/api/servicios/{$servicio->id}/iniciar", ['codigo' => '999999'])
            ->assertStatus(422);

        $this->assertDatabaseHas('servicios', [
            'id'     => $servicio->id,
            'estado' => 'aceptado',
        ]);
    }

    public function test_no_se_adjudica_una_cotizacion_de_un_pedido_ya_cerrado(): void
    {
        ['pedido' => $pedido, 'cotizacion' => $cotizacion, 'cliente' => $cliente] = $this->crearPedidoConCotizacion();

        $pedido->update(['estado' => 'adjudicado']);

        Sanctum::actingAs($cliente);

        $this->postJson("/api/pedidos/{$pedido->id}/cotizaciones/{$cotizacion->id}/aceptar")
            ->assertStatus(422);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Un rechazo valido es 401, 403 o 404 segun el recurso: algunos endpoints
     * ocultan la existencia del recurso ajeno en vez de admitirla con un 403.
     * Lo que esta matriz garantiza es que **nunca** responden 2xx.
     */
    private function assertDenegado(string $metodo, string $uri, array $payload = []): void
    {
        $status = $this->json($metodo, $uri, $payload)->getStatusCode();

        $this->assertContains(
            $status,
            [401, 403, 404],
            "{$metodo} {$uri} debia denegar el acceso y respondio {$status}."
        );
    }

    private function crearServicio(string $estado): Servicio
    {
        $proveedor = $this->crearProveedor();
        $cliente   = User::factory()->cliente()->create();

        $servicio = Servicio::create([
            'cliente_id'    => $cliente->id,
            'proveedor_id'  => $proveedor->id,
            'descripcion'   => 'Servicio de apoyo para la matriz de autorizacion',
            'estado'        => $estado,
            'codigo_inicio' => '123456',
        ]);

        return $servicio
            ->setRelation('proveedor', $proveedor)
            ->setRelation('cliente', $cliente);
    }

    private function crearPedidoConCotizacion(): array
    {
        $proveedor = $this->crearProveedor();
        $cliente   = User::factory()->cliente()->create();

        $pedido = Pedido::create([
            'cliente_id'       => $cliente->id,
            'categoria_id'     => $proveedor->categoria_id,
            'descripcion'      => 'Pedido de apoyo para la matriz de autorizacion',
            'direccion'        => 'Zona 1',
            'urgencia'         => 'media',
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ]);

        $cotizacion = Cotizacion::create([
            'pedido_id'    => $pedido->id,
            'proveedor_id' => $proveedor->id,
            'monto'        => 400,
            'mensaje'      => 'Cotizacion de apoyo para la matriz de autorizacion.',
            'estado'       => 'enviada',
        ]);

        return compact('pedido', 'cotizacion', 'cliente', 'proveedor');
    }

    private function crearProveedor(): Proveedor
    {
        $categoria = Categoria::factory()->create();
        $user      = User::factory()->proveedor()->create();

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion'  => 'Proveedor de apoyo para la matriz de autorizacion.',
        ]);

        return $proveedor->setRelation('user', $user);
    }
}
