#!/bin/bash
set -e

cd /app

echo "================================================"
echo "  ServiGT - Backend Laravel"
echo "================================================"

# ── Verificacion de configuracion (task 4.1) ───────────────────────────────
#
# Antes docker-compose.yml traia APP_KEY y DB_PASSWORD escritos en el archivo y
# el arranque funcionaba con ellos aunque nadie definiera nada. Eso significaba
# que el mismo secreto comiteado servia en la maquina de cualquiera y en
# cualquier servidor que corriera ese compose. Ahora esas variables no tienen
# valor por defecto: si faltan, el contenedor falla aqui con un mensaje claro
# en vez de arrancar con una credencial publica.
#
# Ninguna de estas comprobaciones imprime el valor de la variable.
faltantes=()
for var in APP_KEY DB_PASSWORD ADMIN_PASSWORD; do
  if [ -z "${!var}" ]; then
    faltantes+=("$var")
  fi
done

if [ ${#faltantes[@]} -gt 0 ]; then
  echo "[!] Faltan variables de entorno obligatorias: ${faltantes[*]}" >&2
  echo "    Copia ServiGT/.env.example a ServiGT/.env y completalo." >&2
  echo "    APP_KEY se genera con: docker compose run --rm --entrypoint 'php artisan key:generate --show' backend" >&2
  exit 1
fi

# Generar .env desde variables de entorno de Docker
echo "[*] Configurando variables de entorno..."
cat > /app/.env <<EOF
APP_NAME=${APP_NAME:-ServiGT}
APP_ENV=${APP_ENV:-production}
APP_KEY=${APP_KEY}
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=${APP_URL:-http://localhost:8000}
APP_LOCALE=${APP_LOCALE:-es}
APP_FALLBACK_LOCALE=${APP_FALLBACK_LOCALE:-es}
DB_CONNECTION=${DB_CONNECTION:-pgsql}
DB_HOST=${DB_HOST:-db}
DB_PORT=${DB_PORT:-5432}
DB_DATABASE=${DB_DATABASE:-servigt}
DB_USERNAME=${DB_USERNAME:-servigt_user}
DB_PASSWORD=${DB_PASSWORD}
CORS_ALLOWED_ORIGINS=${CORS_ALLOWED_ORIGINS:-}
CACHE_STORE=file
SESSION_DRIVER=file
FILESYSTEM_DISK=public
EOF
chmod 600 /app/.env

echo "[*] Limpiando cache..."
php artisan config:clear

# Se lee la configuracion ya resuelta (no la variable cruda) para dejar
# constancia de que `config()` refleja lo que se espera despues del clear.
# APP_KEY, DB_PASSWORD y ADMIN_PASSWORD nunca se imprimen.
echo "[*] Configuracion efectiva:"
php -r '
  require "/app/vendor/autoload.php";
  $app = require "/app/bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $origins = config("cors.allowed_origins");
  printf("    app.env=%s  app.debug=%s  cors.allowed_origins=%s\n",
    config("app.env"),
    config("app.debug") ? "true" : "false",
    $origins === [] ? "(ninguno)" : implode(",", $origins)
  );
'

echo "[*] Sincronizando esquema de base de datos..."
php /app/docker/sync_schema.php

echo "[*] Configurando almacenamiento de archivos..."
php artisan storage:link --force 2>/dev/null || true
chmod -R 775 /app/storage /app/bootstrap/cache 2>/dev/null || true

echo "================================================"
echo "  Backend listo en http://localhost:8000"
echo "  API disponible en http://localhost:8000/api"
echo "================================================"

# Iniciar servidor
php artisan serve --host=0.0.0.0 --port=8000
