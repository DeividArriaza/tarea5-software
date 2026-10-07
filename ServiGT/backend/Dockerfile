FROM php:8.3-cli

# Dependencias del sistema
RUN apt-get update && apt-get install -y \
    libpq-dev zip unzip git curl libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Build determinista (task 1.4).
#
# Antes esta imagen corria `composer create-project laravel/laravel` seguido de
# `composer require laravel/sanctum`, asi que cada build resolvia la ultima
# version publicada en ese instante: dos builds del mismo commit podian traer
# frameworks distintos y no habia forma de auditar que se instalo.
#
# Ahora el manifiesto y el lockfile estan versionados en el repositorio y el
# esqueleto de Laravel (artisan, public/, config/, bootstrap/providers.php,
# routes/web.php, storage/) tambien, asi que `composer install` reproduce
# exactamente las mismas versiones en cualquier build.
#
# El install se hace ANTES de copiar el codigo de la aplicacion para que la
# capa de dependencias se cachee entre builds; los scripts y el autoloader se
# ejecutan al final, cuando ya estan presentes app/, bootstrap/ y config/.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist \
    --no-scripts --no-autoloader

# Esqueleto de Laravel versionado en el repositorio.
#
# `.env` se copia desde `.env.example` (sin APP_KEY ni credenciales) solo para
# que Dotenv encuentre el archivo: en el servicio `backend` el entrypoint lo
# reescribe con las variables de entorno reales, y en `backend_test` las
# variables de Compose ganan porque Dotenv no sobreescribe el entorno del
# proceso. Sin este archivo la suite emite un warning de `file_get_contents`
# en cada test.
COPY .env.example /app/.env.example
RUN cp /app/.env.example /app/.env
COPY artisan /app/artisan
COPY public/ /app/public/
COPY resources/ /app/resources/
COPY storage/ /app/storage/

# Archivos personalizados de ServiGT
COPY routes/ /app/routes/
COPY app/ /app/app/
COPY config/ /app/config/
COPY bootstrap/ /app/bootstrap/
COPY lang/ /app/lang/
COPY docker/entrypoint.sh /app/docker/entrypoint.sh
COPY docker/sync_schema.php /app/docker/sync_schema.php

# Infraestructura de pruebas automatizadas (PHPUnit)
# migraciones y factories solo se usan en el entorno de testing (SQLite en memoria);
# el esquema de produccion lo crea database/init.sql en PostgreSQL.
COPY database/migrations/ /app/database/migrations/
COPY database/factories/ /app/database/factories/
COPY database/seeders/ /app/database/seeders/
COPY tests/ /app/tests/
COPY phpunit.xml /app/phpunit.xml

# Autoloader optimizado y descubrimiento de paquetes con el codigo ya en su sitio
RUN composer dump-autoload --optimize --no-interaction \
    && mkdir -p /app/bootstrap/cache /app/storage/framework/cache/data \
       /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs \
    && chmod -R a+rwX /app/storage /app/bootstrap/cache

RUN sed -i 's/\r$//' /app/docker/entrypoint.sh \
    && chmod +x /app/docker/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/app/docker/entrypoint.sh"]
