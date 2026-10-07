<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\PublicacionServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicacionServicioApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_catalogo_publico_muestra_solo_publicaciones_activas_sin_campos_privados(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor();

        PublicacionServicio::create([
            'proveedor_id' => $proveedor->id,
            'categoria_id' => $categoria->id,
            'titulo' => 'Instalacion electrica segura',
            'descripcion' => 'Servicio publicado y visible para clientes del catalogo.',
            'precio_referencial' => 250,
            'estado' => 'activa',
        ]);

        PublicacionServicio::create([
            'proveedor_id' => $proveedor->id,
            'categoria_id' => $categoria->id,
            'titulo' => 'Publicacion pausada',
            'descripcion' => 'Esta publicacion no debe aparecer en el catalogo publico.',
            'estado' => 'inactiva',
        ]);

        $this->getJson('/api/publicaciones')
            ->assertOk()
            ->assertJsonCount(1, 'publicaciones')
            ->assertJsonPath('publicaciones.0.titulo', 'Instalacion electrica segura')
            ->assertJsonMissing(['titulo' => 'Publicacion pausada'])
            ->assertJsonMissingPath('publicaciones.0.proveedor.email')
            ->assertJsonMissingPath('publicaciones.0.proveedor.user_id');
    }

    public function test_proveedor_propietario_administra_crud_de_sus_publicaciones(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $publicacionId = $this->postJson('/api/publicaciones', [
            'proveedor_id' => 999999,
            'categoria_id' => $categoria->id,
            'titulo' => 'Reparacion de tuberia residencial',
            'descripcion' => 'Atencion de fugas, revision de tuberias y reparaciones menores.',
            'precio_referencial' => 175.50,
        ])
            ->assertCreated()
            ->assertJsonPath('publicacion.proveedor.id', $proveedor->id)
            ->assertJsonPath('publicacion.estado', 'activa')
            ->json('publicacion.id');

        $this->assertDatabaseHas('publicaciones_servicio', [
            'id' => $publicacionId,
            'proveedor_id' => $proveedor->id,
            'titulo' => 'Reparacion de tuberia residencial',
        ]);

        $this->putJson("/api/publicaciones/{$publicacionId}", [
            'titulo' => 'Reparacion de tuberia y drenajes',
            'descripcion' => 'Atencion de fugas, drenajes obstruidos y reparaciones menores.',
            'estado' => 'inactiva',
        ])
            ->assertOk()
            ->assertJsonPath('publicacion.titulo', 'Reparacion de tuberia y drenajes')
            ->assertJsonPath('publicacion.estado', 'inactiva');

        $this->postJson("/api/publicaciones/{$publicacionId}/activar")
            ->assertOk()
            ->assertJsonPath('publicacion.estado', 'activa');

        $this->postJson("/api/publicaciones/{$publicacionId}/desactivar")
            ->assertOk()
            ->assertJsonPath('publicacion.estado', 'inactiva');

        $this->deleteJson("/api/publicaciones/{$publicacionId}")
            ->assertOk();

        $this->assertDatabaseMissing('publicaciones_servicio', ['id' => $publicacionId]);
    }

    public function test_anonimo_cliente_y_proveedor_ajeno_no_administran_publicaciones(): void
    {
        [$dueno, $categoria] = $this->crearProveedor();
        [$ajeno] = $this->crearProveedor();
        $cliente = User::factory()->cliente()->create();

        $publicacion = PublicacionServicio::create([
            'proveedor_id' => $dueno->id,
            'categoria_id' => $categoria->id,
            'titulo' => 'Servicio protegido',
            'descripcion' => 'Publicacion creada para probar reglas de autorizacion.',
            'estado' => 'activa',
        ]);

        $payload = [
            'categoria_id' => $categoria->id,
            'titulo' => 'Nueva publicacion',
            'descripcion' => 'Descripcion suficientemente larga para pasar validacion.',
        ];

        $this->postJson('/api/publicaciones', $payload)->assertUnauthorized();

        Sanctum::actingAs($cliente);
        $this->postJson('/api/publicaciones', $payload)->assertForbidden();

        Sanctum::actingAs($ajeno->user);
        $this->putJson("/api/publicaciones/{$publicacion->id}", [
            'titulo' => 'Intento ajeno',
            'descripcion' => 'Intento de edicion por otro proveedor.',
        ])->assertForbidden();
    }

    public function test_payload_e_imagen_invalidos_fallan_validacion_backend(): void
    {
        Storage::fake('public');
        [$proveedor, $categoria] = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->postJson('/api/publicaciones', [
            'categoria_id' => $categoria->id,
            'titulo' => 'abc',
            'descripcion' => 'corta',
            'precio_referencial' => -1,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['titulo', 'descripcion', 'precio_referencial']);

        $this->post('/api/publicaciones', [
            'categoria_id' => $categoria->id,
            'titulo' => 'Publicacion con imagen invalida',
            'descripcion' => 'Descripcion valida para confirmar que falla solo la imagen.',
            'imagen' => UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['imagen']);
    }

    private function crearProveedor(): array
    {
        $categoria = Categoria::factory()->create();
        $user = User::factory()->proveedor()->create();

        $proveedor = Proveedor::create([
            'user_id' => $user->id,
            'nombre' => $user->name,
            'email' => $user->email,
            'departamento' => 'Guatemala',
            'municipio' => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion' => 'Proveedor para pruebas de publicaciones.',
        ])->setRelation('user', $user);

        return [$proveedor, $categoria];
    }
}
