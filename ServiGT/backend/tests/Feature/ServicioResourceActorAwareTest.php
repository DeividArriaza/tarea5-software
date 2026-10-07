<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServicioResourceActorAwareTest extends TestCase
{
    use DatabaseTransactions;

    public function test_proveedor_no_recibe_codigo_inicio_en_detalle_ni_listado(): void
    {
        [$cliente, $proveedor] = $this->crearActores();
        $servicio = $this->crearServicio($cliente, $proveedor, ['estado' => 'aceptado']);

        Sanctum::actingAs($proveedor->user);

        $this->getJson("/api/servicios/{$servicio->id}")
            ->assertOk()
            ->assertJsonMissingPath('servicio.codigo_inicio')
            ->assertJsonMissingPath('servicio.codigo_fin');

        $this->getJson('/api/servicios/proveedor')
            ->assertOk()
            ->assertJsonMissingPath('servicios.0.codigo_inicio')
            ->assertJsonMissingPath('servicios.0.codigo_fin');
    }

    public function test_cliente_recibe_codigo_inicio_autorizado_y_no_recibe_codigo_fin(): void
    {
        [$cliente, $proveedor] = $this->crearActores();
        $servicio = $this->crearServicio($cliente, $proveedor, [
            'estado' => 'por_confirmar',
            'codigo_fin' => '987654',
        ]);

        Sanctum::actingAs($cliente);

        $this->getJson("/api/servicios/{$servicio->id}")
            ->assertOk()
            ->assertJsonPath('servicio.codigo_inicio', '123456')
            ->assertJsonMissingPath('servicio.codigo_fin');
    }

    public function test_tercero_no_puede_ver_servicio_ni_codigos(): void
    {
        [$cliente, $proveedor] = $this->crearActores();
        $tercero = User::factory()->cliente()->create();
        $servicio = $this->crearServicio($cliente, $proveedor, ['estado' => 'aceptado']);

        Sanctum::actingAs($tercero);

        $this->getJson("/api/servicios/{$servicio->id}")
            ->assertForbidden()
            ->assertJsonMissingPath('servicio.codigo_inicio')
            ->assertJsonMissingPath('servicio.codigo_fin');
    }

    public function test_codigo_fin_solo_aparece_en_handoff_de_finalizar(): void
    {
        [$cliente, $proveedor] = $this->crearActores();
        $servicio = $this->crearServicio($cliente, $proveedor, ['estado' => 'en_progreso']);

        Sanctum::actingAs($proveedor->user);

        $codigoFin = $this->postJson("/api/servicios/{$servicio->id}/finalizar")
            ->assertOk()
            ->assertJsonPath('servicio.estado', 'por_confirmar')
            ->assertJsonStructure(['codigo_fin', 'servicio' => ['codigo_fin']])
            ->json('codigo_fin');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $codigoFin);

        $this->getJson("/api/servicios/{$servicio->id}")
            ->assertOk()
            ->assertJsonMissingPath('servicio.codigo_inicio')
            ->assertJsonMissingPath('servicio.codigo_fin');
    }

    private function crearActores(): array
    {
        $categoria = Categoria::factory()->create();
        $cliente = User::factory()->cliente()->create();
        $proveedorUser = User::factory()->proveedor()->create();

        $proveedor = Proveedor::create([
            'user_id' => $proveedorUser->id,
            'nombre' => $proveedorUser->name,
            'email' => $proveedorUser->email,
            'departamento' => 'Guatemala',
            'municipio' => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion' => 'Proveedor de prueba para servicios seguros.',
        ])->setRelation('user', $proveedorUser);

        return [$cliente, $proveedor, $categoria];
    }

    private function crearServicio(User $cliente, Proveedor $proveedor, array $overrides = []): Servicio
    {
        return Servicio::create(array_merge([
            'cliente_id' => $cliente->id,
            'proveedor_id' => $proveedor->id,
            'categoria_id' => $proveedor->categoria_id,
            'descripcion' => 'Servicio de prueba para verificar serializacion actor-aware.',
            'direccion' => 'Zona 10, Guatemala',
            'estado' => 'aceptado',
            'codigo_inicio' => '123456',
        ], $overrides));
    }
}
