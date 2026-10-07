<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\PublicacionServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicacionServicioLimiteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_proveedor_gratis_no_supera_una_publicacion_activa(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor();
        $this->crearPublicacion($proveedor, $categoria, 'activa');
        Sanctum::actingAs($proveedor->user);

        $this->postJson('/api/publicaciones', $this->payload($categoria, 'Segunda publicacion gratis'))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('publicaciones_servicio', 1);
    }

    public function test_proveedor_gratis_sin_activas_puede_crear_una(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        $this->postJson('/api/publicaciones', $this->payload($categoria, 'Primera publicacion gratis'))
            ->assertCreated()
            ->assertJsonPath('publicacion.estado', 'activa');
    }

    public function test_proveedor_premium_activo_no_supera_tres_publicaciones_activas(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor(premium: true);
        $this->crearPublicacion($proveedor, $categoria, 'activa');
        $this->crearPublicacion($proveedor, $categoria, 'activa');
        $this->crearPublicacion($proveedor, $categoria, 'activa');
        Sanctum::actingAs($proveedor->user);

        $this->postJson('/api/publicaciones', $this->payload($categoria, 'Cuarta publicacion premium'))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('publicaciones_servicio', 3);
    }

    public function test_proveedor_premium_activo_con_dos_activas_puede_crear_la_tercera(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor(premium: true);
        $this->crearPublicacion($proveedor, $categoria, 'activa');
        $this->crearPublicacion($proveedor, $categoria, 'activa');
        Sanctum::actingAs($proveedor->user);

        $this->postJson('/api/publicaciones', $this->payload($categoria, 'Tercera publicacion premium'))
            ->assertCreated();

        $this->assertDatabaseCount('publicaciones_servicio', 3);
    }

    public function test_activar_publicacion_respeta_el_mismo_limite_que_crear(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor();
        $this->crearPublicacion($proveedor, $categoria, 'activa');
        $inactiva = $this->crearPublicacion($proveedor, $categoria, 'inactiva');
        Sanctum::actingAs($proveedor->user);

        $this->postJson("/api/publicaciones/{$inactiva->id}/activar")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('publicaciones_servicio', [
            'id' => $inactiva->id,
            'estado' => 'inactiva',
        ]);
    }

    public function test_lectura_publica_no_muta_publicaciones_de_proveedor_vencido_con_excedentes(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor(premiumVencido: true);
        $masAntigua = $this->crearPublicacion($proveedor, $categoria, 'activa', now()->subDays(3));
        $this->crearPublicacion($proveedor, $categoria, 'activa', now()->subDays(2));
        $this->crearPublicacion($proveedor, $categoria, 'activa', now()->subDay());

        $this->getJson('/api/publicaciones?proveedor_id=' . $proveedor->id)
            ->assertOk()
            ->assertJsonCount(1, 'publicaciones')
            ->assertJsonPath('publicaciones.0.id', $masAntigua->id);

        $this->assertDatabaseCount('publicaciones_servicio', 3);
        $this->assertDatabaseHas('publicaciones_servicio', ['id' => $masAntigua->id, 'estado' => 'activa']);
    }

    public function test_escritura_autenticada_normaliza_excedentes_de_proveedor_vencido(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor(premiumVencido: true);
        $masAntigua = $this->crearPublicacion($proveedor, $categoria, 'activa', now()->subDays(3));
        $segunda = $this->crearPublicacion($proveedor, $categoria, 'activa', now()->subDays(2));
        $tercera = $this->crearPublicacion($proveedor, $categoria, 'activa', now()->subDay());
        Sanctum::actingAs($proveedor->user);

        $this->postJson("/api/publicaciones/{$tercera->id}/desactivar")->assertOk();

        $this->assertDatabaseHas('publicaciones_servicio', ['id' => $masAntigua->id, 'estado' => 'activa']);
        $this->assertDatabaseHas('publicaciones_servicio', ['id' => $segunda->id, 'estado' => 'inactiva']);
        $this->assertDatabaseHas('publicaciones_servicio', ['id' => $tercera->id, 'estado' => 'inactiva']);
        $this->assertDatabaseCount('publicaciones_servicio', 3);
    }

    public function test_meta_de_paginacion_refleja_solo_publicaciones_visibles(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor(premiumVencido: true);
        $this->crearPublicacion($proveedor, $categoria, 'activa', now()->subDays(3));
        $this->crearPublicacion($proveedor, $categoria, 'activa', now()->subDays(2));
        $this->crearPublicacion($proveedor, $categoria, 'activa', now()->subDay());

        $this->getJson('/api/publicaciones?proveedor_id=' . $proveedor->id . '&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'publicaciones')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1);
    }

    private function payload(Categoria $categoria, string $titulo): array
    {
        return [
            'categoria_id' => $categoria->id,
            'titulo' => $titulo,
            'descripcion' => 'Descripcion suficientemente larga para pasar la validacion del backend.',
            'precio_referencial' => 100,
        ];
    }

    private function crearPublicacion(Proveedor $proveedor, Categoria $categoria, string $estado, $createdAt = null): PublicacionServicio
    {
        $publicacion = PublicacionServicio::create([
            'proveedor_id' => $proveedor->id,
            'categoria_id' => $categoria->id,
            'titulo' => 'Publicacion de prueba ' . uniqid(),
            'descripcion' => 'Descripcion suficientemente larga para pasar la validacion del backend.',
            'precio_referencial' => 100,
            'estado' => $estado,
        ]);

        if ($createdAt) {
            $publicacion->forceFill(['created_at' => $createdAt])->save();
        }

        return $publicacion;
    }

    private function crearProveedor(bool $premium = false, bool $premiumVencido = false): array
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
            'descripcion' => 'Proveedor para pruebas de limite de publicaciones.',
            'premium_vence_at' => $premium ? now()->addDays(10) : ($premiumVencido ? now()->subDays(5) : null),
        ])->setRelation('user', $user);

        return [$proveedor, $categoria];
    }
}
