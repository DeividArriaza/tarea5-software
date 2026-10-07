<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class SecureErrorAndHealthTest extends TestCase
{
    public function test_error_500_no_expone_detalles_y_comparte_correlation_id(): void
    {
        Route::get('/api/__test/error-seguro', function () {
            throw new \RuntimeException('SQLSTATE[08006] host=db password=secret-token stack trace');
        });

        Log::spy();

        $response = $this->withHeader('X-Correlation-ID', 'test-correlation-123')
            ->getJson('/api/__test/error-seguro')
            ->assertStatus(500)
            ->assertHeader('X-Correlation-ID', 'test-correlation-123')
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Error interno del servidor.')
            ->assertJsonPath('correlation_id', 'test-correlation-123');

        $payload = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $payload);
        $this->assertStringNotContainsString('password', $payload);
        $this->assertStringNotContainsString('stack trace', $payload);

        Log::shouldHaveReceived('error')
            ->with('api.exception', Mockery::on(fn (array $context) => $context['correlation_id'] === 'test-correlation-123'
                && $context['exception'] === \RuntimeException::class
                && $context['status'] === 500
                && !array_key_exists('password', $context)
                && !array_key_exists('token', $context)))
            ->once();
    }

    public function test_health_no_concatena_mensaje_de_excepcion_de_base_de_datos(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->andThrow(new \RuntimeException('SQLSTATE host=db password=secret'));

        $response = $this->withHeader('X-Correlation-ID', 'health-correlation-1')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('X-Correlation-ID', 'health-correlation-1')
            ->assertJsonPath('database.status', 'unavailable')
            ->assertJsonPath('correlation_id', 'health-correlation-1');

        $payload = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $payload);
        $this->assertStringNotContainsString('password', $payload);
        $this->assertStringNotContainsString('host=db', $payload);
    }
}
