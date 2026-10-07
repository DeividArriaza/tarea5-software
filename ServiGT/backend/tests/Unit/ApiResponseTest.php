<?php

namespace Tests\Unit;

use App\Traits\ApiResponse;
use Tests\TestCase;

/**
 * Pruebas unitarias del trait ApiResponse.
 *
 * No tocan la base de datos: solo verifican la forma del JSON y el codigo
 * de estado que producen los helpers success() / error().
 */
class ApiResponseTest extends TestCase
{
    /** Objeto anonimo que expone los metodos protegidos del trait. */
    private function sut(): object
    {
        return new class {
            use ApiResponse;

            public function ok(string $m, mixed $d = null, int $s = 200)
            {
                return $this->success($m, $d, $s);
            }

            public function fail(string $m, int $s = 400, array $e = [])
            {
                return $this->error($m, $s, $e);
            }
        };
    }

    public function test_success_devuelve_estructura_y_estado_por_defecto(): void
    {
        $res  = $this->sut()->ok('Todo bien');
        $body = $res->getData(true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertTrue($body['success']);
        $this->assertSame('Todo bien', $body['message']);
    }

    public function test_success_fusiona_datos_asociativos_en_la_raiz(): void
    {
        $res  = $this->sut()->ok('Creado', ['pedido' => ['id' => 7]], 201);
        $body = $res->getData(true);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame(7, $body['pedido']['id']);
        $this->assertArrayNotHasKey('data', $body);
    }

    public function test_success_envuelve_datos_no_asociativos_bajo_data(): void
    {
        $body = $this->sut()->ok('Lista', 'valor-simple')->getData(true);

        $this->assertSame('valor-simple', $body['data']);
    }

    public function test_error_devuelve_success_false_y_estado(): void
    {
        $res  = $this->sut()->fail('Algo fallo', 422, ['campo' => ['requerido']]);
        $body = $res->getData(true);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertSame('Algo fallo', $body['message']);
        $this->assertSame(['campo' => ['requerido']], $body['errors']);
    }

    public function test_error_omite_clave_errors_cuando_esta_vacia(): void
    {
        $body = $this->sut()->fail('Sin detalles')->getData(true);

        $this->assertArrayNotHasKey('errors', $body);
    }
}
