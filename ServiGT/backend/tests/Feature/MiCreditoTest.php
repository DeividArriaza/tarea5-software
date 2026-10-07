<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CreditoProveedor;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/mi-credito
 *
 * El objetivo de esta suite es garantizar que el frontend nunca tenga que
 * inferir el saldo: un 0 en la respuesta significa cero creditos reales y
 * cualquier otro caso llega como error con codigo y mensaje propios.
 */
class MiCreditoTest extends TestCase
{
    use DatabaseTransactions;

    public function test_proveedor_obtiene_su_saldo_real(): void
    {
        $proveedor = $this->crearProveedor(saldo: 4);

        Sanctum::actingAs($proveedor->user);

        $this->getJson('/api/mi-credito')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('proveedor_id', $proveedor->id)
            ->assertJsonPath('saldo', 4);
    }

    public function test_proveedor_sin_registro_de_creditos_recibe_saldo_cero_valido(): void
    {
        $proveedor = $this->crearProveedor(crearCredito: false);

        Sanctum::actingAs($proveedor->user);

        // Cero legitimo: 200 y success true. Es el unico caso donde la UI
        // debe mostrar "Saldo: 0".
        $this->getJson('/api/mi-credito')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('saldo', 0)
            ->assertJsonPath('actualizado_en', null);
    }

    public function test_proveedor_solo_ve_su_propio_saldo(): void
    {
        $propio = $this->crearProveedor(saldo: 2);
        $ajeno  = $this->crearProveedor(saldo: 99);

        Sanctum::actingAs($propio->user);

        $this->getJson('/api/mi-credito')
            ->assertOk()
            ->assertJsonPath('proveedor_id', $propio->id)
            ->assertJsonPath('saldo', 2);

        $this->assertNotSame($propio->id, $ajeno->id);
    }

    public function test_cliente_recibe_error_explicito_y_no_saldo_cero(): void
    {
        Sanctum::actingAs($this->crearUsuario('cliente'));

        $response = $this->getJson('/api/mi-credito');

        $response->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Solo los proveedores tienen saldo de creditos.');

        // La clave: un error jamas trae saldo, para que el frontend no lo
        // pueda leer como cero.
        $response->assertJsonMissingPath('saldo');
    }

    public function test_usuario_no_autenticado_recibe_401(): void
    {
        $this->getJson('/api/mi-credito')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_saldo_refleja_una_recarga_posterior(): void
    {
        $proveedor = $this->crearProveedor(saldo: 1);

        Sanctum::actingAs($proveedor->user);

        $this->getJson('/api/mi-credito')->assertJsonPath('saldo', 1);

        CreditoProveedor::where('proveedor_id', $proveedor->id)
            ->update(['saldo' => 6, 'updated_at' => now()]);

        // Sin cache: el endpoint lee el saldo vigente en cada llamada.
        $this->getJson('/api/mi-credito')->assertJsonPath('saldo', 6);
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

    private function crearProveedor(int $saldo = 0, bool $crearCredito = true): Proveedor
    {
        $user = $this->crearUsuario('proveedor');
        $categoria = Categoria::create([
            'nombre'      => 'Categoria mi-credito ' . uniqid(),
            'descripcion' => 'Categoria para pruebas de saldo propio',
        ]);

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion'  => 'Proveedor para pruebas de consulta de saldo',
        ]);

        if ($crearCredito) {
            CreditoProveedor::create([
                'proveedor_id' => $proveedor->id,
                'saldo'        => $saldo,
                'updated_at'   => now(),
            ]);
        }

        return $proveedor->setRelation('user', $user);
    }
}
