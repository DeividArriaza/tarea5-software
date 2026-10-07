<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CreditoProveedor;
use App\Models\Proveedor;
use App\Models\TransaccionCredito;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/premium/activar y GET /api/premium/mi-estado
 */
class PremiumControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_proveedor_nunca_activado_ve_estado_nunca(): void
    {
        $proveedor = $this->crearProveedor();

        Sanctum::actingAs($proveedor->user);

        $this->getJson('/api/premium/mi-estado')
            ->assertOk()
            ->assertJsonPath('estado', 'nunca')
            ->assertJsonPath('vence_at', null);
    }

    public function test_activar_fija_vigencia_de_30_dias_y_acredita_bono_premium(): void
    {
        $proveedor = $this->crearProveedor(saldo: 3);

        Sanctum::actingAs($proveedor->user);

        $response = $this->postJson('/api/premium/activar')
            ->assertOk()
            ->assertJsonPath('estado', 'activo')
            ->assertJsonPath('creditos_acreditados', 10)
            ->assertJsonPath('saldo', 13)
            ->assertJsonPath('creditos_por_ciclo', 10)
            ->assertJsonPath('vigencia_dias', 30);

        $venceAt = \Carbon\Carbon::parse($response->json('vence_at'));
        $this->assertTrue($venceAt->isBetween(now()->addDays(29), now()->addDays(31)));

        $this->assertEquals(13, CreditoProveedor::where('proveedor_id', $proveedor->id)->first()->saldo);
        $this->assertDatabaseHas('transacciones_credito', [
            'proveedor_id' => $proveedor->id,
            'tipo'         => 'bono',
            'monto'        => 10,
        ]);

        $this->getJson('/api/premium/mi-estado')
            ->assertJsonPath('estado', 'activo')
            ->assertJsonPath('renovaciones', 1);
    }

    public function test_proveedor_con_vigencia_pasada_aparece_vencido(): void
    {
        $proveedor = $this->crearProveedor();
        $proveedor->premium_vence_at = now()->subDays(5);
        $proveedor->save();

        Sanctum::actingAs($proveedor->user);

        $this->getJson('/api/premium/mi-estado')
            ->assertOk()
            ->assertJsonPath('estado', 'vencido')
            ->assertJsonPath('dias_restantes', 0);
    }

    public function test_repetir_activacion_activa_no_extiende_ni_duplica_bono(): void
    {
        $proveedor = $this->crearProveedor(saldo: 0);

        Sanctum::actingAs($proveedor->user);

        $primera = $this->postJson('/api/premium/activar')->assertOk();
        $segunda = $this->postJson('/api/premium/activar')
            ->assertOk()
            ->assertJsonPath('creditos_acreditados', 0)
            ->assertJsonPath('saldo', 10)
            ->assertJsonPath('ciclo_nuevo', false);

        $this->assertEquals($primera->json('ciclo_actual'), $segunda->json('ciclo_actual'));
        $this->assertEquals(
            1,
            TransaccionCredito::where('proveedor_id', $proveedor->id)
                ->where('tipo', 'bono')
                ->where('motivo', 'like', 'Bono mensual Premium%')
                ->count()
        );
    }

    public function test_reactivar_premium_vencido_abre_nuevo_ciclo_y_acredita_10(): void
    {
        $proveedor = $this->crearProveedor(saldo: 4);
        $proveedor->premium_inicio_at = now()->subDays(45);
        $proveedor->premium_vence_at = now()->subDays(15);
        $proveedor->premium_ciclo_key = 'premium-test-viejo';
        $proveedor->premium_renovaciones = 1;
        $proveedor->save();

        Sanctum::actingAs($proveedor->user);

        $response = $this->postJson('/api/premium/activar')
            ->assertOk()
            ->assertJsonPath('estado', 'activo')
            ->assertJsonPath('creditos_acreditados', 10)
            ->assertJsonPath('renovaciones', 2);

        $this->assertNotEquals('premium-test-viejo', $response->json('ciclo_actual'));
        $this->assertEquals(14, CreditoProveedor::where('proveedor_id', $proveedor->id)->first()->saldo);
    }

    public function test_cliente_no_puede_activar_premium(): void
    {
        Sanctum::actingAs($this->crearUsuario('cliente'));

        $this->postJson('/api/premium/activar')->assertForbidden();
    }

    public function test_usuario_no_autenticado_recibe_401(): void
    {
        $this->postJson('/api/premium/activar')->assertUnauthorized();
        $this->getJson('/api/premium/mi-estado')->assertUnauthorized();
    }

    private function crearUsuario(string $role): User
    {
        $uid = uniqid($role . '.', true);

        return User::create([
            'name'     => ucfirst($role) . ' Test',
            'email'    => "{$uid}@servigt.test",
            'password' => 'password-test',
            'role'     => $role,
        ]);
    }

    private function crearProveedor(int $saldo = 0): Proveedor
    {
        $user = $this->crearUsuario('proveedor');
        $categoria = Categoria::create([
            'nombre'      => 'Categoria premium ' . uniqid(),
            'descripcion' => 'Categoria para pruebas de estado Premium',
        ]);

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion'  => 'Proveedor para pruebas de Premium',
        ]);

        CreditoProveedor::create([
            'proveedor_id' => $proveedor->id,
            'saldo'        => $saldo,
            'updated_at'   => now(),
        ]);

        return $proveedor->setRelation('user', $user);
    }
}
