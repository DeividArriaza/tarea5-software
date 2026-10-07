<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\PublicacionServicio;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServicioDesdePublicacionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cliente_crea_servicio_desde_publicacion_visible_derivando_datos_reales(): void
    {
        [$cliente, $proveedor, $categoria] = $this->crearActores();
        $otroProveedor = $this->crearProveedor($this->categoria('Jardineria'));
        $otraCategoria = $this->categoria('Pintura');
        $publicacion = $this->crearPublicacion($proveedor, $categoria, [
            'titulo' => 'Instalacion electrica residencial',
            'precio_referencial' => 275.50,
        ]);

        Sanctum::actingAs($cliente);

        $servicioId = $this->postJson('/api/servicios', [
            'publicacion_id' => $publicacion->id,
            'proveedor_id' => $otroProveedor->id,
            'categoria_id' => $otraCategoria->id,
            'descripcion' => 'Necesito instalar luminarias en la sala principal',
            'monto_acordado' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('servicio.proveedor_id', $proveedor->id)
            ->assertJsonPath('servicio.categoria_id', $categoria->id)
            ->assertJsonPath('servicio.publicacion_id', $publicacion->id)
            ->assertJsonPath('servicio.publicacion_titulo', 'Instalacion electrica residencial')
            ->assertJsonPath('servicio.publicacion_precio_referencial', 275.50)
            ->assertJsonPath('servicio.monto_acordado', '275.50')
            ->json('servicio.id');

        $this->assertDatabaseHas('servicios', [
            'id' => $servicioId,
            'cliente_id' => $cliente->id,
            'proveedor_id' => $proveedor->id,
            'categoria_id' => $categoria->id,
            'publicacion_id' => $publicacion->id,
            'publicacion_titulo' => 'Instalacion electrica residencial',
            'publicacion_precio_referencial' => 275.50,
            'monto_acordado' => 275.50,
        ]);
    }

    public function test_publicacion_inexistente_o_inactiva_no_se_puede_contratar(): void
    {
        [$cliente, $proveedor, $categoria] = $this->crearActores();
        $inactiva = $this->crearPublicacion($proveedor, $categoria, ['estado' => 'inactiva']);

        Sanctum::actingAs($cliente);

        $this->postJson('/api/servicios', [
            'publicacion_id' => 999999,
            'descripcion' => 'Necesito contratar una publicacion inexistente',
        ])->assertNotFound();

        $this->postJson('/api/servicios', [
            'publicacion_id' => $inactiva->id,
            'descripcion' => 'Necesito contratar una publicacion pausada',
        ])->assertUnprocessable();
    }

    public function test_publicacion_activa_fuera_de_ventana_visible_no_se_puede_contratar(): void
    {
        [$cliente, $proveedor, $categoria] = $this->crearActores();
        $visible = $this->crearPublicacion($proveedor, $categoria, ['titulo' => 'Publicacion visible'], now()->subDays(2));
        $ocultaPorLimite = $this->crearPublicacion($proveedor, $categoria, ['titulo' => 'Publicacion fuera de limite'], now()->subDay());

        Sanctum::actingAs($cliente);

        $this->postJson('/api/servicios', [
            'publicacion_id' => $visible->id,
            'descripcion' => 'Necesito contratar la publicacion visible',
        ])->assertCreated();

        $this->postJson('/api/servicios', [
            'publicacion_id' => $ocultaPorLimite->id,
            'descripcion' => 'Necesito contratar una publicacion no visible',
        ])->assertUnprocessable();
    }

    public function test_proveedor_no_puede_autocontratar_su_publicacion(): void
    {
        [, $proveedor, $categoria] = $this->crearActores();
        $publicacion = $this->crearPublicacion($proveedor, $categoria);

        Sanctum::actingAs($proveedor->user);

        $this->postJson('/api/servicios', [
            'publicacion_id' => $publicacion->id,
            'descripcion' => 'Intento solicitar mi propia publicacion',
        ])->assertForbidden();
    }

    public function test_editar_o_desactivar_publicacion_no_altera_snapshot_del_servicio(): void
    {
        [$cliente, $proveedor, $categoria] = $this->crearActores();
        $publicacion = $this->crearPublicacion($proveedor, $categoria, [
            'titulo' => 'Servicio original',
            'precio_referencial' => 150,
        ]);

        Sanctum::actingAs($cliente);

        $servicioId = $this->postJson('/api/servicios', [
            'publicacion_id' => $publicacion->id,
            'descripcion' => 'Necesito contratar el servicio original',
        ])->assertCreated()->json('servicio.id');

        $publicacion->update([
            'titulo' => 'Servicio editado',
            'precio_referencial' => 999,
            'estado' => 'inactiva',
        ]);

        $servicio = Servicio::findOrFail($servicioId);

        $this->assertSame($publicacion->id, $servicio->publicacion_id);
        $this->assertSame('Servicio original', $servicio->publicacion_titulo);
        $this->assertSame('150.00', (string) $servicio->publicacion_precio_referencial);
        $this->assertSame('150.00', (string) $servicio->monto_acordado);
    }

    private function crearActores(): array
    {
        $categoria = $this->categoria('Electricidad');
        $cliente = User::factory()->cliente()->create();
        $proveedor = $this->crearProveedor($categoria);

        return [$cliente, $proveedor, $categoria];
    }

    private function categoria(string $nombre): Categoria
    {
        return Categoria::firstOrCreate(
            ['nombre' => $nombre],
            ['descripcion' => 'Categoria de prueba para servicios desde publicacion.']
        );
    }

    private function crearProveedor(Categoria $categoria): Proveedor
    {
        $user = User::factory()->proveedor()->create();

        return Proveedor::create([
            'user_id' => $user->id,
            'nombre' => $user->name,
            'email' => $user->email,
            'departamento' => 'Guatemala',
            'municipio' => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion' => 'Proveedor de prueba para publicaciones.',
        ])->setRelation('user', $user);
    }

    private function crearPublicacion(
        Proveedor $proveedor,
        Categoria $categoria,
        array $overrides = [],
        $createdAt = null
    ): PublicacionServicio {
        $publicacion = PublicacionServicio::create(array_merge([
            'proveedor_id' => $proveedor->id,
            'categoria_id' => $categoria->id,
            'titulo' => 'Servicio publicado',
            'descripcion' => 'Descripcion completa del servicio publicado para pruebas.',
            'precio_referencial' => 200,
            'estado' => 'activa',
        ], $overrides));

        if ($createdAt) {
            $publicacion->forceFill(['created_at' => $createdAt])->save();
        }

        return $publicacion;
    }
}
