<?php

namespace Tests\Unit;

use App\Http\Requests\StorePedidoRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Pruebas unitarias de las reglas de validacion de StorePedidoRequest.
 *
 * Se validan las reglas que NO dependen de la base de datos (min/max de
 * descripcion, valores permitidos de urgencia, campos requeridos). La regla
 * `exists:categorias` se excluye para mantener la prueba aislada.
 */
class StorePedidoRequestTest extends TestCase
{
    /** Reglas sin la validacion `exists` (que requeriria base de datos). */
    private function rulesSinExists(): array
    {
        $rules = (new StorePedidoRequest())->rules();
        $rules['categoria_id'] = ['required', 'integer'];
        return $rules;
    }

    private function validar(array $data): \Illuminate\Validation\Validator
    {
        return Validator::make($data, $this->rulesSinExists());
    }

    private function payloadValido(array $overrides = []): array
    {
        return array_merge([
            'descripcion'  => 'Necesito un electricista para revisar el tablero',
            'categoria_id' => 1,
            'direccion'    => 'Zona 10, Ciudad de Guatemala',
            'urgencia'     => 'alta',
        ], $overrides);
    }

    public function test_payload_valido_pasa_la_validacion(): void
    {
        $this->assertTrue($this->validar($this->payloadValido())->passes());
    }

    public function test_descripcion_corta_falla(): void
    {
        $v = $this->validar($this->payloadValido(['descripcion' => 'Corto']));

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('descripcion', $v->errors()->toArray());
    }

    public function test_urgencia_fuera_del_catalogo_falla(): void
    {
        $v = $this->validar($this->payloadValido(['urgencia' => 'extrema']));

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('urgencia', $v->errors()->toArray());
    }

    public function test_campos_requeridos_faltantes_fallan(): void
    {
        $v = $this->validar([]);

        $errores = $v->errors()->toArray();
        $this->assertArrayHasKey('descripcion', $errores);
        $this->assertArrayHasKey('categoria_id', $errores);
        $this->assertArrayHasKey('direccion', $errores);
        $this->assertArrayHasKey('urgencia', $errores);
    }

    public function test_mensaje_personalizado_de_urgencia(): void
    {
        $mensajes = (new StorePedidoRequest())->messages();

        $this->assertSame(
            'La urgencia debe ser baja, media o alta.',
            $mensajes['urgencia.in']
        );
    }
}
