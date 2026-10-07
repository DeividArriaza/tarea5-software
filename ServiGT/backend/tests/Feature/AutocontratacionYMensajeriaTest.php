<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Cotizacion;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 2.3 — autocontratacion, autocalificacion y mensajeria (hallazgos 24 y 25).
 *
 * Antes de la correccion cualquier autenticado podia usar POST /api/servicios,
 * incluido un proveedor apuntando a su propio perfil: podia recorrer el flujo
 * completo solo y terminar calificandose, subiendo la nota por la que ordena el
 * directorio publico. Y POST /api/mensajes aceptaba cualquier receptor_id sin
 * exigir relacion alguna.
 */
class AutocontratacionYMensajeriaTest extends TestCase
{
    use DatabaseTransactions;

    // ── Autocontratacion ────────────────────────────────────────────────

    public function test_proveedor_no_puede_solicitarse_un_servicio_a_si_mismo(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->postJson('/api/servicios', [
            'proveedor_id' => $proveedor->id,
            'descripcion'  => 'Intento de autocontratacion',
        ])->assertForbidden();

        $this->assertDatabaseMissing('servicios', ['proveedor_id' => $proveedor->id]);
    }

    public function test_un_proveedor_no_puede_contratar_a_otro_proveedor(): void
    {
        $unProveedor  = $this->crearProveedor();
        $otroProveedor = $this->crearProveedor();

        Sanctum::actingAs($unProveedor->user);

        $this->postJson('/api/servicios', [
            'proveedor_id' => $otroProveedor->id,
            'descripcion'  => 'Un proveedor intentando actuar como cliente',
        ])->assertForbidden();
    }

    public function test_cliente_si_puede_solicitar_un_servicio(): void
    {
        $proveedor = $this->crearProveedor();
        $cliente   = User::factory()->cliente()->create();

        Sanctum::actingAs($cliente);

        $this->postJson('/api/servicios', [
            'proveedor_id' => $proveedor->id,
            'descripcion'  => 'Reparacion de tuberia en la cocina',
        ])->assertCreated();

        $this->assertDatabaseHas('servicios', [
            'proveedor_id' => $proveedor->id,
            'cliente_id'   => $cliente->id,
        ]);
    }

    public function test_nadie_puede_calificar_su_propio_perfil_de_proveedor(): void
    {
        $proveedor = $this->crearProveedor();

        // Servicio heredado de antes del guard: el mismo usuario en ambos lados.
        $servicio = Servicio::create([
            'cliente_id'   => $proveedor->user_id,
            'proveedor_id' => $proveedor->id,
            'descripcion'  => 'Servicio legado con el mismo usuario en ambos lados',
            'estado'       => 'completado',
        ]);

        Sanctum::actingAs($proveedor->user);

        $this->postJson("/api/servicios/{$servicio->id}/calificar", [
            'puntuacion' => 5,
            'comentario' => 'Excelente yo mismo',
        ])->assertForbidden();

        $this->assertDatabaseMissing('calificaciones', ['servicio_id' => $servicio->id]);
    }

    // ── Mensajeria ──────────────────────────────────────────────────────

    public function test_no_se_puede_escribir_a_un_usuario_sin_relacion_previa(): void
    {
        $proveedor = $this->crearProveedor();
        $cliente   = User::factory()->cliente()->create();

        Sanctum::actingAs($cliente);

        $this->postJson('/api/mensajes', [
            'receptor_id' => $proveedor->user_id,
            'contenido'   => 'Hola, vi tu perfil',
        ])->assertForbidden();

        $this->assertDatabaseCount('mensajes', 0);
    }

    public function test_una_cotizacion_no_adjudicada_no_habilita_el_chat(): void
    {
        $proveedor = $this->crearProveedor();
        $cliente   = User::factory()->cliente()->create();

        $pedido = Pedido::create([
            'cliente_id'       => $cliente->id,
            'categoria_id'     => $proveedor->categoria_id,
            'descripcion'      => 'Necesito reparar una fuga en el bano principal',
            'direccion'        => 'Zona 10',
            'urgencia'         => 'media',
            'estado'           => 'abierto',
            'fecha_expiracion' => now()->addDays(7),
        ]);

        Cotizacion::create([
            'pedido_id'    => $pedido->id,
            'proveedor_id' => $proveedor->id,
            'monto'        => 350,
            'mensaje'      => 'Puedo pasar el martes',
            'estado'       => 'enviada',
        ]);

        Sanctum::actingAs($cliente);

        $this->postJson('/api/mensajes', [
            'receptor_id' => $proveedor->user_id,
            'contenido'   => 'Quiero negociar antes de adjudicar',
        ])->assertForbidden();
    }

    public function test_con_servicio_en_comun_el_chat_funciona_en_ambos_sentidos(): void
    {
        $proveedor = $this->crearProveedor();
        $cliente   = User::factory()->cliente()->create();

        Servicio::create([
            'cliente_id'   => $cliente->id,
            'proveedor_id' => $proveedor->id,
            'descripcion'  => 'Cambio de grifo',
            'estado'       => 'aceptado',
        ]);

        Sanctum::actingAs($cliente);
        $this->postJson('/api/mensajes', [
            'receptor_id' => $proveedor->user_id,
            'contenido'   => 'Buenos dias, a que hora llega?',
        ])->assertCreated();

        Sanctum::actingAs($proveedor->user);
        $this->postJson('/api/mensajes', [
            'receptor_id' => $cliente->id,
            'contenido'   => 'Voy en camino',
        ])->assertCreated();

        $this->assertDatabaseCount('mensajes', 2);
    }

    public function test_un_servicio_terminado_no_cierra_la_conversacion(): void
    {
        $proveedor = $this->crearProveedor();
        $cliente   = User::factory()->cliente()->create();

        Servicio::create([
            'cliente_id'   => $cliente->id,
            'proveedor_id' => $proveedor->id,
            'descripcion'  => 'Trabajo ya finalizado',
            'estado'       => 'completado',
        ]);

        Sanctum::actingAs($cliente);

        $this->postJson('/api/mensajes', [
            'receptor_id' => $proveedor->user_id,
            'contenido'   => 'Una duda sobre la garantia',
        ])->assertCreated();
    }

    private function crearProveedor(): Proveedor
    {
        $user      = User::factory()->proveedor()->create();
        $categoria = Categoria::firstOrCreate(
            ['nombre' => 'Plomería'],
            ['descripcion' => 'Servicios de plomeria']
        );

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Mixco',
            'categoria_id' => $categoria->id,
        ]);

        return $proveedor->setRelation('user', $user);
    }
}
