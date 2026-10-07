<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\PublicacionServicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicacionServicioMiasTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mias_expone_cupos_activas_y_limite_para_proveedor_gratis(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor();
        $this->crearPublicacion($proveedor, $categoria, 'activa');
        $this->crearPublicacion($proveedor, $categoria, 'inactiva');
        Sanctum::actingAs($proveedor->user);

        $this->getJson('/api/publicaciones/mias')
            ->assertOk()
            ->assertJsonCount(2, 'publicaciones')
            ->assertJsonPath('total', 2)
            ->assertJsonPath('cupos.activas', 1)
            ->assertJsonPath('cupos.limite', 1)
            ->assertJsonPath('cupos.disponibles', 0);
    }

    public function test_mias_expone_cupos_activas_y_limite_para_proveedor_premium(): void
    {
        [$proveedor, $categoria] = $this->crearProveedor(premium: true);
        $this->crearPublicacion($proveedor, $categoria, 'activa');
        Sanctum::actingAs($proveedor->user);

        $this->getJson('/api/publicaciones/mias')
            ->assertOk()
            ->assertJsonPath('cupos.activas', 1)
            ->assertJsonPath('cupos.limite', 3)
            ->assertJsonPath('cupos.disponibles', 2);
    }

    public function test_mias_requiere_autenticacion_y_rol_proveedor(): void
    {
        $this->getJson('/api/publicaciones/mias')->assertUnauthorized();

        $cliente = User::factory()->cliente()->create();
        Sanctum::actingAs($cliente);
        $this->getJson('/api/publicaciones/mias')->assertForbidden();
    }

    private function crearPublicacion(Proveedor $proveedor, Categoria $categoria, string $estado): PublicacionServicio
    {
        return PublicacionServicio::create([
            'proveedor_id' => $proveedor->id,
            'categoria_id' => $categoria->id,
            'titulo' => 'Publicacion de prueba ' . uniqid(),
            'descripcion' => 'Descripcion suficientemente larga para pasar la validacion del backend.',
            'precio_referencial' => 100,
            'estado' => $estado,
        ]);
    }

    private function crearProveedor(bool $premium = false): array
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
            'descripcion' => 'Proveedor para pruebas de mias().',
            'premium_vence_at' => $premium ? now()->addDays(10) : null,
        ])->setRelation('user', $user);

        return [$proveedor, $categoria];
    }
}