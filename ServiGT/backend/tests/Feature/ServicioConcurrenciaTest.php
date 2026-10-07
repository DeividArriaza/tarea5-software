<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Notificacion;
use App\Models\Proveedor;
use App\Models\Servicio;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Task 1.2 (bloque S8-01) — atomicidad de las transiciones de servicio.
 *
 * ── Por que este archivo no sigue la convencion de la suite ──────────────────
 *
 * El criterio de S8-01 exige conexiones independientes sobre PostgreSQL y
 * prohibe etiquetar como concurrencia una prueba secuencial. Eso obliga a NO
 * usar `DatabaseTransactions`: ese trait envuelve cada test en una transaccion
 * sin commit, y se midio en este entorno que una conexion independiente lee 0
 * filas dentro de ella. Con el trait, toda prueba de concurrencia seria teatro.
 * Aqui los fixtures se comitean y `tearDown` los borra de forma dirigida.
 *
 * ── Por que se inspecciona el SQL y no solo el codigo de respuesta ───────────
 *
 * Un primer intento midio si el endpoint completaba la transicion mientras una
 * transaccion rival mantenia la fila bloqueada. Esa prueba pasaba, pero por la
 * razon equivocada: `UPDATE` toma el lock de fila por si mismo, asi que la
 * peticion abortaba en la escritura aunque hubiera decidido sobre una lectura
 * sucia. Como toda escritura ocurre antes de cualquier efecto observable, el
 * codigo actual y el corregido son indistinguibles por respuesta o por estado
 * final. El unico discriminante real es COMO consulta el endpoint antes de
 * decidir, y eso se lee del log de consultas.
 *
 * ── Que se considera atomico ────────────────────────────────────────────────
 *
 * S8-01 admite "releer bajo lock y revalidar dentro de la transaccion, o una
 * alternativa equivalente demostrada", asi que la prueba no impone una unica
 * implementacion. Acepta dos formas seguras:
 *
 *   a) lectura con `FOR UPDATE` sobre `servicios` antes de decidir; o
 *   b) escritura condicionada al estado previo (`UPDATE ... WHERE estado = ?`),
 *      donde la propia base rechaza la carrera perdida.
 *
 * Lo que rechaza es el patron actual: leer sin lock, validar en PHP y escribir
 * sin condicion. Entre la lectura y la escritura cabe otra peticion.
 *
 * ── Estado esperado ─────────────────────────────────────────────────────────
 *
 * Los cuatro tests de transicion nacen EN ROJO a proposito: hoy
 * `ServicioController` no usa `lockForUpdate` en ninguna transicion (0 usos,
 * frente a 4 en CotizacionController y 2 en CreditoController). Pasan a verde
 * cuando D cierre 1.1. El grupo `concurrencia` permite excluirlos del CI
 * mientras tanto, si el equipo decide no integrarlos junto con 1.1.
 *
 * ── Contrato de `en_camino` (ratificado) ────────────────────────────────────
 *
 * Se resolvio la propuesta del plan (seccion 3.1) conservando el estado con una
 * salida valida:
 *
 *     aceptado ──/estado──> en_camino
 *        │                      │
 *        └──/iniciar(codigo)────┴──> en_progreso
 *
 * Es decir: `/estado` queda reducido a un unico destino, `en_camino`, y solo
 * desde `aceptado`; `/iniciar` valida el codigo desde `aceptado` o desde
 * `en_camino`; `completado` sigue siendo alcanzable unicamente por
 * `confirmar-fin`. Hoy `en_camino` es un callejon sin salida —lo produce solo
 * `/estado` y `/iniciar` exige `aceptado`—, asi que las dos pruebas de abajo
 * tambien nacen en rojo. No hay migracion: el valor ya esta en el CHECK del
 * esquema y no existe ninguna fila en ese estado.
 *
 * Que `/estado` rechace `completado` y `cancelado` es una regla de maquina de
 * estados, no de atomicidad: vive en las pruebas de 1.1, no aqui.
 *
 * ── Fuera de alcance en esta entrega ────────────────────────────────────────
 *
 * - `cancelar`: la ruta no existe todavia (task 2.1, D). La carrera
 *   cancelar/iniciar es el escenario central de 1.2 y se agrega al aterrizar.
 */
#[Group('concurrencia')]
class ServicioConcurrenciaTest extends TestCase
{
    /** @var list<int> ids de usuarios; proveedores y servicios caen por FK. */
    private array $usuariosCreados = [];

    /** @var list<int> */
    private array $categoriasCreadas = [];

    /** @var list<\PDO> */
    private array $rivales = [];

    protected function tearDown(): void
    {
        // Una transaccion rival abierta deja la fila bloqueada y la limpieza se
        // quedaria esperando. Se cierran antes de borrar.
        foreach ($this->rivales as $rival) {
            try {
                if ($rival->inTransaction()) {
                    $rival->rollBack();
                }
            } catch (\PDOException) {
                // Conexion ya caida: no hay lock que liberar.
            }
        }
        $this->rivales = [];

        if ($this->usuariosCreados) {
            // `notificaciones` no cae en cascada desde `users`; se borra aparte.
            Notificacion::whereIn('destinatario_id', $this->usuariosCreados)->delete();
            User::whereIn('id', $this->usuariosCreados)->delete();
        }
        if ($this->categoriasCreadas) {
            Categoria::whereIn('id', $this->categoriasCreadas)->delete();
        }

        $this->usuariosCreados   = [];
        $this->categoriasCreadas = [];

        parent::tearDown();
    }

    // ── Control del arnes ────────────────────────────────────────────────────

    /**
     * Sin este control, un fallo de los tests de abajo no distinguiria "el
     * endpoint no protege la transicion" de "el arnes nunca compitio".
     */
    public function test_el_arnes_usa_conexiones_realmente_independientes(): void
    {
        $servicio = $this->crearServicio('pendiente');
        $rival    = $this->conexionIndependiente();

        $st = $rival->prepare('SELECT count(*) FROM servicios WHERE id = :id');
        $st->execute(['id' => $servicio->id]);

        $this->assertSame(
            1,
            (int) $st->fetchColumn(),
            'La conexion independiente no ve el fixture comiteado: se reintrodujo DatabaseTransactions.'
        );

        // Compiten de verdad: el segundo lock sobre la misma fila debe negarse.
        $rival->beginTransaction();
        $rival->prepare('SELECT id FROM servicios WHERE id = :id FOR UPDATE')
            ->execute(['id' => $servicio->id]);

        $negado = false;
        try {
            DB::select('SELECT id FROM servicios WHERE id = ? FOR UPDATE NOWAIT', [$servicio->id]);
        } catch (\Throwable $e) {
            $negado = str_contains($e->getMessage(), '55P03')
                || str_contains(strtolower($e->getMessage()), 'lock');
        }

        $rival->rollBack();

        $this->assertTrue($negado, 'PostgreSQL debio negar el segundo lock sobre la misma fila.');
    }

    // ── Transiciones: la decision debe estar protegida ───────────────────────

    public function test_aceptar_protege_la_transicion_contra_una_peticion_simultanea(): void
    {
        $servicio = $this->crearServicio('pendiente');

        $traza = $this->trazarTransicion(function () use ($servicio) {
            Sanctum::actingAs($servicio->proveedor->user);
            return $this->postJson("/api/servicios/{$servicio->id}/aceptar");
        });

        $this->assertTransicionAtomica($traza, 'aceptar');
    }

    public function test_rechazar_protege_la_transicion_contra_una_peticion_simultanea(): void
    {
        $servicio = $this->crearServicio('pendiente');

        $traza = $this->trazarTransicion(function () use ($servicio) {
            Sanctum::actingAs($servicio->proveedor->user);
            return $this->postJson("/api/servicios/{$servicio->id}/rechazar");
        });

        $this->assertTransicionAtomica($traza, 'rechazar');
    }

    public function test_finalizar_protege_la_transicion_contra_una_peticion_simultanea(): void
    {
        $servicio = $this->crearServicio('en_progreso');

        $traza = $this->trazarTransicion(function () use ($servicio) {
            Sanctum::actingAs($servicio->proveedor->user);
            return $this->postJson("/api/servicios/{$servicio->id}/finalizar");
        });

        $this->assertTransicionAtomica($traza, 'finalizar');
    }

    public function test_confirmar_fin_protege_la_transicion_contra_una_peticion_simultanea(): void
    {
        $servicio = $this->crearServicio('por_confirmar', ['codigo_fin' => '654321']);

        $traza = $this->trazarTransicion(function () use ($servicio) {
            Sanctum::actingAs($servicio->cliente);
            return $this->postJson("/api/servicios/{$servicio->id}/confirmar-fin", ['codigo' => '654321']);
        });

        $this->assertTransicionAtomica($traza, 'confirmar-fin');
    }

    // ── Contrato ratificado de `en_camino` ───────────────────────────────────

    /**
     * Unica transicion que `/estado` conserva tras 1.1. Hoy responde 200 pero
     * sin proteger la fila, asi que dos peticiones simultaneas pueden moverla
     * desde origenes distintos.
     */
    public function test_marcar_en_camino_protege_la_transicion_contra_una_peticion_simultanea(): void
    {
        $servicio = $this->crearServicio('aceptado');

        $traza = $this->trazarTransicion(function () use ($servicio) {
            Sanctum::actingAs($servicio->proveedor->user);
            return $this->putJson("/api/servicios/{$servicio->id}/estado", ['estado' => 'en_camino']);
        });

        $this->assertTransicionAtomica($traza, 'marcar en camino');
    }

    /**
     * La salida del estado. Hoy `/iniciar` exige `aceptado`, asi que un
     * servicio en `en_camino` no puede arrancar y queda atrapado: esta prueba
     * falla primero en el codigo de respuesta y, una vez abierta la puerta,
     * seguira exigiendo que la transicion sea atomica.
     */
    public function test_iniciar_desde_en_camino_protege_la_transicion_contra_una_peticion_simultanea(): void
    {
        $servicio = $this->crearServicio('en_camino');

        $traza = $this->trazarTransicion(function () use ($servicio) {
            Sanctum::actingAs($servicio->proveedor->user);
            return $this->postJson("/api/servicios/{$servicio->id}/iniciar", ['codigo' => '123456']);
        });

        $this->assertTransicionAtomica($traza, 'iniciar desde en_camino');
    }

    // ── Efecto duplicado concreto ────────────────────────────────────────────

    /**
     * Caracteriza el dano que justifica el lock, sin pasar por el endpoint: dos
     * conexiones independientes repiten la secuencia leer -> validar ->
     * escribir que hoy usa `finalizar`. Ambas aprueban la misma precondicion
     * porque ninguna bloquea, ambas escriben, y el segundo `codigo_fin` pisa al
     * primero: el codigo que la primera peticion ya le mostro al proveedor deja
     * de servir para confirmar.
     *
     * No es un guard de regresion —no toca el controlador— y seguira pasando
     * despues de 1.1. Documenta por que la relectura protegida es obligatoria.
     */
    public function test_la_secuencia_sin_lock_permite_pisar_el_codigo_fin(): void
    {
        $servicio = $this->crearServicio('en_progreso');

        $a = $this->conexionIndependiente();
        $b = $this->conexionIndependiente();

        $a->beginTransaction();
        $b->beginTransaction();

        $this->assertSame('en_progreso', $this->estadoServicio($a, $servicio->id));
        $this->assertSame(
            'en_progreso',
            $this->estadoServicio($b, $servicio->id),
            'Sin lock, ambas transacciones aprueban la misma precondicion.'
        );

        $this->escribirCodigoFin($a, $servicio->id, '111111');
        $a->commit();

        $this->escribirCodigoFin($b, $servicio->id, '222222');
        $b->commit();

        $codigoFinal = Servicio::find($servicio->id)->codigo_fin;

        $this->assertSame(
            '222222',
            $codigoFinal,
            'El segundo escritor debio pisar al primero; si no, el fixture no reprodujo la carrera.'
        );
        $this->assertNotSame(
            '111111',
            $codigoFinal,
            'El codigo entregado por la primera peticion ya no sirve para confirmar.'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Ejecuta la peticion capturando el SQL real que emitio.
     *
     * `DB::enableQueryLog()` no sirve aqui: el arranque de la peticion de
     * prueba reinicia el log y devuelve cero consultas. El evento
     * `QueryExecuted` si sobrevive al ciclo del request.
     *
     * @return array{status:int, consultas:list<string>, transaccion:bool}
     */
    private function trazarTransicion(callable $accion): array
    {
        $consultas   = [];
        $transaccion = false;

        Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$consultas) {
            $consultas[] = $e->sql;
        });
        Event::listen(TransactionBeginning::class, function () use (&$transaccion) {
            $transaccion = true;
        });

        $respuesta = $accion();

        return [
            'status'      => $respuesta->status(),
            'consultas'   => $consultas,
            'transaccion' => $transaccion,
        ];
    }

    /**
     * @param array{status:int, consultas:list<string>, transaccion:bool} $traza
     */
    private function assertTransicionAtomica(array $traza, string $endpoint): void
    {
        $this->assertSame(
            200,
            $traza['status'],
            "{$endpoint} no completo la transicion (HTTP {$traza['status']}), asi que no hay nada "
            . 'cuya atomicidad medir. Un 422 aqui significa que la ruta todavia no admite este '
            . 'origen: es parte del contrato que cierra 1.1, no un fixture mal armado.'
        );

        $lecturaBajoLock      = false;
        $escrituraCondicional = false;

        foreach ($traza['consultas'] as $sql) {
            $normalizado = strtolower(preg_replace('/\s+/', ' ', $sql));

            if (str_contains($normalizado, 'servicios') && str_contains($normalizado, 'for update')) {
                $lecturaBajoLock = true;
            }

            // Escritura que delega la carrera a la base: el WHERE incluye el
            // estado esperado, asi que una peticion perdedora afecta 0 filas.
            if (str_starts_with($normalizado, 'update "servicios"')
                || str_starts_with($normalizado, 'update servicios')) {
                $where = strstr($normalizado, ' where ');
                if ($where !== false && str_contains($where, 'estado')) {
                    $escrituraCondicional = true;
                }
            }
        }

        $this->assertTrue(
            $lecturaBajoLock || $escrituraCondicional,
            "{$endpoint} decide sobre una lectura sin proteger y escribe sin condicionar al estado "
            . "previo: entre la lectura y la escritura cabe otra peticion, que puede duplicar "
            . "efectos o revivir un estado ya resuelto. Se esperaba una lectura FOR UPDATE sobre "
            . "`servicios` o un UPDATE condicionado al estado. SQL observado: "
            . $this->resumirConsultas($traza['consultas'])
        );

        $this->assertTrue(
            $traza['transaccion'],
            "{$endpoint} cambia el estado y escribe notificaciones fuera de una transaccion: "
            . 'un fallo intermedio deja el servicio movido sin avisar a la contraparte.'
        );
    }

    /** @param list<string> $consultas */
    private function resumirConsultas(array $consultas): string
    {
        $relevantes = array_filter(
            $consultas,
            fn (string $sql) => str_contains(strtolower($sql), 'servicios')
        );

        if (!$relevantes) {
            return '(ninguna consulta toco `servicios`)';
        }

        return implode(' | ', array_map(
            fn (string $sql) => substr(preg_replace('/\s+/', ' ', $sql), 0, 90),
            $relevantes
        ));
    }

    private function conexionIndependiente(): \PDO
    {
        $c = config('database.connections.pgsql');

        $pdo = new \PDO(
            "pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}",
            $c['username'],
            $c['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        // Evita que la rival cuelgue la suite si la fila ya esta tomada.
        $pdo->exec("SET lock_timeout = '1500ms'");

        $this->rivales[] = $pdo;

        return $pdo;
    }

    private function estadoServicio(\PDO $pdo, int $id): string
    {
        $st = $pdo->prepare('SELECT estado FROM servicios WHERE id = :id');
        $st->execute(['id' => $id]);

        return (string) $st->fetchColumn();
    }

    private function escribirCodigoFin(\PDO $pdo, int $id, string $codigo): void
    {
        $pdo->prepare(
            "UPDATE servicios SET codigo_fin = :codigo, estado = 'por_confirmar' WHERE id = :id"
        )->execute(['codigo' => $codigo, 'id' => $id]);
    }

    /**
     * Fixture comiteado. Replica la forma de `MatrizAutorizacionTest`, pero sin
     * apoyarse en el rollback del trait porque aqui no hay ninguno.
     */
    private function crearServicio(string $estado, array $extra = []): Servicio
    {
        $categoria = Categoria::factory()->create();
        $this->categoriasCreadas[] = $categoria->id;

        $userProveedor = User::factory()->proveedor()->create();
        $cliente       = User::factory()->cliente()->create();
        $this->usuariosCreados[] = $userProveedor->id;
        $this->usuariosCreados[] = $cliente->id;

        $proveedor = Proveedor::create([
            'user_id'      => $userProveedor->id,
            'nombre'       => $userProveedor->name,
            'email'        => $userProveedor->email,
            'departamento' => 'Guatemala',
            'municipio'    => 'Guatemala',
            'categoria_id' => $categoria->id,
            'descripcion'  => 'Proveedor de apoyo para pruebas de concurrencia.',
        ]);

        $servicio = Servicio::create(array_merge([
            'cliente_id'    => $cliente->id,
            'proveedor_id'  => $proveedor->id,
            'descripcion'   => 'Servicio de apoyo para pruebas de concurrencia',
            'estado'        => $estado,
            'codigo_inicio' => '123456',
        ], $extra));

        return $servicio
            ->setRelation('proveedor', $proveedor->setRelation('user', $userProveedor))
            ->setRelation('cliente', $cliente);
    }
}
