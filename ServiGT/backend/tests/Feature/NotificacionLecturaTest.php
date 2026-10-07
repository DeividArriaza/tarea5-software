<?php

namespace Tests\Feature;

use App\Models\Notificacion;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lectura de notificaciones: PUT /api/notificaciones/{id}/leer y el contrato de
 * GET /api/notificaciones.
 *
 * NotificationBell llama a estos dos endpoints en cada toque, y navega leyendo
 * las llaves de `datos`. Esta suite fija ese contrato para que un cambio en el
 * backend no rompa la navegacion en silencio.
 */
class NotificacionLecturaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_destinatario_marca_su_notificacion_como_leida(): void
    {
        $user  = $this->crearUsuario('proveedor');
        $notif = $this->crearNotificacion($user, 'nueva_solicitud', ['servicio_id' => 41]);

        Sanctum::actingAs($user);

        $this->putJson("/api/notificaciones/{$notif->id}/leer")->assertOk();

        $this->assertDatabaseHas('notificaciones', [
            'id'    => $notif->id,
            'leida' => true,
        ]);

        $this->getJson('/api/notificaciones')
            ->assertOk()
            ->assertJsonPath('no_leidas', 0);
    }

    public function test_otro_usuario_no_puede_marcar_una_notificacion_ajena(): void
    {
        $dueno  = $this->crearUsuario('proveedor');
        $ajeno  = $this->crearUsuario('proveedor');
        $notif  = $this->crearNotificacion($dueno, 'servicio_completado', ['servicio_id' => 9]);

        Sanctum::actingAs($ajeno);

        $this->putJson("/api/notificaciones/{$notif->id}/leer")->assertNotFound();

        // Sigue sin leer: un 404 no debe tener efecto lateral.
        $this->assertDatabaseHas('notificaciones', [
            'id'    => $notif->id,
            'leida' => false,
        ]);
    }

    public function test_marcar_leida_requiere_autenticacion(): void
    {
        $user  = $this->crearUsuario('cliente');
        $notif = $this->crearNotificacion($user, 'servicio_iniciado', ['servicio_id' => 3]);

        $this->putJson("/api/notificaciones/{$notif->id}/leer")->assertUnauthorized();

        $this->assertDatabaseHas('notificaciones', [
            'id'    => $notif->id,
            'leida' => false,
        ]);
    }

    public function test_marcar_todas_leidas_no_toca_las_de_otro_usuario(): void
    {
        $propio = $this->crearUsuario('cliente');
        $ajeno  = $this->crearUsuario('cliente');

        $mia    = $this->crearNotificacion($propio, 'servicio_calificable', ['servicio_id' => 5]);
        $suya   = $this->crearNotificacion($ajeno, 'servicio_calificable', ['servicio_id' => 6]);

        Sanctum::actingAs($propio);

        $this->putJson('/api/notificaciones/leer-todas')->assertOk();

        $this->assertDatabaseHas('notificaciones', ['id' => $mia->id,  'leida' => true]);
        $this->assertDatabaseHas('notificaciones', ['id' => $suya->id, 'leida' => false]);
    }

    /**
     * El frontend resuelve el destino con las llaves de `datos`. Si el cast del
     * modelo o los nombres cambian, la navegacion se rompe: este test lo fija.
     */
    public function test_index_devuelve_datos_como_objeto_con_las_llaves_del_contrato(): void
    {
        $user = $this->crearUsuario('proveedor');

        $this->crearNotificacion($user, 'cotizacion_aceptada', [
            'pedido_id'     => 12,
            'cotizacion_id' => 30,
            'servicio_id'   => 55,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/notificaciones')->assertOk();

        $notificacion = $response->json('notificaciones.0');

        $this->assertSame('cotizacion_aceptada', $notificacion['tipo']);
        $this->assertIsArray($notificacion['datos'], 'datos debe serializarse como objeto, no como string JSON');
        $this->assertSame(12, $notificacion['datos']['pedido_id']);
        $this->assertSame(30, $notificacion['datos']['cotizacion_id']);
        $this->assertSame(55, $notificacion['datos']['servicio_id']);
        $this->assertFalse($notificacion['leida']);
    }

    public function test_index_solo_devuelve_notificaciones_del_usuario_autenticado(): void
    {
        $propio = $this->crearUsuario('proveedor');
        $ajeno  = $this->crearUsuario('proveedor');

        $this->crearNotificacion($propio, 'nueva_solicitud', ['servicio_id' => 1]);
        $this->crearNotificacion($ajeno, 'nueva_solicitud', ['servicio_id' => 2]);

        Sanctum::actingAs($propio);

        $response = $this->getJson('/api/notificaciones')->assertOk();

        $this->assertCount(1, $response->json('notificaciones'));
        $this->assertSame(1, $response->json('notificaciones.0.datos.servicio_id'));
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

    private function crearNotificacion(User $user, string $tipo, array $datos): Notificacion
    {
        return Notificacion::create([
            'destinatario_id' => $user->id,
            'tipo'            => $tipo,
            'titulo'          => 'Aviso de prueba',
            'mensaje'         => 'Mensaje de prueba para ' . $tipo,
            'datos'           => $datos,
            'leida'           => false,
        ]);
    }
}
