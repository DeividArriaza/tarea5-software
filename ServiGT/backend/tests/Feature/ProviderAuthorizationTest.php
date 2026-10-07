<?php

namespace Tests\Feature;

use App\Models\DocumentoProveedor;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProviderAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_store_ignora_user_id_del_payload_y_usa_el_autenticado(): void
    {
        $user = User::factory()->proveedor()->create();
        $otro = User::factory()->proveedor()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/providers', [
            'user_id'      => $otro->id,
            'nombre'       => 'Taller Mecanico El Rayo',
            'email'        => 'rayo@example.com',
            'departamento' => 'Guatemala',
        ])
            ->assertCreated()
            ->assertJsonPath('proveedor.user_id', $user->id);

        $this->assertDatabaseHas('proveedores', [
            'email'   => 'rayo@example.com',
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseMissing('proveedores', [
            'email'   => 'rayo@example.com',
            'user_id' => $otro->id,
        ]);
    }

    public function test_carrera_al_crear_perfil_devuelve_422_y_no_500(): void
    {
        $user = User::factory()->proveedor()->create();
        Sanctum::actingAs($user);

        $yaInsertada = false;

        // Simula la peticion rival que gana la carrera justo entre el
        // exists() de esta peticion y su insert: se engancha al SELECT del
        // chequeo de duplicados (antes de que empiece la transaccion propia
        // del insert) e inserta directo por debajo del ORM.
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$yaInsertada, $user) {
            if ($yaInsertada) {
                return;
            }
            if (!str_contains($query->sql, 'select exists')) {
                return;
            }
            if (!in_array($user->id, $query->bindings, true)) {
                return;
            }
            $yaInsertada = true;

            \Illuminate\Support\Facades\DB::table('proveedores')->insert([
                'user_id'      => $user->id,
                'nombre'       => 'Rival de la carrera',
                'email'        => 'rival-' . uniqid() . '@example.com',
                'departamento' => 'Guatemala',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        });

        $this->postJson('/api/providers', [
            'nombre'       => 'Perfil perdedor de la carrera',
            'email'        => 'perdedor-' . uniqid() . '@example.com',
            'departamento' => 'Guatemala',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('proveedores', 1);
    }

    public function test_usuario_no_puede_crear_dos_perfiles_de_proveedor(): void
    {
        $user = User::factory()->proveedor()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/providers', [
            'nombre'       => 'Plomeria Rapida',
            'email'        => 'plomeria@example.com',
            'departamento' => 'Guatemala',
        ])->assertCreated();

        $this->postJson('/api/providers', [
            'nombre'       => 'Plomeria Rapida Dos',
            'email'        => 'plomeria2@example.com',
            'departamento' => 'Guatemala',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('proveedores', 1);
    }

    public function test_update_de_perfil_ajeno_responde_403_e_inexistente_404(): void
    {
        [$dueno] = $this->crearProveedorConUsuario();
        $ajeno = User::factory()->proveedor()->create();
        Sanctum::actingAs($ajeno);

        $this->putJson("/api/providers/{$dueno->id}", ['nombre' => 'Nuevo nombre'])
            ->assertForbidden();

        $this->putJson('/api/providers/999999', ['nombre' => 'Nuevo nombre'])
            ->assertNotFound();
    }

    public function test_update_de_perfil_propio_funciona(): void
    {
        [$dueno, $user] = $this->crearProveedorConUsuario();
        Sanctum::actingAs($user);

        $this->putJson("/api/providers/{$dueno->id}", ['nombre' => 'Nombre actualizado'])
            ->assertOk()
            ->assertJsonPath('proveedor.nombre', 'Nombre actualizado');
    }

    public function test_admin_puede_editar_perfil_ajeno(): void
    {
        [$dueno] = $this->crearProveedorConUsuario();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $this->putJson("/api/providers/{$dueno->id}", ['nombre' => 'Editado por admin'])
            ->assertOk()
            ->assertJsonPath('proveedor.nombre', 'Editado por admin');
    }

    public function test_upload_documento_de_perfil_ajeno_responde_403_y_anonimo_401(): void
    {
        Storage::fake('local');
        [$dueno] = $this->crearProveedorConUsuario();
        $ajeno = User::factory()->proveedor()->create();

        $archivo = UploadedFile::fake()->create('dpi.pdf', 200, 'application/pdf');

        $this->post("/api/providers/{$dueno->id}/documentos", [
            'documento'      => $archivo,
            'tipo_documento' => 'dpi',
        ], ['Accept' => 'application/json'])->assertUnauthorized();

        Sanctum::actingAs($ajeno);
        $this->post("/api/providers/{$dueno->id}/documentos", [
            'documento'      => $archivo,
            'tipo_documento' => 'dpi',
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_upload_documento_propio_funciona(): void
    {
        Storage::fake('local');
        [$dueno, $user] = $this->crearProveedorConUsuario();
        Sanctum::actingAs($user);

        $archivo = UploadedFile::fake()->create('dpi.pdf', 200, 'application/pdf');

        $this->post("/api/providers/{$dueno->id}/documentos", [
            'documento'      => $archivo,
            'tipo_documento' => 'dpi',
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_get_documentos_respeta_ownership(): void
    {
        [$dueno, $user] = $this->crearProveedorConUsuario();
        $ajeno = User::factory()->proveedor()->create();
        $admin = User::factory()->admin()->create();

        DocumentoProveedor::create([
            'proveedor_id'      => $dueno->id,
            'tipo_documento'    => 'dpi',
            'nombre_archivo'    => 'dpi.pdf',
            'ruta_archivo'      => 'documentos/' . $dueno->id . '/dpi.pdf',
            'estado_validacion' => 'pendiente',
        ]);

        Sanctum::actingAs($ajeno);
        $this->getJson("/api/providers/{$dueno->id}/documentos")->assertForbidden();

        Sanctum::actingAs($user);
        $this->getJson("/api/providers/{$dueno->id}/documentos")->assertOk();

        Sanctum::actingAs($admin);
        $this->getJson("/api/providers/{$dueno->id}/documentos")->assertOk();
    }

    public function test_get_documentos_de_proveedor_inexistente_responde_404(): void
    {
        $user = User::factory()->proveedor()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/providers/999999/documentos')->assertNotFound();
    }

    /**
     * @return array{0: Proveedor, 1: User}
     */
    private function crearProveedorConUsuario(): array
    {
        $user = User::factory()->proveedor()->create();

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'descripcion'  => 'Proveedor para pruebas de autorizacion.',
        ]);

        return [$proveedor, $user];
    }
}
