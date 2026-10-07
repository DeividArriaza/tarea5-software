# Tarea 5 — Pruebas automatizadas de ServiGT

Ingeniería de Software 2 · Universidad del Valle de Guatemala · Semestre II, 2026.

Este repositorio reúne el informe, el video sin voz, seis pruebas automatizadas
sobre funcionalidades reales de ServiGT y su proceso de integración continua.

## Entregables

- [Informe PDF](entregables/Informe-Tarea-5.pdf): investigación, comparación de herramientas, diseño de pruebas, resultados y conclusiones.
- [Video sin voz](entregables/Video-Tarea-5-sin-voz.mp4): aproximadamente 7 minutos y 35 segundos, con resultados locales reales.
- [Pruebas implementadas](ServiGT/backend/tests/Tarea5/Tarea5Test.php).
- [Workflow de GitHub Actions](.github/workflows/tarea5.yml).
- [Evidencia local](docs/evidencia-local/): logs, JUnit XML y TestDox HTML.

El informe todavía requiere completar nombres y carnés. El video presenta
la evidencia local; la demostración de la ejecución remota de Actions debe
complementarse antes de entregar como evidencia completa de CI.

## Casos de prueba

| Caso | Comportamiento verificado |
|---|---|
| I1 | Crear un pedido persiste cliente/categoría y devuelve el recurso JSON correcto. |
| I2 | Una compra simulada registra compra, saldo e historial coherentes. |
| I3 | Leer una notificación actualiza la persistencia y el contador. |
| R1 | El pedido conserva la expiración exacta de siete días. |
| R2 | Un reintento de compra no duplica créditos ni movimientos. |
| R3 | Un usuario ajeno no puede leer ni modificar la notificación de otra persona. |

Se utiliza el kernel HTTP real de Laravel y PostgreSQL. Sanctum establece el
actor autenticado del test; estos casos no prueban tokens reales, la interfaz
Expo ni concurrencia entre conexiones.

## Ejecutar localmente

Requisitos: Docker con Compose y Python 3. Desde la raíz:

```bash
python3 scripts/tarea5.py --mode suite
python3 scripts/tarea5.py --mode demo
```

`demo` verifica la secuencia **6 aprobadas → únicamente R1 fallida → 6 aprobadas**.
Introduce temporalmente una expiración de un día en el controlador, comprueba
el fallo específico y restaura el código original. Usar una copia de trabajo y
no ejecutar demostraciones simultáneamente en el mismo directorio.

PostgreSQL 16 vive en un servicio temporal exclusivo, inicializado con
`ServiGT/database/init.sql`. Cada test revierte sus fixtures mediante
`DatabaseTransactions`. La limpieza se limita a este entorno de pruebas.

Los reportes de cada ejecución se generan en `evidencia/tarea5/`. Guardarlos en
otra carpeta antes de repetir un comando si se desea conservar ambos resultados.

## Resultados locales comprobados

PHP 8.3.32 · PHPUnit 12.5.34 · PostgreSQL 16.

| Etapa | Resultado | Duración del comando |
|---|---|---|
| Línea base | 6 pruebas aprobadas, 33 assertions | 3.409 s |
| Mutación | 6 ejecutadas; únicamente R1 falla | 1.600 s |
| Corrección | 6 pruebas aprobadas, 33 assertions | 1.603 s |

El controlador quedó restaurado. También aprobaron 20 pruebas existentes de
pedidos, compras y notificaciones, con 67 assertions. Los reportes están en
`docs/evidencia-local/`; sus fechas están expresadas en UTC.

## Integración continua

El workflow ejecuta la suite con **push** o **pull request** a `main` o `dev`.
Construye el backend, prepara PostgreSQL y conserva logs/reportes como artifacts
incluso en fallos, con retención configurada de 30 días.

La ejecución manual permite:

- `suite`: ejecución normal; debe aprobar.
- `demo`: experimento completo de éxito, fallo y corrección; debe verificar las tres fases.
- `mutant`: provoca la regresión y devuelve código 1 para mostrar un job rojo deliberado.

Para documentar tres ejecuciones remotas, ejecutar `suite`, `mutant` y `suite`,
y conservar los enlaces y artifacts. El fallo rojo debe deberse a R1 y no a la
preparación del entorno.

## Procedencia del código

Se incluye el backend y el esquema SQL necesarios para que este repositorio
pueda ejecutar las pruebas sin depender de otra carpeta local. Se tomaron de
ServiGT, repositorio `SrCharlied/G6Software-PServicios`, commit
`7ea97d48f621e3e350e10125530132cba35a2b7d`, y se añadieron las pruebas de Tarea 5,
el XML dedicado, el script y el workflow. No se incluye el frontend porque
los casos seleccionados verifican la integración del backend con persistencia.
