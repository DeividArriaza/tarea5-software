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
 * GET /api/creditos/transacciones
 */
class CreditoTransaccionesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_lista_solo_las_transacciones_del_proveedor_autenticado(): void
    {
        $propio = $this->crearProveedor();
        $ajeno = $this->crearProveedor();

        TransaccionCredito::create([
            'proveedor_id' => $propio->id,
            'tipo'         => 'recarga',
            'monto'        => 5,
            'motivo'       => 'Recarga de prueba',
        ]);
        TransaccionCredito::create([
            'proveedor_id' => $propio->id,
            'tipo'         => 'gasto',
            'monto'        => 1,
            'motivo'       => 'Cotizacion de prueba',
        ]);
        TransaccionCredito::create([
            'proveedor_id' => $ajeno->id,
            'tipo'         => 'bono',
            'monto'        => 10,
            'motivo'       => 'Bono mensual Premium',
        ]);

        Sanctum::actingAs($propio->user);

        $response = $this->getJson('/api/creditos/transacciones')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $tipos = collect($response->json('transacciones'))->pluck('tipo');
        $this->assertEqualsCanonicalizing(['recarga', 'gasto'], $tipos->all());
    }

    public function test_orden_descendente_por_fecha(): void
    {
        $proveedor = $this->crearProveedor();

        $vieja = TransaccionCredito::create([
            'proveedor_id' => $proveedor->id,
            'tipo'         => 'recarga',
            'monto'        => 3,
            'motivo'       => 'Primera',
        ]);
        $vieja->created_at = now()->subDays(2);
        $vieja->save();

        TransaccionCredito::create([
            'proveedor_id' => $proveedor->id,
            'tipo'         => 'gasto',
            'monto'        => 1,
            'motivo'       => 'Segunda',
        ]);

        Sanctum::actingAs($proveedor->user);

        $response = $this->getJson('/api/creditos/transacciones');
        $motivos = collect($response->json('transacciones'))->pluck('motivo');

        $this->assertEquals(['Segunda', 'Primera'], $motivos->all());
    }

    public function test_cliente_no_tiene_historial_de_creditos(): void
    {
        Sanctum::actingAs($this->crearUsuario('cliente'));

        $this->getJson('/api/creditos/transacciones')->assertForbidden();
    }

    public function test_usuario_no_autenticado_recibe_401(): void
    {
        $this->getJson('/api/creditos/transacciones')->assertUnauthorized();
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

    private function crearProveedor(): Proveedor
    {
        $user = $this->crearUsuario('proveedor');
        $categoria = Categoria::create([
            'nombre'      => 'Categoria transacciones ' . uniqid(),
            'descripcion' => 'Categoria para pruebas de historial de creditos',
        ]);

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion'  => 'Proveedor para pruebas de historial',
        ]);

        CreditoProveedor::create([
            'proveedor_id' => $proveedor->id,
            'saldo'        => 0,
            'updated_at'   => now(),
        ]);

        return $proveedor->setRelation('user', $user);
    }
}
