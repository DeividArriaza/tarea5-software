<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\PaqueteCredito;
use App\Models\Proveedor;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_login_excede_limite_y_se_recupera_al_vencer_ventana(): void
    {
        User::factory()->cliente()->create([
            'email' => 'rate-login@servigt.test',
            'password' => 'Password123!',
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.7.0.1'])
                ->postJson('/api/login', [
                    'email' => 'rate-login@servigt.test',
                    'password' => 'incorrecta',
                ])
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.7.0.1'])
            ->postJson('/api/login', [
                'email' => 'rate-login@servigt.test',
                'password' => 'incorrecta',
            ])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['message', 'retry_after', 'correlation_id']);

        $this->travel(61)->seconds();

        $this->withServerVariables(['REMOTE_ADDR' => '10.7.0.1'])
            ->postJson('/api/login', [
                'email' => 'rate-login@servigt.test',
                'password' => 'incorrecta',
            ])
            ->assertUnauthorized();
    }

    public function test_registro_usa_limite_por_ip(): void
    {
        $payload = [
            'name' => 'Registro Rate',
            'email' => 'rate-register@servigt.test',
            'password' => 'Password123!',
            'role' => 'cliente',
        ];

        $this->withServerVariables(['REMOTE_ADDR' => '10.7.0.2'])
            ->postJson('/api/register', $payload)
            ->assertCreated();

        for ($i = 0; $i < 2; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.7.0.2'])
                ->postJson('/api/register', $payload)
                ->assertStatus(422);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.7.0.2'])
            ->postJson('/api/register', $payload)
            ->assertStatus(429);
    }

    public function test_mensajes_usa_limite_por_usuario_autenticado(): void
    {
        // El chat exige un servicio en comun desde la task 2.3, asi que la
        // relacion se crea aqui a proposito: lo que mide esta prueba es el
        // limite por minuto, no la autorizacion de contacto.
        $proveedor = $this->crearProveedor();
        $emisor = User::factory()->cliente()->create();

        Servicio::create([
            'cliente_id'   => $emisor->id,
            'proveedor_id' => $proveedor->id,
            'descripcion'  => 'Servicio que habilita la conversacion',
            'estado'       => 'aceptado',
        ]);

        Sanctum::actingAs($emisor);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/mensajes', [
                'receptor_id' => $proveedor->user_id,
                'contenido' => 'Mensaje normal dentro del limite '.$i,
            ])->assertCreated();
        }

        $this->postJson('/api/mensajes', [
            'receptor_id' => $proveedor->user_id,
            'contenido' => 'Mensaje que excede el limite',
        ])->assertStatus(429);
    }

    public function test_uploads_usan_limite_por_usuario_autenticado(): void
    {
        $proveedor = $this->crearProveedor();
        Sanctum::actingAs($proveedor->user);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/providers/{$proveedor->id}/foto", [])
                ->assertStatus(422);
        }

        $this->postJson("/api/providers/{$proveedor->id}/foto", [])
            ->assertStatus(429);
    }

    public function test_compra_y_premium_limitan_por_usuario_sin_bloquear_a_otro_proveedor(): void
    {
        $paquete = PaqueteCredito::where('nombre', 'Inicial')->firstOrFail();
        $proveedor = $this->crearProveedor();

        Sanctum::actingAs($proveedor->user);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/creditos/comprar', [
                'paquete_id' => $paquete->id,
                'idempotency_key' => 'rate-compra-'.$i,
            ])->assertCreated();
        }

        $this->postJson('/api/creditos/comprar', [
            'paquete_id' => $paquete->id,
            'idempotency_key' => 'rate-compra-exceso',
        ])->assertStatus(429);

        Cache::flush();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/premium/activar')->assertOk();
        }

        $this->postJson('/api/premium/activar')->assertStatus(429);

        $otro = $this->crearProveedor();
        Sanctum::actingAs($otro->user);

        $this->postJson('/api/premium/activar')->assertOk();
    }

    private function crearProveedor(): Proveedor
    {
        $categoria = Categoria::factory()->create();
        $user = User::factory()->proveedor()->create();

        return Proveedor::create([
            'user_id' => $user->id,
            'nombre' => $user->name,
            'email' => $user->email,
            'departamento' => 'Guatemala',
            'municipio' => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion' => 'Proveedor para pruebas de rate limit.',
        ])->setRelation('user', $user);
    }
}
