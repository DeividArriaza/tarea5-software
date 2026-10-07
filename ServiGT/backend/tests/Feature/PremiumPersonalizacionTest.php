<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 2.4 — personalizacion de marca restringida a Premium activo.
 *
 * Antes cualquier proveedor podia subir portada y fijar `color_acento` sin
 * haber pagado nunca. Se cubren los tres estados que devuelve
 * `premiumEstado()`: `nunca`, `activo` y `vencido`.
 *
 * Los archivos de prueba se fabrican con `UploadedFile::fake()->create()` y un
 * mime explicito en vez de `->image()`: la imagen de PHP del backend no lleva
 * la extension GD, asi que `->image()` lanzaria LogicException antes de llegar
 * a la autorizacion, que es lo que se quiere medir.
 */
class PremiumPersonalizacionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function proveedorConPremium(?string $estado): Proveedor
    {
        $user = User::factory()->create(['role' => 'proveedor']);

        $vence = match ($estado) {
            'activo'  => now()->addDays(20),
            'vencido' => now()->subDay(),
            default   => null,
        };

        return Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => 'Proveedor Prueba',
            'email'        => $user->email,
            'telefono'     => '5555-0199',
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => Categoria::query()->value('id'),
            'premium_inicio_at' => $vence ? $vence->copy()->subMonth() : null,
            'premium_vence_at'  => $vence,
        ]);
    }

    private function actuarComoDuenno(Proveedor $proveedor): void
    {
        Sanctum::actingAs($proveedor->user);
    }

    public function test_proveedor_que_nunca_activo_premium_no_puede_fijar_color(): void
    {
        $proveedor = $this->proveedorConPremium(null);
        $this->actuarComoDuenno($proveedor);

        $this->putJson("/api/providers/{$proveedor->id}", ['color_acento' => '#123456'])
            ->assertStatus(403);

        $this->assertNull($proveedor->fresh()->color_acento);
    }

    public function test_proveedor_con_premium_vencido_no_puede_fijar_color(): void
    {
        $proveedor = $this->proveedorConPremium('vencido');
        $this->actuarComoDuenno($proveedor);

        $this->putJson("/api/providers/{$proveedor->id}", ['color_acento' => '#123456'])
            ->assertStatus(403);
    }

    public function test_proveedor_con_premium_activo_puede_fijar_color(): void
    {
        $proveedor = $this->proveedorConPremium('activo');
        $this->actuarComoDuenno($proveedor);

        $this->putJson("/api/providers/{$proveedor->id}", ['color_acento' => '#123456'])
            ->assertStatus(200);

        $this->assertSame('#123456', $proveedor->fresh()->color_acento);
    }

    public function test_editar_el_perfil_sin_tocar_la_marca_sigue_funcionando_sin_premium(): void
    {
        // El 403 debe apuntar a la prestacion Premium, no a la edicion normal
        // del perfil: si un proveedor gratis no pudiera cambiar su descripcion,
        // la task habria roto el flujo basico.
        $proveedor = $this->proveedorConPremium(null);
        $this->actuarComoDuenno($proveedor);

        $this->putJson("/api/providers/{$proveedor->id}", ['descripcion' => 'Nueva descripcion'])
            ->assertStatus(200);

        $this->assertSame('Nueva descripcion', $proveedor->fresh()->descripcion);
    }

    public function test_subir_portada_exige_premium_activo(): void
    {
        $sinPremium = $this->proveedorConPremium(null);
        $this->actuarComoDuenno($sinPremium);

        $this->postJson("/api/providers/{$sinPremium->id}/portada", [
            'portada' => UploadedFile::fake()->create('portada.jpg', 100, 'image/jpeg'),
        ])->assertStatus(403);

        $vencido = $this->proveedorConPremium('vencido');
        $this->actuarComoDuenno($vencido);

        $this->postJson("/api/providers/{$vencido->id}/portada", [
            'portada' => UploadedFile::fake()->create('portada.jpg', 100, 'image/jpeg'),
        ])->assertStatus(403);

        $activo = $this->proveedorConPremium('activo');
        $this->actuarComoDuenno($activo);

        $this->postJson("/api/providers/{$activo->id}/portada", [
            'portada' => UploadedFile::fake()->create('portada.jpg', 100, 'image/jpeg'),
        ])->assertStatus(200);

        $this->assertNotNull($activo->fresh()->portada);
    }

    public function test_al_vencer_no_se_borra_la_portada_y_el_perfil_publico_usa_fallback(): void
    {
        $proveedor = $this->proveedorConPremium('activo');
        $this->actuarComoDuenno($proveedor);

        $this->postJson("/api/providers/{$proveedor->id}/portada", [
            'portada' => UploadedFile::fake()->create('portada.jpg', 100, 'image/jpeg'),
        ])->assertStatus(200);

        $this->putJson("/api/providers/{$proveedor->id}", ['color_acento' => '#abcdef'])
            ->assertStatus(200);

        $rutaGuardada = $proveedor->fresh()->portada;
        $this->assertNotNull($rutaGuardada);

        // Vence el Premium.
        $proveedor->update(['premium_vence_at' => now()->subDay()]);

        // La fila conserva ruta y color: no se borra nada.
        $this->assertSame($rutaGuardada, $proveedor->fresh()->portada);
        $this->assertSame('#abcdef', $proveedor->fresh()->color_acento);

        // Pero el perfil publico deja de entregarlos, asi que la UI cae al
        // degradado y al color de marca sin logica propia.
        $publico = $this->getJson("/api/providers/{$proveedor->id}")->assertStatus(200);
        $this->assertNull($publico->json('proveedor.portada'));
        $this->assertNull($publico->json('proveedor.color_acento'));

        // El duenno si los sigue viendo, con el indicador de que estan inactivos.
        $propio = $this->getJson('/api/providers/me')->assertStatus(200);
        $this->assertSame($rutaGuardada, $propio->json('proveedor.portada'));
        $this->assertSame('#abcdef', $propio->json('proveedor.color_acento'));
        $this->assertFalse($propio->json('proveedor.marca_activa'));
    }

    public function test_premium_badge_y_verificacion_siguen_siendo_conceptos_distintos(): void
    {
        // `premium_estado` alimenta el badge; la personalizacion de marca es
        // otra prestacion. Un proveedor vencido conserva el estado 'vencido'
        // —el badge sabe que no debe pintarse— sin que eso implique nada sobre
        // documentos verificados.
        $proveedor = $this->proveedorConPremium('vencido');

        $publico = $this->getJson("/api/providers/{$proveedor->id}")->assertStatus(200);

        $this->assertSame('vencido', $publico->json('proveedor.premium_estado'));
        $this->assertArrayNotHasKey('documentos', $publico->json('proveedor'));
        $this->assertArrayNotHasKey('verificado', $publico->json('proveedor'));
    }
}
