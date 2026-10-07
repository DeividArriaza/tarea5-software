<?php

/**
 * Sincroniza el esquema PostgreSQL en cada arranque del backend.
 *
 * El DDL NO vive aqui: se lee de database/init.sql, que es la fuente
 * unica de verdad y esta escrito para ser idempotente. Este script solo
 * (1) espera a que la BD acepte conexiones, (2) aplica ese archivo en una
 * transaccion, y (3) siembra los usuarios que requieren hash bcrypt.
 *
 * Es seguro correrlo contra una BD vacia, una recien creada o una que ya
 * tiene datos: nada de lo que ejecuta borra informacion.
 */

declare(strict_types=1);

$host     = getenv('DB_HOST') ?: 'db';
$port     = getenv('DB_PORT') ?: '5432';
$database = getenv('DB_DATABASE') ?: 'servigt';
$username = getenv('DB_USERNAME') ?: 'servigt_user';
$password = getenv('DB_PASSWORD') ?: 'servigt_secret';

$schemaFile = getenv('SCHEMA_FILE') ?: __DIR__ . '/../database/init.sql';

if (!is_readable($schemaFile)) {
    fwrite(STDERR, "[!] No se encontro el archivo de esquema: {$schemaFile}\n");
    fwrite(STDERR, "    Debe estar montado en el contenedor. Revisa el volumen\n");
    fwrite(STDERR, "    ./database/init.sql:/app/database/init.sql:ro en docker-compose.yml\n");
    exit(1);
}

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database);

$attempts = 0;
$maxAttempts = 20;

while (true) {
    try {
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        break;
    } catch (Throwable $e) {
        $attempts++;
        if ($attempts >= $maxAttempts) {
            fwrite(STDERR, "[!] No se pudo conectar a PostgreSQL para sincronizar esquema: {$e->getMessage()}\n");
            exit(1);
        }

        sleep(1);
    }
}

// ── Esquema ────────────────────────────────────────────────────────────────
// Todo el archivo va en una transaccion: si algo falla, la BD queda
// exactamente como estaba en vez de a medio migrar.
try {
    $pdo->beginTransaction();
    $pdo->exec(file_get_contents($schemaFile));
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[!] Fallo al aplicar el esquema ({$schemaFile}): {$e->getMessage()}\n");
    fwrite(STDERR, "    No se modifico la base de datos.\n");
    exit(1);
}

// ── Seed admin por defecto ─────────────────────────────────────────────────
$requiredSchemaChecks = [
    "SELECT to_regclass('publicaciones_servicio') IS NOT NULL",
    "SELECT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'servicios' AND column_name = 'publicacion_id'
    )",
];

foreach ($requiredSchemaChecks as $checkSql) {
    if (!$pdo->query($checkSql)->fetchColumn()) {
        fwrite(STDERR, "[!] El esquema no contiene la estructura esperada de publicaciones.\n");
        exit(1);
    }
}

// ── Seed admin (task 4.1) ──────────────────────────────────────────────────
// Antes esto creaba `admin@gmail.com` con la contrasena literal `admin` en
// cualquier base vacia: cualquiera que levantara el compose tal cual quedaba
// con una cuenta de administrador de credencial conocida. Ahora ADMIN_PASSWORD
// es obligatoria y el arranque falla si no viene; la contrasena nunca se
// imprime ni se registra, solo el correo.
$adminEmail    = getenv('ADMIN_EMAIL')    ?: 'admin@servigt.gt';
$adminPassword = getenv('ADMIN_PASSWORD') ?: '';
$adminName     = getenv('ADMIN_NAME')     ?: 'Administrador ServiGT';

if ($adminPassword === '') {
    fwrite(STDERR, "[!] ADMIN_PASSWORD no esta definida.\n");
    fwrite(STDERR, "    Es obligatoria: sin ella el backend crearia (o dejaria sin crear)\n");
    fwrite(STDERR, "    la cuenta de administrador de forma impredecible.\n");
    fwrite(STDERR, "    Definela en el archivo .env junto a docker-compose.yml.\n");
    exit(1);
}

if (strlen($adminPassword) < 12) {
    fwrite(STDERR, "[!] ADMIN_PASSWORD es demasiado corta (minimo 12 caracteres).\n");
    exit(1);
}

$check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$check->execute(['email' => $adminEmail]);
if (!$check->fetch()) {
    $hash = password_hash($adminPassword, PASSWORD_BCRYPT);
    $insert = $pdo->prepare(
        'INSERT INTO users (name, email, password, role) VALUES (:name, :email, :password, :role)'
    );
    $insert->execute([
        'name'     => $adminName,
        'email'    => $adminEmail,
        'password' => $hash,
        'role'     => 'admin',
    ]);
    echo "[*] Admin por defecto creado: {$adminEmail}\n";
}

// ── Neutraliza el administrador legado ─────────────────────────────────────
// Dejar de crear `admin@gmail.com` cierra el agujero solo en una base nueva. En
// cualquier entorno que haya corrido el codigo anterior la cuenta sigue ahi con
// la contrasena literal `admin`, porque el seed de arriba unicamente inserta
// cuando el correo no existe: nunca toca una fila existente. Eso incluye la
// maquina de cada integrante y cualquier instancia ya desplegada.
//
// Revocar los tokens es la parte que de verdad corta el acceso. Rotar la
// contrasena no invalida un token de Sanctum ya emitido: quien tenga uno sigue
// entrando como administrador aunque la contrasena cambie.
//
// Si la cuenta no tiene datos asociados se elimina; si los tiene se conserva la
// fila y se le pone una contrasena aleatoria que nadie conoce, para no romper
// referencias. En ambos casos el bloque es idempotente y no imprime secretos.
$legados = ['admin@gmail.com'];

foreach ($legados as $correoLegado) {
    if ($correoLegado === $adminEmail) {
        continue; // es el administrador configurado, no un residuo
    }

    $buscar = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $buscar->execute(['email' => $correoLegado]);
    $idLegado = $buscar->fetchColumn();

    if (!$idLegado) {
        continue;
    }

    $idLegado = (int) $idLegado;

    $tokens = $pdo->prepare(
        'DELETE FROM personal_access_tokens WHERE tokenable_type = :tipo AND tokenable_id = :id'
    );
    $tokens->execute(['tipo' => 'App\\Models\\User', 'id' => $idLegado]);
    $tokensRevocados = $tokens->rowCount();

    // Cualquier referencia que impida borrar la fila sin perder informacion.
    $dependencias = [
        'proveedores'    => 'user_id',
        'servicios'      => 'cliente_id',
        'pedidos'        => 'cliente_id',
        'notificaciones' => 'destinatario_id',
    ];

    $tieneDatos = false;
    foreach ($dependencias as $tablaDep => $columna) {
        $consulta = $pdo->prepare(
            "SELECT EXISTS (SELECT 1 FROM {$tablaDep} WHERE {$columna} = :id)"
        );
        $consulta->execute(['id' => $idLegado]);
        if ($consulta->fetchColumn()) {
            $tieneDatos = true;
            break;
        }
    }

    foreach ([['mensajes', 'emisor_id', 'receptor_id'],
              ['calificaciones', 'autor_id', 'destinatario_id']] as [$tablaDep, $colA, $colB]) {
        if ($tieneDatos) {
            break;
        }
        $consulta = $pdo->prepare(
            "SELECT EXISTS (SELECT 1 FROM {$tablaDep} WHERE {$colA} = :a OR {$colB} = :b)"
        );
        $consulta->execute(['a' => $idLegado, 'b' => $idLegado]);
        $tieneDatos = (bool) $consulta->fetchColumn();
    }

    if ($tieneDatos) {
        $inutilizable = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password = :password WHERE id = :id')
            ->execute(['password' => $inutilizable, 'id' => $idLegado]);
        fwrite(STDERR, "[!] Administrador legado {$correoLegado} neutralizado: {$tokensRevocados} token(s)\n");
        fwrite(STDERR, "    revocado(s) y contrasena reemplazada por un valor aleatorio.\n");
        fwrite(STDERR, "    Conserva datos asociados, por eso no se elimino. Revisala y borrala a mano\n");
        fwrite(STDERR, "    si ya no se usa.\n");
    } else {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $idLegado]);
        echo "[*] Administrador legado {$correoLegado} eliminado ({$tokensRevocados} token(s) revocado(s)).\n";
    }
}

// ── Seed proveedores de ejemplo ────────────────────────────────────────────
$sampleProviders = [
    ['Juan Perez',      'Plomería',     'Guatemala',      'Mixco',              '5555-0101', 75.00, 4.8, 'experto',     'Plomero con 15 anos de experiencia en instalaciones residenciales.'],
    ['Maria Lopez',     'Electricidad', 'Sacatepequez',   'Antigua Guatemala',  '5555-0102', 90.00, 4.5, 'experto',     'Electricista certificada, instalaciones y reparaciones.'],
    ['Carlos Ramirez',  'Pintura',      'Quetzaltenango', 'Quetzaltenango',     '5555-0103', 60.00, 4.2, 'intermedio',  'Pintura de interiores y exteriores con acabados finos.'],
    ['Ana Garcia',      'Carpintería',  'Chimaltenango',  'Chimaltenango',      '5555-0104', 85.00, 4.7, 'experto',     'Muebles a la medida y reparaciones de madera.'],
    ['Luis Morales',    'Limpieza',     'Guatemala',      'Guatemala',          '5555-0105', 45.00, 4.0, 'intermedio',  'Limpieza profunda de hogares y oficinas.'],
    ['Sofia Castillo',  'Jardinería',   'Escuintla',      'Escuintla',          '5555-0106', 50.00, 3.9, 'intermedio',  'Diseno y mantenimiento de jardines.'],
    ['Pedro Vasquez',   'Albañilería',  'Huehuetenango',  'Huehuetenango',      '5555-0107', 70.00, 4.3, 'intermedio',  'Construccion y remodelacion de obra civil.'],
    ['Lucia Mendez',    'Mecánica',     'Izabal',         'Puerto Barrios',     '5555-0108', 80.00, 4.6, 'experto',     'Mecanica automotriz general y diagnostico.'],
    ['Diego Hernandez', 'Tecnología',   'Guatemala',      'Villa Nueva',        '5555-0109', 95.00, 4.4, 'intermedio',  'Soporte tecnico, redes y reparacion de equipos.'],
    ['Elena Flores',    'Enseñanza',    'Alta Verapaz',   'Coban',              '5555-0110', 55.00, 4.9, 'experto',     'Tutoria academica de matematicas y ciencias.'],
];

// ── Seed de datos demo (task 4.1) ──────────────────────────────────────────
// Los diez proveedores de ejemplo existen para poder demostrar el catalogo, no
// para correr en un ambiente real: son cuentas con contrasena compartida y
// telefono ficticio. Se siembran solo cuando el ambiente lo pide de forma
// explicita (`SEED_DEMO_DATA=true`), con default activo unicamente en local.
$ambiente     = getenv('APP_ENV') ?: 'production';
$demoPorEnv   = in_array($ambiente, ['local', 'testing'], true) ? 'true' : 'false';
$sembrarDemo  = filter_var(getenv('SEED_DEMO_DATA') ?: $demoPorEnv, FILTER_VALIDATE_BOOLEAN);

if (!$sembrarDemo) {
    echo "[*] Datos demo omitidos (SEED_DEMO_DATA=false, APP_ENV={$ambiente}).\n";
    echo "[*] Esquema PostgreSQL sincronizado.\n";
    exit(0);
}

$providerPassword = getenv('PROVIDER_SEED_PASSWORD') ?: '';
if ($providerPassword === '') {
    fwrite(STDERR, "[!] SEED_DEMO_DATA esta activo pero PROVIDER_SEED_PASSWORD no esta definida.\n");
    fwrite(STDERR, "    Definela en el .env del ambiente o desactiva los datos demo.\n");
    exit(1);
}

$providerHash = password_hash($providerPassword, PASSWORD_BCRYPT);
$createdProviders = 0;

$findCategoria   = $pdo->prepare('SELECT id FROM categorias WHERE nombre = :nombre LIMIT 1');
$findUserByEmail = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$insertUser      = $pdo->prepare(
    'INSERT INTO users (name, email, password, role, telefono) VALUES (:name, :email, :password, :role, :telefono) RETURNING id'
);
$insertProveedor = $pdo->prepare(
    'INSERT INTO proveedores (user_id, nombre, email, telefono, descripcion, departamento, municipio, categoria_id, tarifa_hora, calificacion_promedio, nivel)
     VALUES (:user_id, :nombre, :email, :telefono, :descripcion, :departamento, :municipio, :categoria_id, :tarifa_hora, :calificacion, :nivel)'
);

foreach ($sampleProviders as $i => $p) {
    [$name, $categoriaNombre, $departamento, $municipio, $telefono, $tarifa, $calificacion, $nivel, $descripcion] = $p;
    $email = 'proveedor' . ($i + 1) . '@servigt.gt';

    $findUserByEmail->execute(['email' => $email]);
    if ($findUserByEmail->fetch()) {
        continue; // ya existe, no duplicar
    }

    $findCategoria->execute(['nombre' => $categoriaNombre]);
    $categoriaId = $findCategoria->fetchColumn();
    if (!$categoriaId) {
        continue; // categoria no encontrada, saltar
    }

    $insertUser->execute([
        'name'     => $name,
        'email'    => $email,
        'password' => $providerHash,
        'role'     => 'proveedor',
        'telefono' => $telefono,
    ]);
    $userId = $insertUser->fetchColumn();

    $insertProveedor->execute([
        'user_id'      => $userId,
        'nombre'       => $name,
        'email'        => $email,
        'telefono'     => $telefono,
        'descripcion'  => $descripcion,
        'departamento' => $departamento,
        'municipio'    => $municipio,
        'categoria_id' => $categoriaId,
        'tarifa_hora'  => $tarifa,
        'calificacion' => $calificacion,
        'nivel'        => $nivel,
    ]);

    $createdProviders++;
}

if ($createdProviders > 0) {
    echo "[*] Proveedores de ejemplo creados: {$createdProviders}\n";
}

echo "[*] Esquema PostgreSQL sincronizado.\n";
