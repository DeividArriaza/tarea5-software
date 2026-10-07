# Evidencia de GitHub Actions

Commit probado: `aced080c5aa038d4e2861398a5da6ac05dd93a74`.

- [Suite automática por push a main](https://github.com/DeividArriaza/tarea5-software/actions/runs/37561473072): job aprobado; seis pruebas aprobadas.
- [Demostración remota completa](https://github.com/DeividArriaza/tarea5-software/actions/runs/37561479579): job aprobado al verificar las tres fases.

| Fase | Pruebas | Fallos | Código de salida | Duración del comando |
|---|---|---|---|---|
| 01-exitosa | 6 | 0 | 0 | 8.062 s |
| 02-regresion | 6 | 1 | 1 | 1.244 s |
| 03-corregida | 6 | 0 | 0 | 1.226 s |

El único fallo de la fase 02 corresponde a R1: la expiración cambió temporalmente
de siete a un día. El controlador se restauró y las seis pruebas volvieron a
aprobar. El job `demo` termina aprobado porque confirma esa secuencia esperada;
la salida 1 de PHPUnit en la fase de regresión se conserva en los reportes.

Estos archivos se descargaron de los artifacts reales de GitHub Actions.
Las carpetas `suite/` y `demo/` conservan logs, XML JUnit, TestDox HTML y resumen.
Los enlaces de las ejecuciones permiten revisar preparación, pasos y artifacts.
