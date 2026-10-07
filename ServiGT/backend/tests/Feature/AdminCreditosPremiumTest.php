<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CompraCredito;
use App\Models\CreditoProveedor;
use App\Models\PaqueteCredito;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/admin/creditos-premium
 */
class AdminCreditosPremiumTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_ve_compras_kpis_y_estado_premium(): void
    {
        $admin = $this->crearUsuario('admin');
        $proveedor = $this->crearProveedor(saldo: 12);
        $proveedor->premium_inicio_at = now()->subDays(5);
        $proveedor->premium_vence_at = now()->addDays(25);
        $proveedor->premium_ciclo_key = 'premium-test-admin';
        $proveedor->premium_renovaciones = 1;
        $proveedor->save();

        $paquete = PaqueteCredito::where('nombre', 'Inicial')->first();

        CompraCredito::create([
            'proveedor_id'       => $proveedor->id,
            'paquete_id'         => $paquete->id,
            'monto_gtq'          => $paquete->precio_gtq,
            'creditos_otorgados' => 8,
            'estado'             => 'completada',
            'referencia'         => 'SGT-90001',
            'idempotency_key'    => 'admin-test-' . uniqid(),
            'completada_at'      => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/creditos-premium')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('kpis.premium_activos', 1)
            ->assertJsonPath('compras.0.referencia', 'SGT-90001')
            ->assertJsonPath('compras.0.estado', 'completada')
            ->assertJsonPath('proveedores.0.premium.estado', 'activo');
    }

    public function test_cliente_no_puede_ver_creditos_premium_admin(): void
    {
        Sanctum::actingAs($this->crearUsuario('cliente'));

        $this->getJson('/api/admin/creditos-premium')->assertForbidden();
    }

    public function test_usuario_no_autenticado_recibe_401(): void
    {
        $this->getJson('/api/admin/creditos-premium')->assertUnauthorized();
    }

    /**
     * La superficie administrativa filtra por los cuatro estados de compra.
     * Los tres no completados solo pueden llegar a existir por via
     * administrativa o por un pago real futuro, asi que esta es la unica
     * cobertura que tienen: sin ella, un cambio en el CHECK o en el filtro
     * pasaria inadvertido.
     */
    public function test_el_filtro_por_estado_distingue_pendiente_fallida_y_cancelada(): void
    {
        $admin = $this->crearUsuario('admin');
        $proveedor = $this->crearProveedor(saldo: 0);
        $paquete = PaqueteCredito::where('nombre', 'Inicial')->first();

        $referencias = [];

        foreach (['pendiente', 'completada', 'fallida', 'cancelada'] as $estado) {
            $referencia = 'SGT-' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $referencias[$estado] = $referencia;

            CompraCredito::create([
                'proveedor_id'       => $proveedor->id,
                'paquete_id'         => $paquete->id,
                'monto_gtq'          => $paquete->precio_gtq,
                'creditos_otorgados' => 8,
                'estado'             => $estado,
                'referencia'         => $referencia,
                'idempotency_key'    => "admin-{$estado}-" . uniqid(),
                'completada_at'      => $estado === 'completada' ? now() : null,
            ]);
        }

        Sanctum::actingAs($admin);

        foreach ($referencias as $estado => $referencia) {
            $response = $this->getJson("/api/admin/creditos-premium?estado={$estado}")
                ->assertOk()
                ->assertJsonPath('filtro_estado', $estado);

            $compras = collect($response->json('compras'))
                ->where('proveedor_id', $proveedor->id);

            $this->assertCount(1, $compras, "El filtro {$estado} debe devolver exactamente una compra del proveedor.");
            $this->assertEquals($referencia, $compras->first()['referencia']);
        }

        // Sin filtro, las cuatro conviven en la misma superficie.
        Sanctum::actingAs($admin);
        $todas = collect($this->getJson('/api/admin/creditos-premium')->assertOk()->json('compras'))
            ->where('proveedor_id', $proveedor->id);

        $this->assertCount(4, $todas);
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
            'nombre'      => 'Categoria admin monetizacion ' . uniqid(),
            'descripcion' => 'Categoria para pruebas admin de creditos y Premium',
        ]);

        $proveedor = Proveedor::create([
            'user_id'      => $user->id,
            'nombre'       => $user->name,
            'email'        => $user->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion'  => 'Proveedor para pruebas admin de monetizacion',
        ]);

        CreditoProveedor::create([
            'proveedor_id' => $proveedor->id,
            'saldo'        => $saldo,
            'updated_at'   => now(),
        ]);

        return $proveedor->setRelation('user', $user);
    }
}
