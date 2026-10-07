#!/usr/bin/env python3
"""Ejecuta pruebas reales y conserva evidencia; demo exige verde-rojo-verde."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import time
from datetime import datetime, timezone
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'evidencia/tarea5'
CONTROLLER = ROOT / 'ServiGT/backend/app/Http/Controllers/PedidoController.php'
NORMAL = "'fecha_expiracion' => now()->addDays(7),"
MUTANT = "'fecha_expiracion' => now()->addDays(1),"
COMPOSE = ['docker', 'compose', '-p', 'servigt-tarea5', '-f', str(ROOT / 'compose.tarea5.yml')]


def command(args, logfile):
    start = time.monotonic()
    with logfile.open('w') as log:
        proc = subprocess.Popen(args, cwd=ROOT, stdout=subprocess.PIPE,
                                stderr=subprocess.STDOUT, text=True)
        for line in proc.stdout:
            print(line, end='', flush=True)
            log.write(line)
        code = proc.wait()
    return code, round(time.monotonic() - start, 3)


def run_stage(name, expected_failure=False):
    junit = OUT / f'{name}.xml'
    junit.unlink(missing_ok=True)  # Nunca aceptar un reporte de otra ejecucion.
    code, seconds = command(COMPOSE + ['run', '--rm', 'pruebas',
        '--configuration', '/app/tests/Tarea5/phpunit.xml', '--testdox',
        '--log-junit', f'/evidencia/{name}.xml',
        '--testdox-html', f'/evidencia/{name}.html'], OUT / f'{name}.log')
    cases = []
    if junit.exists():
        cases = ET.parse(junit).getroot().findall('.//testcase')
    failures = [case for case in cases if case.find('failure') is not None]
    errors = [case for case in cases if case.find('error') is not None]
    skipped = [case for case in cases if case.find('skipped') is not None]
    if expected_failure:
        valid = (code == 1 and len(cases) == 6 and len(failures) == 1 and
                 not errors and not skipped and
                 failures[0].get('name') == 'test_R1_pedido_conserva_expiracion_exacta_de_siete_dias' and
                 'exactamente siete dias' in ''.join(failures[0].find('failure').itertext()))
    else:
        valid = code == 0 and len(cases) == 6 and not failures and not errors and not skipped
    result = dict(etapa=name, utc=datetime.now(timezone.utc).isoformat(),
                  exit_code=code, segundos=seconds, pruebas=len(cases),
                  fallos=len(failures), errores=len(errors), omitidas=len(skipped),
                  resultado_esperado_verificado=valid)
    print(json.dumps(result, ensure_ascii=False), flush=True)
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--mode', choices=['suite', 'demo', 'mutant'], default='suite')
    args = parser.parse_args()
    OUT.mkdir(parents=True, exist_ok=True)
    original = CONTROLLER.read_bytes()
    records = []
    summary = dict(modo=args.mode, estado='iniciado', etapas=records,
                   commit_ci=os.environ.get('GITHUB_SHA'),
                   controlador_sha256=hashlib.sha256(original).hexdigest())
    touched = False
    exit_code = 2
    try:
        # Validar infraestructura antes de introducir el cambio deliberado.
        code, _ = command(['docker', 'info'], OUT / 'docker.log')
        if code:
            raise RuntimeError('No se puede acceder a Docker; no se ejecutaron pruebas.')
        code, _ = command(COMPOSE + ['build', 'pruebas'], OUT / 'build.log')
        if code:
            raise RuntimeError('Fallo al construir el entorno de pruebas.')
        if args.mode != 'mutant':
            stage = run_stage('01-exitosa')
            records.append(stage)
            if not stage['resultado_esperado_verificado']:
                raise RuntimeError('La linea base debe aprobar las seis pruebas.')
        if args.mode in ('demo', 'mutant'):
            text = original.decode()
            if text.count(NORMAL) != 1:
                raise RuntimeError('El punto de mutacion cambio; revisar antes de ejecutar.')
            touched = True
            CONTROLLER.write_bytes(text.replace(NORMAL, MUTANT).encode())
            stage = run_stage('02-regresion', expected_failure=True)
            records.append(stage)
            if not stage['resultado_esperado_verificado']:
                raise RuntimeError('No se detecto exclusivamente la regresion de expiracion.')
            CONTROLLER.write_bytes(original)
            touched = False
            if args.mode == 'demo':
                stage = run_stage('03-corregida')
                records.append(stage)
                if not stage['resultado_esperado_verificado']:
                    raise RuntimeError('La correccion debe recuperar las seis pruebas.')
        summary['estado'] = 'regresion_detectada' if args.mode == 'mutant' else 'verificado'
        # mutant reproduce un job rojo de CI, aunque el fallo haya sido esperado.
        exit_code = 1 if args.mode == 'mutant' else 0
    except (OSError, RuntimeError, ET.ParseError) as exc:
        summary['estado'] = 'bloqueado_o_fallido'
        summary['detalle'] = str(exc)
        print(str(exc), file=sys.stderr)
    finally:
        if touched:
            CONTROLLER.write_bytes(original)
        summary['controlador_restaurado'] = CONTROLLER.read_bytes() == original
        (OUT / 'resumen.json').write_text(json.dumps(summary, ensure_ascii=False, indent=2) + '\n')
        try:
            # Solo hay servicios efimeros en este archivo Compose independiente.
            command(COMPOSE + ['down', '--remove-orphans'], OUT / 'cleanup.log')
        except OSError:
            pass
    return exit_code


if __name__ == '__main__':
    sys.exit(main())
