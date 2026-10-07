-- ============================================================
-- ServiGT Guatemala — Esquema PostgreSQL (fuente unica de verdad)
-- ============================================================
--
-- Este archivo es el UNICO lugar donde se define el esquema.
-- Lo aplica backend/docker/sync_schema.php en CADA arranque del
-- contenedor backend, dentro de una sola transaccion.
--
-- Reglas para editarlo:
--   1. Todo statement debe ser IDEMPOTENTE (IF NOT EXISTS, bloques
--      DO con guarda). Se ejecuta en cada arranque, no solo el primero.
--   2. Nunca borrar datos. Nada de DROP TABLE / DELETE / TRUNCATE.
--   3. Para columnas nuevas sobre tablas ya desplegadas, agregarlas
--      al CREATE TABLE de la seccion 1 *y* como ALTER ... ADD COLUMN
--      IF NOT EXISTS en la seccion 2, para que las BD existentes
--      converjan al mismo esquema sin recrear el volumen.
--
-- Los seeds que requieren hash bcrypt (admin, proveedores de ejemplo)
-- viven en sync_schema.php porque SQL puro no puede generarlos.
-- ============================================================


-- ============================================================
-- 1. Tablas base (en orden de dependencias de llave foranea)
-- ============================================================

-- Sanctum tokens
CREATE TABLE IF NOT EXISTS personal_access_tokens (
    id BIGSERIAL PRIMARY KEY,
    tokenable_type VARCHAR(255) NOT NULL,
    tokenable_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    abilities TEXT,
    last_used_at TIMESTAMP WITHOUT TIME ZONE,
    expires_at TIMESTAMP WITHOUT TIME ZONE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pat_tokenable ON personal_access_tokens (tokenable_type, tokenable_id);

-- Usuarios (clientes y proveedores)
CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('cliente', 'proveedor', 'admin')),
    telefono VARCHAR(20),
    foto_perfil VARCHAR(500),
    documento_verificado BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Categorias de servicio
CREATE TABLE IF NOT EXISTS categorias (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL UNIQUE,
    descripcion TEXT,
    icono VARCHAR(255),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Perfil de proveedor (extiende users)
CREATE TABLE IF NOT EXISTS proveedores (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    nombre VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    telefono VARCHAR(20),
    descripcion TEXT,
    departamento VARCHAR(100) NOT NULL,
    municipio VARCHAR(100),
    categoria_id BIGINT REFERENCES categorias(id) ON DELETE RESTRICT,
    foto_perfil VARCHAR(500),
    tarifa_hora DECIMAL(10,2),
    tarifa_proyecto DECIMAL(10,2),
    calificacion_promedio DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    total_calificaciones INT NOT NULL DEFAULT 0,
    nivel VARCHAR(20) NOT NULL DEFAULT 'novato' CHECK (nivel IN ('novato','intermedio','experto')),
    premium_inicio_at TIMESTAMP WITHOUT TIME ZONE NULL,
    premium_vence_at TIMESTAMP WITHOUT TIME ZONE NULL,
    premium_ciclo_key VARCHAR(80),
    premium_renovaciones INT NOT NULL DEFAULT 0 CHECK (premium_renovaciones >= 0),
    portada VARCHAR(500),
    color_acento VARCHAR(7),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_proveedores_user_id ON proveedores (user_id);
CREATE INDEX IF NOT EXISTS idx_proveedores_categoria_id ON proveedores (categoria_id);
CREATE INDEX IF NOT EXISTS idx_proveedores_departamento ON proveedores (departamento);

-- Documentos de identidad del proveedor
CREATE TABLE IF NOT EXISTS documentos_proveedores (
    id BIGSERIAL PRIMARY KEY,
    proveedor_id BIGINT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    tipo_documento VARCHAR(100) NOT NULL,
    nombre_archivo VARCHAR(500) NOT NULL,
    ruta_archivo VARCHAR(500) NOT NULL,
    estado_validacion VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado_validacion IN ('pendiente', 'aprobado', 'rechazado')),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_docs_proveedor_id ON documentos_proveedores (proveedor_id);

-- Categorias adicionales por proveedor (N:M)
CREATE TABLE IF NOT EXISTS proveedor_categorias (
    proveedor_id BIGINT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    categoria_id BIGINT NOT NULL REFERENCES categorias(id) ON DELETE CASCADE,
    PRIMARY KEY (proveedor_id, categoria_id)
);

-- Disponibilidad semanal del proveedor
CREATE TABLE IF NOT EXISTS disponibilidad (
    id BIGSERIAL PRIMARY KEY,
    proveedor_id BIGINT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    dia_semana INT NOT NULL CHECK (dia_semana BETWEEN 0 AND 6), -- 0=Dom, 1=Lun...
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    disponible BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (proveedor_id, dia_semana)
);
CREATE INDEX IF NOT EXISTS idx_disponibilidad_proveedor ON disponibilidad (proveedor_id);

-- Creditos del proveedor (saldo unico por proveedor)
CREATE TABLE IF NOT EXISTS creditos_proveedor (
    proveedor_id BIGINT PRIMARY KEY REFERENCES proveedores(id) ON DELETE CASCADE,
    saldo INT NOT NULL DEFAULT 0 CHECK (saldo >= 0),
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Log de movimientos de creditos (auditoria + historial)
CREATE TABLE IF NOT EXISTS transacciones_credito (
    id BIGSERIAL PRIMARY KEY,
    proveedor_id BIGINT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    tipo VARCHAR(20) NOT NULL CHECK (tipo IN ('bono','gasto','recarga')),
    monto INT NOT NULL CHECK (monto > 0),
    motivo VARCHAR(255) NOT NULL,
    referencia_id BIGINT,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_trans_cred_proveedor ON transacciones_credito (proveedor_id, created_at DESC);

-- Paquetes de creditos comprables (catalogo administrable sin tocar codigo)
CREATE TABLE IF NOT EXISTS paquetes_creditos (
    id BIGSERIAL PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    precio_gtq DECIMAL(10,2) NOT NULL CHECK (precio_gtq > 0),
    creditos_base INT NOT NULL CHECK (creditos_base > 0),
    creditos_bonus INT NOT NULL DEFAULT 0 CHECK (creditos_bonus >= 0),
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    orden INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_paquetes_creditos_activo ON paquetes_creditos (activo, orden);

-- Compras de creditos (simuladas durante el MVP: sin datos bancarios reales)
CREATE TABLE IF NOT EXISTS compras_creditos (
    id BIGSERIAL PRIMARY KEY,
    proveedor_id BIGINT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    paquete_id BIGINT NOT NULL REFERENCES paquetes_creditos(id) ON DELETE RESTRICT,
    monto_gtq DECIMAL(10,2) NOT NULL CHECK (monto_gtq > 0),
    creditos_otorgados INT NOT NULL CHECK (creditos_otorgados > 0),
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente','completada','fallida','cancelada')),
    referencia VARCHAR(20) NOT NULL UNIQUE,
    idempotency_key VARCHAR(100) NOT NULL,
    completada_at TIMESTAMP WITHOUT TIME ZONE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT compras_creditos_proveedor_idem_uk UNIQUE (proveedor_id, idempotency_key)
);
CREATE INDEX IF NOT EXISTS idx_compras_creditos_proveedor ON compras_creditos (proveedor_id, created_at DESC);

-- Publicaciones de servicios ofrecidos por proveedores
CREATE TABLE IF NOT EXISTS publicaciones_servicio (
    id BIGSERIAL PRIMARY KEY,
    proveedor_id BIGINT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    categoria_id BIGINT REFERENCES categorias(id) ON DELETE SET NULL,
    titulo VARCHAR(120) NOT NULL,
    descripcion TEXT NOT NULL,
    precio_referencial DECIMAL(10,2),
    imagen VARCHAR(500),
    estado VARCHAR(20) NOT NULL DEFAULT 'activa'
        CHECK (estado IN ('activa','inactiva')),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_publicaciones_servicio_visible
    ON publicaciones_servicio (estado, categoria_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_publicaciones_servicio_proveedor
    ON publicaciones_servicio (proveedor_id, estado);

-- Servicios (solicitudes de trabajo)
CREATE TABLE IF NOT EXISTS servicios (
    id BIGSERIAL PRIMARY KEY,
    cliente_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    proveedor_id BIGINT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    publicacion_id BIGINT REFERENCES publicaciones_servicio(id) ON DELETE SET NULL,
    publicacion_titulo VARCHAR(120),
    publicacion_precio_referencial DECIMAL(10,2),
    categoria_id BIGINT REFERENCES categorias(id) ON DELETE SET NULL,
    descripcion TEXT NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente','aceptado','en_camino','en_progreso','por_confirmar','completado','cancelado','rechazado')),
    fecha_agendada TIMESTAMP WITHOUT TIME ZONE,
    direccion VARCHAR(500),
    monto_acordado DECIMAL(10,2),
    codigo_inicio VARCHAR(10),
    codigo_fin VARCHAR(10),
    motivo_cancelacion TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_servicios_cliente ON servicios (cliente_id);
CREATE INDEX IF NOT EXISTS idx_servicios_proveedor ON servicios (proveedor_id);
CREATE INDEX IF NOT EXISTS idx_servicios_estado ON servicios (estado);

-- Pedidos (marketplace de demanda: cliente publica, proveedores cotizan)
CREATE TABLE IF NOT EXISTS pedidos (
    id BIGSERIAL PRIMARY KEY,
    cliente_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    descripcion TEXT NOT NULL,
    categoria_id BIGINT REFERENCES categorias(id) ON DELETE SET NULL,
    direccion VARCHAR(500),
    urgencia VARCHAR(10) NOT NULL DEFAULT 'media'
        CHECK (urgencia IN ('baja','media','alta')),
    estado VARCHAR(20) NOT NULL DEFAULT 'abierto'
        CHECK (estado IN ('abierto','adjudicado','cerrado','expirado')),
    fecha_expiracion TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pedidos_cliente ON pedidos (cliente_id);
CREATE INDEX IF NOT EXISTS idx_pedidos_estado ON pedidos (estado);
CREATE INDEX IF NOT EXISTS idx_pedidos_categoria ON pedidos (categoria_id);

-- Cotizaciones (ofertas de proveedores a un pedido del cliente)
CREATE TABLE IF NOT EXISTS cotizaciones (
    id BIGSERIAL PRIMARY KEY,
    pedido_id BIGINT NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    proveedor_id BIGINT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    monto DECIMAL(10,2) NOT NULL CHECK (monto > 0),
    mensaje TEXT NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'enviada'
        CHECK (estado IN ('enviada','aceptada','rechazada','retirada')),
    costo_creditos INT NOT NULL DEFAULT 0 CHECK (costo_creditos >= 0),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_cotizaciones_pedido_proveedor ON cotizaciones (pedido_id, proveedor_id);

-- Pagos
CREATE TABLE IF NOT EXISTS pagos (
    id BIGSERIAL PRIMARY KEY,
    servicio_id BIGINT NOT NULL REFERENCES servicios(id) ON DELETE CASCADE,
    monto DECIMAL(10,2) NOT NULL,
    metodo_pago VARCHAR(50) NOT NULL DEFAULT 'efectivo'
        CHECK (metodo_pago IN ('efectivo','transferencia','tarjeta')),
    estado VARCHAR(20) NOT NULL DEFAULT 'pendiente'
        CHECK (estado IN ('pendiente','completado','fallido','reembolsado')),
    referencia VARCHAR(255),
    fecha_pago TIMESTAMP WITHOUT TIME ZONE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_pagos_servicio ON pagos (servicio_id);

-- Calificaciones
CREATE TABLE IF NOT EXISTS calificaciones (
    id BIGSERIAL PRIMARY KEY,
    servicio_id BIGINT NOT NULL REFERENCES servicios(id) ON DELETE CASCADE,
    autor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    destinatario_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    puntuacion INT NOT NULL CHECK (puntuacion BETWEEN 1 AND 5),
    comentario TEXT,
    es_verificada BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (servicio_id, autor_id)
);
CREATE INDEX IF NOT EXISTS idx_calif_destinatario ON calificaciones (destinatario_id);
CREATE INDEX IF NOT EXISTS idx_calif_servicio ON calificaciones (servicio_id);

-- Mensajes (chat)
CREATE TABLE IF NOT EXISTS mensajes (
    id BIGSERIAL PRIMARY KEY,
    emisor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    receptor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    servicio_id BIGINT REFERENCES servicios(id) ON DELETE SET NULL,
    contenido TEXT NOT NULL CHECK (LENGTH(BTRIM(contenido)) > 0),
    leido BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mensajes_emisor_receptor ON mensajes (emisor_id, receptor_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_mensajes_servicio ON mensajes (servicio_id);

-- Notificaciones
CREATE TABLE IF NOT EXISTS notificaciones (
    id BIGSERIAL PRIMARY KEY,
    destinatario_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tipo VARCHAR(50) NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    mensaje TEXT NOT NULL,
    datos JSONB,
    leida BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_notif_destinatario ON notificaciones (destinatario_id, leida);


-- ============================================================
-- 2. Convergencia de BD preexistentes
-- ============================================================
-- Los CREATE TABLE de arriba no tocan una tabla que ya existe. Estos
-- statements alinean bases creadas por versiones anteriores del esquema
-- (o por migraciones de Laravel) sin destruir datos.

ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'cliente';
ALTER TABLE users ADD COLUMN IF NOT EXISTS telefono VARCHAR(20);
ALTER TABLE users ADD COLUMN IF NOT EXISTS foto_perfil VARCHAR(500);
ALTER TABLE users ADD COLUMN IF NOT EXISTS documento_verificado BOOLEAN NOT NULL DEFAULT FALSE;

ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS user_id BIGINT;
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS foto_perfil VARCHAR(500);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS tarifa_hora DECIMAL(10,2);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS tarifa_proyecto DECIMAL(10,2);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS calificacion_promedio DECIMAL(3,2) NOT NULL DEFAULT 0.00;
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS total_calificaciones INT NOT NULL DEFAULT 0;
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS nivel VARCHAR(20) NOT NULL DEFAULT 'novato';

-- El chat se agrego despues, sobre bases que ya tenian la tabla mensajes
-- incompleta.
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS emisor_id BIGINT REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS receptor_id BIGINT REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS servicio_id BIGINT REFERENCES servicios(id) ON DELETE SET NULL;
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS contenido TEXT;
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS leido BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE mensajes ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- Un proveedor como maximo por usuario. Indice parcial: admite varios
-- proveedores heredados sin user_id, pero impide duplicar el vinculo.
CREATE UNIQUE INDEX IF NOT EXISTS idx_proveedores_user_id_unique
    ON proveedores (user_id) WHERE user_id IS NOT NULL;

-- FK proveedores.user_id -> users.id. Se busca por columna y no por
-- nombre: el CREATE TABLE la nombra "proveedores_user_id_fkey" y una
-- version previa de este script la creaba como "..._foreign", con lo
-- que se llegaban a tener las dos apuntando a lo mismo.
DO $$
DECLARE
    duplicada TEXT;
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conrelid = 'proveedores'::regclass
          AND contype = 'f'
          AND conkey = ARRAY[(
              SELECT attnum FROM pg_attribute
              WHERE attrelid = 'proveedores'::regclass AND attname = 'user_id'
          )]::smallint[]
    ) THEN
        ALTER TABLE proveedores
            ADD CONSTRAINT proveedores_user_id_fkey
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;

    -- Limpiar la FK redundante que dejo la version anterior.
    FOR duplicada IN
        SELECT conname
        FROM pg_constraint
        WHERE conrelid = 'proveedores'::regclass
          AND contype = 'f'
          AND conname = 'proveedores_user_id_foreign'
          AND EXISTS (
              SELECT 1 FROM pg_constraint otra
              WHERE otra.conrelid = 'proveedores'::regclass
                AND otra.contype = 'f'
                AND otra.conname = 'proveedores_user_id_fkey'
          )
    LOOP
        EXECUTE format('ALTER TABLE proveedores DROP CONSTRAINT %I', duplicada);
    END LOOP;
END $$;

-- servicios.estado gano el valor 'por_confirmar'. Solo se recrea el CHECK
-- si al actual le falta: recrearlo en cada arranque tomaria un lock
-- exclusivo y reescanearia la tabla completa sin necesidad.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'servicios'::regclass
          AND conname = 'servicios_estado_check'
          AND pg_get_constraintdef(oid) LIKE '%por_confirmar%'
    ) THEN
        ALTER TABLE servicios DROP CONSTRAINT IF EXISTS servicios_estado_check;
        ALTER TABLE servicios ADD CONSTRAINT servicios_estado_check
            CHECK (estado IN ('pendiente','aceptado','en_camino','en_progreso','por_confirmar','completado','cancelado','rechazado'));
    END IF;
END $$;


-- proveedores gana premium_vence_at: NULL = nunca activado, fecha futura =
-- activo, fecha pasada = vencido. El estado se deriva de esta unica columna
-- para no duplicar la fuente de verdad en un campo de estado aparte.
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS premium_inicio_at TIMESTAMP WITHOUT TIME ZONE NULL;
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS premium_vence_at TIMESTAMP WITHOUT TIME ZONE NULL;
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS premium_ciclo_key VARCHAR(80);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS premium_renovaciones INT NOT NULL DEFAULT 0;

-- Personalizacion del perfil del proveedor: imagen de portada y color de
-- acento. Ambas opcionales; un perfil sin ellas se dibuja con el degradado
-- de marca de siempre.
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS portada VARCHAR(500);
ALTER TABLE proveedores ADD COLUMN IF NOT EXISTS color_acento VARCHAR(7);

-- Publicaciones y trazabilidad nullable desde servicios. El snapshot conserva
-- datos minimos aunque luego la publicacion se edite o elimine.
CREATE TABLE IF NOT EXISTS publicaciones_servicio (
    id BIGSERIAL PRIMARY KEY,
    proveedor_id BIGINT NOT NULL REFERENCES proveedores(id) ON DELETE CASCADE,
    categoria_id BIGINT REFERENCES categorias(id) ON DELETE SET NULL,
    titulo VARCHAR(120) NOT NULL,
    descripcion TEXT NOT NULL,
    precio_referencial DECIMAL(10,2),
    imagen VARCHAR(500),
    estado VARCHAR(20) NOT NULL DEFAULT 'activa'
        CHECK (estado IN ('activa','inactiva')),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_publicaciones_servicio_visible
    ON publicaciones_servicio (estado, categoria_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_publicaciones_servicio_proveedor
    ON publicaciones_servicio (proveedor_id, estado);

ALTER TABLE servicios ADD COLUMN IF NOT EXISTS publicacion_id BIGINT REFERENCES publicaciones_servicio(id) ON DELETE SET NULL;
ALTER TABLE servicios ADD COLUMN IF NOT EXISTS publicacion_titulo VARCHAR(120);
ALTER TABLE servicios ADD COLUMN IF NOT EXISTS publicacion_precio_referencial DECIMAL(10,2);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'servicios'::regclass
          AND contype = 'f'
          AND conkey = ARRAY[(
              SELECT attnum FROM pg_attribute
              WHERE attrelid = 'servicios'::regclass AND attname = 'publicacion_id'
          )]::smallint[]
    ) THEN
        ALTER TABLE servicios
            ADD CONSTRAINT servicios_publicacion_id_fkey
            FOREIGN KEY (publicacion_id) REFERENCES publicaciones_servicio(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'proveedores'::regclass
          AND conname = 'proveedores_premium_renovaciones_check'
    ) THEN
        ALTER TABLE proveedores ADD CONSTRAINT proveedores_premium_renovaciones_check
            CHECK (premium_renovaciones >= 0);
    END IF;
END $$;

-- transacciones_credito.tipo gano el valor 'compra'. Igual que arriba, solo
-- se recrea el CHECK si al actual le falta, para no tomar el lock en cada arranque.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'transacciones_credito'::regclass
          AND conname = 'transacciones_credito_tipo_check'
          AND pg_get_constraintdef(oid) LIKE '%compra%'
    ) THEN
        ALTER TABLE transacciones_credito DROP CONSTRAINT IF EXISTS transacciones_credito_tipo_check;
        ALTER TABLE transacciones_credito ADD CONSTRAINT transacciones_credito_tipo_check
            CHECK (tipo IN ('bono','gasto','recarga','compra'));
    END IF;
END $$;

-- La unicidad de idempotency_key nacio global, pero el controlador siempre la
-- consulta acotada al proveedor autenticado. Esa asimetria hacia que la clave
-- de un proveedor bloqueara la de otro: la consulta no veia la fila ajena,
-- pasaba el chequeo y reventaba contra el UNIQUE con un 500. La clave solo
-- tiene que ser unica DENTRO de cada proveedor.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'compras_creditos'::regclass
          AND conname = 'compras_creditos_proveedor_idem_uk'
    ) THEN
        ALTER TABLE compras_creditos DROP CONSTRAINT IF EXISTS compras_creditos_idempotency_key_key;
        ALTER TABLE compras_creditos ADD CONSTRAINT compras_creditos_proveedor_idem_uk
            UNIQUE (proveedor_id, idempotency_key);
    END IF;
END $$;

-- Las categorias se sembraron sin tildes ni ñ. Este renombre corre ANTES del
-- seed a proposito: si corriera despues, el seed ya habria insertado la
-- version acentuada junto a la vieja y el UPDATE chocaria con el UNIQUE de
-- nombre. La guarda cubre el caso de que ambas ya convivan.
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT * FROM (VALUES
            ('Plomeria',    'Plomería',    'Servicios de plomería y fontanería'),
            ('Carpinteria', 'Carpintería', 'Trabajos en madera y muebles'),
            ('Jardineria',  'Jardinería',  'Mantenimiento de jardines y áreas verdes'),
            ('Albanileria', 'Albañilería', 'Construcción y reparaciones de obra civil'),
            ('Mecanica',    'Mecánica',    'Reparación de vehículos y maquinaria'),
            ('Tecnologia',  'Tecnología',  'Soporte técnico y reparación de equipos'),
            ('Ensenanza',   'Enseñanza',   'Clases particulares y tutoría académica')
        ) AS t(viejo, nuevo, descripcion)
    LOOP
        IF EXISTS (SELECT 1 FROM categorias WHERE nombre = r.viejo)
           AND NOT EXISTS (SELECT 1 FROM categorias WHERE nombre = r.nuevo) THEN
            UPDATE categorias
               SET nombre = r.nuevo, descripcion = r.descripcion
             WHERE nombre = r.viejo;
        END IF;
    END LOOP;
END $$;

-- Electricidad no cambia de nombre, solo la descripcion gana tildes.
UPDATE categorias
   SET descripcion = 'Instalaciones y reparaciones eléctricas'
 WHERE nombre = 'Electricidad'
   AND descripcion = 'Instalaciones y reparaciones electricas';


-- ============================================================
-- 3. Seed de catalogo
-- ============================================================
INSERT INTO categorias (nombre, descripcion, icono) VALUES
    ('Plomería',      'Servicios de plomería y fontanería',          'wrench'),
    ('Electricidad',  'Instalaciones y reparaciones eléctricas',     'zap'),
    ('Pintura',       'Pintura de interiores y exteriores',          'brush'),
    ('Carpintería',   'Trabajos en madera y muebles',                'hammer'),
    ('Limpieza',      'Servicios de limpieza del hogar y oficina',   'sparkles'),
    ('Jardinería',    'Mantenimiento de jardines y áreas verdes',    'leaf'),
    ('Albañilería',   'Construcción y reparaciones de obra civil',   'building'),
    ('Mecánica',      'Reparación de vehículos y maquinaria',        'settings'),
    ('Tecnología',    'Soporte técnico y reparación de equipos',     'monitor'),
    ('Enseñanza',     'Clases particulares y tutoría académica',     'book')
ON CONFLICT (nombre) DO NOTHING;

-- Paquetes de creditos ratificados para Sprint 6 (precio oficial en GTQ)
INSERT INTO paquetes_creditos (nombre, precio_gtq, creditos_base, creditos_bonus, orden) VALUES
    ('Inicial',      39.00,  8,   0,  1),
    ('Impulso',      115.00, 25,  5,  2),
    ('Profesional',  459.00, 110, 25, 3),
    ('Negocio',      765.00, 190, 60, 4)
ON CONFLICT (nombre) DO NOTHING;
