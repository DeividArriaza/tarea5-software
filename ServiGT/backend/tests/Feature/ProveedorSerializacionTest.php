<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 2.5 — contrato de salida del proveedor (hallazgos 21 y 22).
 *
 * Antes de la correccion `GET /api/providers` devolvia el modelo crudo, asi que
 * un visitante anonimo obtenia correo, `user_id` y los campos internos de
 * Premium de todos los proveedores; y `GET /api/providers/user/{id}` dejaba a
 * cualquier autenticado leer el perfil ajeno. Estas pruebas fallan contra ese
 * codigo.
 */
class ProveedorSerializacionTest extends TestCase
{
    use DatabaseTransactions;

    private const PRIVADOS = [
        'email',
        'user_id',
        'premium_vence_at',
        'premium_inicio_at',
        'premium_ciclo_key',
        'premium_renovaciones',
        'premium_dias_restantes',
    ];

    public function test_catalogo_publico_no_expone_datos_privados_del_proveedor(): void
    {
        $proveedor = $this->crearProveedor();

        $response = $this->getJson('/api/providers')->assertOk();

        $fila = collect($response->json('proveedores'))
            ->firstWhere('id', $proveedor->id);

        $this->assertNotNull($fila, 'El proveedor creado debe aparecer en el catalogo.');

        foreach (self::PRIVADOS as $campo) {
            $this->assertArrayNotHasKey($campo, $fila, "El catalogo publico no debe exponer {$campo}.");
        }

        // Lo que el listado y su buscador si necesitan sigue estando.
        foreach (['id', 'nombre', 'descripcion', 'departamento', 'municipio', 'telefono', 'categoria'] as $campo) {
            $this->assertArrayHasKey($campo, $fila, "El catalogo dejaria de funcionar sin {$campo}.");
        }

        $this->assertSame('nunca', $fila['premium_estado']);
    }

    public function test_detalle_publico_no_expone_datos_privados_ni_documentos(): void
    {
        $proveedor = $this->crearProveedor();

        $response = $this->getJson("/api/providers/{$proveedor->id}")->assertOk();
        $data = $response->json('proveedor');

        foreach (self::PRIVADOS as $campo) {
            $this->assertArrayNotHasKey($campo, $data, "El detalle publico no debe exponer {$campo}.");
        }

        $this->assertArrayNotHasKey('documentos', $data);

        // El detalle si agrega lo que la pantalla publica dibuja.
        foreach (['nivel', 'total_calificaciones', 'portada', 'color_acento', 'categorias'] as $campo) {
            $this->assertArrayHasKey($campo, $data, "El detalle publico necesita {$campo}.");
        }
    }

    public function test_perfil_propio_si_incluye_email_user_id_y_vigencia_premium(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $data = $this->getJson('/api/providers/me')->assertOk()->json('proveedor');

        $this->assertSame($proveedor->id, $data['id']);
        $this->assertSame($proveedor->user_id, $data['user_id']);
        $this->assertSame($proveedor->email, $data['email']);
        $this->assertArrayHasKey('premium_vence_at', $data);
        $this->assertArrayHasKey('premium_renovaciones', $data);
    }

    public function test_providers_me_sin_perfil_responde_404(): void
    {
        Sanctum::actingAs(User::factory()->cliente()->create());

        $this->getJson('/api/providers/me')->assertNotFound();
    }

    public function test_providers_me_requiere_autenticacion(): void
    {
        $this->getJson('/api/providers/me')->assertUnauthorized();
    }

    public function test_lookup_legado_por_user_id_ajeno_responde_403(): void
    {
        $proveedor = $this->crearProveedor();
        $intruso   = User::factory()->proveedor()->create();

        Sanctum::actingAs($intruso);

        $this->getJson("/api/providers/user/{$proveedor->user_id}")->assertForbidden();
    }

    public function test_lookup_legado_del_propio_usuario_sigue_funcionando(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->getJson("/api/providers/user/{$proveedor->user_id}")
            ->assertOk()
            ->assertJsonPath('proveedor.user_id', $proveedor->user_id);
    }

    public function test_admin_puede_consultar_el_perfil_de_otro_proveedor(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/providers/user/{$proveedor->user_id}")
            ->assertOk()
            ->assertJsonPath('proveedor.email', $proveedor->email);
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
            'telefono'     => '5555-0199',
            'descripcion'  => 'Proveedor para pruebas de serializacion',
            'departamento' => 'Guatemala',
            'municipio'    => 'Mixco',
            'categoria_id' => $categoria->id,
        ]);

        return $proveedor->setRelation('user', $user);
    }
}
