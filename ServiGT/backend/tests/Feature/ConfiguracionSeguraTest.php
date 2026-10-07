<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Task 4.1 — configuracion segura por ambiente.
 *
 * Las tres piezas de esta task que se pueden probar en PHPUnit son el archivo
 * de CORS, el hecho de que la configuracion se lea desde `config()` y no desde
 * `env()` disperso, y que la respuesta de la API no filtre configuracion. Las
 * otras dos (arranque sin secretos y ausencia de admin por defecto) viven en
 * `docker/entrypoint.sh` y `docker/sync_schema.php`, y su evidencia es el
 * arranque con volumen limpio documentado en la matriz OWASP.
 */
class ConfiguracionSeguraTest extends TestCase
{
    /**
     * Recarga `config/cors.php` con un entorno simulado. Se evalua el archivo
     * real —no una copia del criterio— para que la prueba falle si alguien
     * cambia la logica del archivo.
     *
     * @param  array<string, string>  $entorno
     * @return array<string, mixed>
     */
    private function cargarCors(array $entorno): array
    {
        $previos = [];

        foreach ($entorno as $clave => $valor) {
            $previos[$clave] = $_ENV[$clave] ?? null;
            $_ENV[$clave]    = $valor;
            $_SERVER[$clave] = $valor;
            putenv("{$clave}={$valor}");
        }

        try {
            return require base_path('config/cors.php');
        } finally {
            foreach ($previos as $clave => $valor) {
                if ($valor === null) {
                    unset($_ENV[$clave], $_SERVER[$clave]);
                    putenv($clave);
                    continue;
                }

                $_ENV[$clave]    = $valor;
                $_SERVER[$clave] = $valor;
                putenv("{$clave}={$valor}");
            }
        }
    }

    public function test_cors_no_usa_comodin_fuera_de_local_sin_origenes_declarados(): void
    {
        $cors = $this->cargarCors([
            'APP_ENV'              => 'production',
            'CORS_ALLOWED_ORIGINS' => '',
        ]);

        $this->assertSame([], $cors['allowed_origins']);
        $this->assertNotContains('*', $cors['allowed_origins']);
    }

    public function test_cors_respeta_la_lista_declarada_por_ambiente(): void
    {
        $cors = $this->cargarCors([
            'APP_ENV'              => 'production',
            'CORS_ALLOWED_ORIGINS' => 'https://www.servigtdev.com, http://localhost:8086',
        ]);

        $this->assertSame(
            ['https://www.servigtdev.com', 'http://localhost:8086'],
            $cors['allowed_origins']
        );
    }

    public function test_cors_conserva_el_comodin_solo_en_local(): void
    {
        $cors = $this->cargarCors([
            'APP_ENV'              => 'local',
            'CORS_ALLOWED_ORIGINS' => '',
        ]);

        $this->assertSame(['*'], $cors['allowed_origins']);
    }

    public function test_cors_no_habilita_credenciales_ni_metodos_comodin(): void
    {
        $cors = config('cors');

        // Con Bearer token no hay cookie que compartir; habilitar credenciales
        // obligaria ademas a abandonar el comodin de local.
        $this->assertFalse($cors['supports_credentials']);
        $this->assertNotContains('*', $cors['allowed_methods']);
        $this->assertSame(['api/*'], $cors['paths']);
    }

    public function test_la_configuracion_de_cors_se_lee_desde_config_y_no_esta_vacia(): void
    {
        // Si alguien borrara `config/cors.php`, HandleCors caeria a los
        // defaults del paquete (que si traen comodin) sin ningun error visible.
        $this->assertIsArray(config('cors'));
        $this->assertArrayHasKey('allowed_origins', config('cors'));
    }

    public function test_health_no_expone_configuracion_ni_credenciales(): void
    {
        $respuesta = $this->getJson('/api/health');

        $cuerpo = $respuesta->getContent();

        foreach (['APP_KEY', 'DB_PASSWORD', 'ADMIN_PASSWORD', 'base64:', 'password'] as $fragmento) {
            $this->assertStringNotContainsStringIgnoringCase($fragmento, $cuerpo);
        }
    }
}
