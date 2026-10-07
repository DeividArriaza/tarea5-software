<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\PublicacionServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 5.4 — CRUD, autorizacion y contrato transaccional de publicaciones.
 *
 * Complementa a `PublicacionServicioApiTest` (CRUD del propietario, catalogo
 * publico, 401/403 basicos y payload invalido) y a
 * `PublicacionServicioLimiteTest` (limite 1/3, activacion y vencimiento
 * Premium). Aqui van los huecos que dejaban esos dos archivos:
 *
 *   - IDs inexistentes y manipulados (no habia un solo 404 en la cobertura);
 *   - 401 en todas las rutas protegidas, no solo en `store`;
 *   - estados y categorias invalidos;
 *   - que un rechazo no deje fila ni archivo a medias;
 *   - el contrato transaccional del limite.
 *
 * SOBRE CONCURRENCIA — leer antes de agregar pruebas aqui.
 * PHPUnit corre en un solo proceso y `DatabaseTransactions` mantiene una unica
 * conexion, asi que en este archivo NO hay ninguna prueba de contencion real:
 * dos peticiones simultaneas disputandose el lock no se pueden reproducir desde
 * un solo hilo, y un hook de modelo que simula a la rival prueba recuperacion
 * ante conflicto, no concurrencia. Lo que si se verifica de forma determinista
 * es el *contrato*: que el conteo del limite ocurra despues de tomar
 * `lockForUpdate` sobre el proveedor, que es la propiedad de la que depende la
 * correccion bajo carga. Una prueba de contencion real necesita dos conexiones
 * PostgreSQL independientes y queda como evidencia aparte, fuera de esta suite.
 */
class PublicacionServicioContratoTest extends TestCase
{
    use DatabaseTransactions;

    // ── IDs inexistentes y manipulados ──────────────────────────────────

    public function test_detalle_publico_de_id_inexistente_responde_404(): void
    {
        $this->getJson('/api/publicaciones/999999')->assertNotFound();
    }

    public function test_detalle_publico_no_revela_una_publicacion_inactiva(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor();

        $inactiva = $this->crearPublicacion($proveedor, $categoria, 'inactiva');

        // Conocer el id no debe alcanzar: una publicacion pausada es invisible
        // para el catalogo y tambien para el acceso directo.
        $this->getJson("/api/publicaciones/{$inactiva->id}")->assertNotFound();
    }

    public function test_administrar_una_publicacion_inexistente_responde_404(): void
    {
        [$proveedor] = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->putJson('/api/publicaciones/999999', [
            'titulo'      => 'Titulo que nunca se guardara',
            'descripcion' => 'Descripcion suficientemente larga para pasar validacion.',
        ])->assertNotFound();

        $this->postJson('/api/publicaciones/999999/activar')->assertNotFound();
        $this->postJson('/api/publicaciones/999999/desactivar')->assertNotFound();
        $this->deleteJson('/api/publicaciones/999999')->assertNotFound();
    }

    public function test_desactivar_y_eliminar_publicacion_ajena_responde_403(): void
    {
        [$dueno, $categoria] = $this->crearProveedor();
        [$ajeno]             = $this->crearProveedor();

        $publicacion = $this->crearPublicacion($dueno, $categoria);

        Sanctum::actingAs($ajeno->user);

        $this->postJson("/api/publicaciones/{$publicacion->id}/activar")->assertForbidden();
        $this->postJson("/api/publicaciones/{$publicacion->id}/desactivar")->assertForbidden();
        $this->deleteJson("/api/publicaciones/{$publicacion->id}")->assertForbidden();

        $this->assertDatabaseHas('publicaciones_servicio', [
            'id'     => $publicacion->id,
            'estado' => 'activa',
        ]);
    }

    public function test_update_ignora_un_proveedor_id_enviado_por_el_cliente(): void
    {
        [$dueno, $categoria] = $this->crearProveedor();
        [$ajeno]             = $this->crearProveedor();

        $publicacion = $this->crearPublicacion($dueno, $categoria);

        Sanctum::actingAs($dueno->user);

        $this->putJson("/api/publicaciones/{$publicacion->id}", [
            'proveedor_id' => $ajeno->id,
            'titulo'       => 'Titulo editado por su dueno',
            'descripcion'  => 'Descripcion suficientemente larga para pasar validacion.',
        ])->assertOk();

        // La publicacion no puede cambiar de dueno por un campo del payload.
        $this->assertDatabaseHas('publicaciones_servicio', [
            'id'           => $publicacion->id,
            'proveedor_id' => $dueno->id,
        ]);
    }

    // ── Autenticacion ───────────────────────────────────────────────────

    public function test_todas_las_rutas_de_administracion_exigen_autenticacion(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor();
        $publicacion = $this->crearPublicacion($proveedor, $categoria);

        $this->getJson('/api/publicaciones/mias')->assertUnauthorized();
        $this->putJson("/api/publicaciones/{$publicacion->id}", [
            'titulo'      => 'Intento anonimo',
            'descripcion' => 'Descripcion suficientemente larga para pasar validacion.',
        ])->assertUnauthorized();
        $this->postJson("/api/publicaciones/{$publicacion->id}/activar")->assertUnauthorized();
        $this->postJson("/api/publicaciones/{$publicacion->id}/desactivar")->assertUnauthorized();
        $this->deleteJson("/api/publicaciones/{$publicacion->id}")->assertUnauthorized();
    }

    public function test_proveedor_sin_perfil_no_puede_listar_ni_publicar(): void
    {
        // Rol correcto pero sin perfil creado todavia: no es 401 ni 500.
        Sanctum::actingAs(User::factory()->proveedor()->create());

        $this->getJson('/api/publicaciones/mias')->assertForbidden();
        $this->postJson('/api/publicaciones', [
            'titulo'      => 'Publicacion sin perfil de proveedor',
            'descripcion' => 'Descripcion suficientemente larga para pasar validacion.',
        ])->assertForbidden();
    }

    // ── Validacion ──────────────────────────────────────────────────────

    public function test_estado_y_categoria_invalidos_fallan_validacion(): void
    {
        [$proveedor] = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->postJson('/api/publicaciones', [
            'titulo'       => 'Publicacion con estado invalido',
            'descripcion'  => 'Descripcion suficientemente larga para pasar validacion.',
            'estado'       => 'archivada',
            'categoria_id' => 999999,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado', 'categoria_id']);

        $this->assertDatabaseCount('publicaciones_servicio', 0);
    }

    // ── Nada a medias ───────────────────────────────────────────────────

    public function test_rechazo_por_limite_no_deja_fila_ni_imagen_huerfana(): void
    {
        Storage::fake('public');

        [$proveedor, $categoria] = $this->crearProveedor();
        $this->crearPublicacion($proveedor, $categoria); // ocupa el unico cupo gratis

        Sanctum::actingAs($proveedor->user);

        // Se usa `create()` con MIME explicito y no `image()`: la imagen PHP del
        // proyecto no trae la extension GD, asi que `image()` revienta con
        // "GD extension is not installed" antes de llegar al caso de prueba.
        $this->post('/api/publicaciones', [
            'categoria_id' => $categoria->id,
            'titulo'       => 'Segunda publicacion que excede el cupo gratis',
            'descripcion'  => 'Descripcion suficientemente larga para pasar validacion.',
            'imagen'       => UploadedFile::fake()->create('portada.jpg', 120, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            // Se afirma el motivo, no solo el codigo: si la peticion fuera
            // rechazada por la imagen en vez de por el cupo, el 422 seria el
            // mismo y la prueba pasaria sin probar nada.
            ->assertJsonPath('message', 'Alcanzaste el limite de publicaciones activas (1).');

        // Ni la fila ni el archivo: el limite se verifica antes de tocar disco.
        $this->assertDatabaseCount('publicaciones_servicio', 1);
        $this->assertEmpty(Storage::disk('public')->allFiles('publicaciones/'.$proveedor->id));
    }

    public function test_un_fallo_despues_del_insert_revierte_la_publicacion(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        // Revienta despues del INSERT y dentro de la transaccion del
        // controlador: si el rollback no funcionara, la fila quedaria escrita.
        PublicacionServicio::created(function () {
            throw new \RuntimeException('fallo simulado despues del insert');
        });

        $this->postJson('/api/publicaciones', [
            'categoria_id' => $categoria->id,
            'titulo'       => 'Publicacion que no debe sobrevivir',
            'descripcion'  => 'Descripcion suficientemente larga para pasar validacion.',
        ])->assertStatus(500);

        $this->assertDatabaseCount('publicaciones_servicio', 0);
    }

    // ── Contrato transaccional del limite ───────────────────────────────

    public function test_el_conteo_del_limite_ocurre_despues_de_bloquear_al_proveedor(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $consultas = [];
        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = strtolower($query->sql);
        });

        $this->postJson('/api/publicaciones', [
            'categoria_id' => $categoria->id,
            'titulo'       => 'Publicacion para inspeccionar el orden de consultas',
            'descripcion'  => 'Descripcion suficientemente larga para pasar validacion.',
        ])->assertCreated();

        $posicionLock = $this->primeraPosicion(
            $consultas,
            fn (string $sql) => str_contains($sql, 'proveedores') && str_contains($sql, 'for update')
        );

        $posicionConteo = $this->primeraPosicion(
            $consultas,
            fn (string $sql) => str_contains($sql, 'count(*)') && str_contains($sql, 'publicaciones_servicio')
        );

        $this->assertNotNull($posicionLock, 'La creacion debe tomar lockForUpdate sobre el proveedor.');
        $this->assertNotNull($posicionConteo, 'La creacion debe contar las publicaciones activas.');
        $this->assertLessThan(
            $posicionConteo,
            $posicionLock,
            'El conteo del limite debe ocurrir despues del lock, no antes: contar sin lock permite '
            .'que dos peticiones lean el mismo total y ambas se crean.'
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function primeraPosicion(array $consultas, callable $coincide): ?int
    {
        foreach ($consultas as $i => $sql) {
            if ($coincide($sql)) {
                return $i;
            }
        }

        return null;
    }

    private function crearPublicacion(Proveedor $proveedor, Categoria $categoria, string $estado = 'activa'): PublicacionServicio
    {
        return PublicacionServicio::create([
            'proveedor_id' => $proveedor->id,
            'categoria_id' => $categoria->id,
            'titulo'       => 'Publicacion de apoyo '.uniqid(),
            'descripcion'  => 'Descripcion suficientemente larga para pasar la validacion del backend.',
            'estado'       => $estado,
        ]);
    }

    private function crearProveedor(): array
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
            'descripcion'  => 'Proveedor para pruebas de contrato de publicaciones.',
        ])->setRelation('user', $user);

        return [$proveedor, $categoria];
    }
}
