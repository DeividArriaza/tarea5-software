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

class AdminRecargaCreditosTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_puede_recargar_creditos_y_registra_transaccion(): void
    {
        $admin = $this->crearUsuario('admin');
        $proveedor = $this->crearProveedor(saldo: 2);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/proveedores/{$proveedor->id}/creditos", [
            'monto'  => 5,
            'motivo' => 'Recarga manual por validacion QA',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('credito.proveedor_id', $proveedor->id)
            ->assertJsonPath('credito.saldo', 7)
            ->assertJsonPath('transaccion.tipo', 'recarga')
            ->assertJsonPath('transaccion.monto', 5);

        $this->assertDatabaseHas('creditos_proveedor', [
            'proveedor_id' => $proveedor->id,
            'saldo'        => 7,
        ]);

        $this->assertDatabaseHas('transacciones_credito', [
            'proveedor_id' => $proveedor->id,
            'tipo'         => 'recarga',
            'monto'        => 5,
            'motivo'       => 'Recarga manual por validacion QA',
        ]);
    }

    public function test_usuario_no_admin_no_puede_recargar_creditos(): void
    {
        $cliente = $this->crearUsuario('cliente');
        $proveedor = $this->crearProveedor(saldo: 1);

        Sanctum::actingAs($cliente);

        $this->postJson("/api/admin/proveedores/{$proveedor->id}/creditos", [
            'monto'  => 4,
            'motivo' => 'Intento sin permisos',
        ])->assertForbidden();

        $this->assertDatabaseHas('creditos_proveedor', [
            'proveedor_id' => $proveedor->id,
            'saldo'        => 1,
        ]);
        $this->assertDatabaseMissing('transacciones_credito', [
            'proveedor_id' => $proveedor->id,
            'motivo'       => 'Intento sin permisos',
        ]);
    }

    public function test_recarga_crea_saldo_si_el_proveedor_no_tenia_registro_creditos(): void
    {
        $admin = $this->crearUsuario('admin');
        $proveedor = $this->crearProveedor(crearCredito: false);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/proveedores/{$proveedor->id}/creditos", [
            'monto'  => 3,
            'motivo' => 'Alta inicial de creditos',
        ])->assertOk()
            ->assertJsonPath('credito.saldo', 3);

        $this->assertDatabaseHas('creditos_proveedor', [
            'proveedor_id' => $proveedor->id,
            'saldo'        => 3,
        ]);
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
            'nombre'      => 'Categoria recarga ' . uniqid(),
            'descripcion' => 'Categoria para pruebas de recarga',
        ]);

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion'  => 'Proveedor para pruebas de recarga manual',
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
