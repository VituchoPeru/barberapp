-- ============================================================
-- BARBERAPP SaaS · Script SQL completo para MySQL 8.0+
-- Generado con: php bin/console schema:build
-- Fuente de verdad: database/migrations/*.sql (no editar este archivo a mano)
-- ============================================================

CREATE DATABASE IF NOT EXISTS `barberapp` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `barberapp`;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS migraciones (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migracion   VARCHAR(191) NOT NULL,
    checksum    CHAR(64)     NOT NULL,
    lote        INT UNSIGNED NOT NULL,
    aplicada_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_migraciones_migracion (migracion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================
-- BARBERAPP · Migración 0001 · Núcleo SaaS
-- planes, roles, permisos, usuarios, tenants, membresías,
-- sucursales, horarios de sucursal, suscripciones y pagos SaaS.
-- Convenciones:
--   * InnoDB + utf8mb4_0900_ai_ci
--   * Fechas de eventos en UTC (DATETIME; la conexión fija time_zone = '+00:00')
--   * Importes en DECIMAL(10,2), moneda ISO 4217 (PEN por defecto)
--   * Tablas de una barbería llevan tenant_id y UNIQUE (tenant_id, id) para
--     permitir claves foráneas compuestas que impiden mezclar datos entre tenants.
-- ============================================================

CREATE TABLE planes (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo            VARCHAR(40)     NOT NULL,
    nombre            VARCHAR(80)     NOT NULL,
    descripcion       VARCHAR(500)    NULL,
    precio_mensual    DECIMAL(10,2)   NOT NULL,
    precio_anual      DECIMAL(10,2)   NULL,
    moneda            CHAR(3)         NOT NULL DEFAULT 'PEN',
    dias_prueba       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_sucursales    INT UNSIGNED    NULL COMMENT 'NULL = ilimitado',
    max_barberos      INT UNSIGNED    NULL COMMENT 'NULL = ilimitado',
    caracteristicas   JSON            NULL COMMENT 'Funcionalidades habilitadas: reportes_avanzados, personalizacion, canales...',
    publico           TINYINT(1)      NOT NULL DEFAULT 1,
    activo            TINYINT(1)      NOT NULL DEFAULT 1,
    orden             SMALLINT        NOT NULL DEFAULT 0,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_planes_codigo (codigo),
    CONSTRAINT ck_planes_precio CHECK (precio_mensual >= 0 AND (precio_anual IS NULL OR precio_anual >= 0)),
    CONSTRAINT ck_planes_moneda CHECK (REGEXP_LIKE(moneda, '^[A-Z]{3}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Planes comerciales del SaaS';

CREATE TABLE roles (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo       VARCHAR(40)  NOT NULL,
    nombre       VARCHAR(80)  NOT NULL,
    descripcion  VARCHAR(255) NULL,
    ambito       ENUM('plataforma','tenant','cliente') NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_roles_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='superadmin, propietario, administrador, barbero, cliente';

CREATE TABLE permisos (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo       VARCHAR(80)  NOT NULL COMMENT 'modulo.accion, p. ej. reservas.cancelar',
    modulo       VARCHAR(40)  NOT NULL,
    descripcion  VARCHAR(255) NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_permisos_codigo (codigo),
    KEY idx_permisos_modulo (modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rol_permisos (
    rol_id      BIGINT UNSIGNED NOT NULL,
    permiso_id  BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (rol_id, permiso_id),
    KEY idx_rol_permisos_permiso (permiso_id),
    CONSTRAINT fk_rol_permisos_rol     FOREIGN KEY (rol_id)     REFERENCES roles (id)    ON DELETE CASCADE,
    CONSTRAINT fk_rol_permisos_permiso FOREIGN KEY (permiso_id) REFERENCES permisos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE usuarios (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email                 VARCHAR(190)    NOT NULL,
    password_hash         VARCHAR(255)    NULL COMMENT 'password_hash(); NULL si solo usa acceso social o invitación pendiente',
    nombres               VARCHAR(100)    NOT NULL,
    apellidos             VARCHAR(100)    NULL,
    telefono              VARCHAR(20)     NULL COMMENT 'Formato E.164, p. ej. +51987654321',
    avatar_url            VARCHAR(500)    NULL,
    rol_plataforma_id     BIGINT UNSIGNED NULL COMMENT 'Solo personal de BARBERAPP (superadmin)',
    estado                ENUM('activo','bloqueado','eliminado') NOT NULL DEFAULT 'activo',
    email_verificado_en   DATETIME        NULL,
    ultimo_login_en       DATETIME        NULL,
    acepto_terminos_en    DATETIME        NULL,
    acepto_privacidad_en  DATETIME        NULL COMMENT 'Consentimiento Ley 29733',
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at            DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuarios_email (email),
    KEY idx_usuarios_telefono (telefono),
    KEY idx_usuarios_rol_plataforma (rol_plataforma_id),
    CONSTRAINT fk_usuarios_rol_plataforma FOREIGN KEY (rol_plataforma_id) REFERENCES roles (id) ON DELETE RESTRICT,
    CONSTRAINT ck_usuarios_telefono CHECK (telefono IS NULL OR REGEXP_LIKE(telefono, '^\\+?[0-9]{6,15}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Cuenta única por persona; los roles por barbería están en membresias';

CREATE TABLE tenants (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug                    VARCHAR(80)     NOT NULL COMMENT 'Enlace público /b/{slug}',
    nombre_comercial        VARCHAR(120)    NOT NULL,
    razon_social            VARCHAR(160)    NULL,
    ruc                     CHAR(11)        NULL,
    email                   VARCHAR(190)    NULL,
    telefono                VARCHAR(20)     NULL,
    descripcion             TEXT            NULL,
    logo_url                VARCHAR(500)    NULL,
    portada_url             VARCHAR(500)    NULL,
    color_primario          CHAR(7)         NOT NULL DEFAULT '#C9A45C',
    color_secundario        CHAR(7)         NOT NULL DEFAULT '#111111',
    zona_horaria            VARCHAR(64)     NOT NULL DEFAULT 'America/Lima',
    estado                  ENUM('pendiente','activo','suspendido','cancelado') NOT NULL DEFAULT 'pendiente',
    publicado_marketplace   TINYINT(1)      NOT NULL DEFAULT 0,
    propietario_usuario_id  BIGINT UNSIGNED NULL,
    aprobado_en             DATETIME        NULL,
    suspendido_en           DATETIME        NULL,
    motivo_suspension       VARCHAR(255)    NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenants_slug (slug),
    UNIQUE KEY uk_tenants_ruc (ruc),
    KEY idx_tenants_estado_marketplace (estado, publicado_marketplace),
    KEY idx_tenants_propietario (propietario_usuario_id),
    FULLTEXT KEY ft_tenants_busqueda (nombre_comercial, descripcion),
    CONSTRAINT fk_tenants_propietario FOREIGN KEY (propietario_usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT ck_tenants_slug   CHECK (REGEXP_LIKE(slug, '^[a-z0-9]+(-[a-z0-9]+)*$', 'c')),
    CONSTRAINT ck_tenants_ruc    CHECK (ruc IS NULL OR REGEXP_LIKE(ruc, '^(10|15|17|20)[0-9]{9}$')),
    CONSTRAINT ck_tenants_colores CHECK (REGEXP_LIKE(color_primario, '^#[0-9A-Fa-f]{6}$') AND REGEXP_LIKE(color_secundario, '^#[0-9A-Fa-f]{6}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Cada barbería es un tenant';

CREATE TABLE sucursales (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    nombre         VARCHAR(120)    NOT NULL,
    slug           VARCHAR(80)     NOT NULL,
    direccion      VARCHAR(255)    NOT NULL,
    referencia     VARCHAR(255)    NULL,
    distrito       VARCHAR(80)     NOT NULL,
    provincia      VARCHAR(80)     NOT NULL DEFAULT 'Lima',
    departamento   VARCHAR(80)     NOT NULL DEFAULT 'Lima',
    ubigeo         CHAR(6)         NULL,
    latitud        DECIMAL(9,6)    NULL,
    longitud       DECIMAL(9,6)    NULL,
    telefono       VARCHAR(20)     NULL,
    email          VARCHAR(190)    NULL,
    zona_horaria   VARCHAR(64)     NOT NULL DEFAULT 'America/Lima',
    es_principal   TINYINT(1)      NOT NULL DEFAULT 0,
    activo         TINYINT(1)      NOT NULL DEFAULT 1,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_sucursales_tenant_id (tenant_id, id),
    UNIQUE KEY uk_sucursales_tenant_slug (tenant_id, slug),
    KEY idx_sucursales_distrito (departamento, provincia, distrito),
    KEY idx_sucursales_geo (latitud, longitud),
    CONSTRAINT fk_sucursales_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT ck_sucursales_coordenadas CHECK (
        (latitud IS NULL AND longitud IS NULL)
        OR (latitud IS NOT NULL AND longitud IS NOT NULL
            AND latitud BETWEEN -90 AND 90 AND longitud BETWEEN -180 AND 180)
    ),
    CONSTRAINT ck_sucursales_slug CHECK (REGEXP_LIKE(slug, '^[a-z0-9]+(-[a-z0-9]+)*$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE horarios_sucursales (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    sucursal_id    BIGINT UNSIGNED NOT NULL,
    dia_semana     TINYINT UNSIGNED NOT NULL COMMENT 'ISO-8601: 1 = lunes … 7 = domingo',
    hora_apertura  TIME            NOT NULL COMMENT 'Hora local de la sucursal',
    hora_cierre    TIME            NOT NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_horarios_sucursales_tramo (sucursal_id, dia_semana, hora_apertura),
    KEY idx_horarios_sucursales_tenant (tenant_id, sucursal_id),
    CONSTRAINT fk_horarios_sucursales_sucursal FOREIGN KEY (tenant_id, sucursal_id) REFERENCES sucursales (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_horarios_sucursales_dia  CHECK (dia_semana BETWEEN 1 AND 7),
    CONSTRAINT ck_horarios_sucursales_rango CHECK (hora_cierre > hora_apertura)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Horario comercial publicado en el marketplace';

CREATE TABLE membresias (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    usuario_id     BIGINT UNSIGNED NOT NULL,
    rol_id         BIGINT UNSIGNED NOT NULL,
    estado         ENUM('invitado','activo','suspendido','revocado') NOT NULL DEFAULT 'activo',
    invitado_por   BIGINT UNSIGNED NULL,
    aceptado_en    DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_membresias_tenant_id (tenant_id, id),
    UNIQUE KEY uk_membresias_usuario_tenant_rol (usuario_id, tenant_id, rol_id),
    KEY idx_membresias_tenant_estado (tenant_id, estado),
    KEY idx_membresias_rol (rol_id),
    KEY idx_membresias_invitado_por (invitado_por),
    CONSTRAINT fk_membresias_tenant  FOREIGN KEY (tenant_id)    REFERENCES tenants (id)  ON DELETE RESTRICT,
    CONSTRAINT fk_membresias_usuario FOREIGN KEY (usuario_id)   REFERENCES usuarios (id) ON DELETE RESTRICT,
    CONSTRAINT fk_membresias_rol     FOREIGN KEY (rol_id)       REFERENCES roles (id)    ON DELETE RESTRICT,
    CONSTRAINT fk_membresias_invitador FOREIGN KEY (invitado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Rol de un usuario dentro de una barbería (un usuario puede tener roles distintos en barberías distintas)';

CREATE TABLE suscripciones (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id              BIGINT UNSIGNED NOT NULL,
    plan_id                BIGINT UNSIGNED NOT NULL,
    estado                 ENUM('prueba','activa','vencida','suspendida','cancelada') NOT NULL,
    ciclo                  ENUM('mensual','anual') NOT NULL DEFAULT 'mensual',
    precio_pactado         DECIMAL(10,2)   NOT NULL,
    moneda                 CHAR(3)         NOT NULL DEFAULT 'PEN',
    inicio_en              DATETIME        NOT NULL,
    periodo_fin_en         DATETIME        NOT NULL COMMENT 'Fin del periodo pagado o de la prueba',
    prueba_fin_en          DATETIME        NULL,
    cancelada_en           DATETIME        NULL,
    renovacion_automatica  TINYINT(1)      NOT NULL DEFAULT 0,
    notas                  VARCHAR(500)    NULL,
    -- Solo puede existir una suscripción vigente (prueba/activa) por tenant.
    tenant_vigente         BIGINT UNSIGNED AS (IF(estado IN ('prueba','activa'), tenant_id, NULL)) VIRTUAL,
    created_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_suscripciones_tenant_id (tenant_id, id),
    UNIQUE KEY uk_suscripciones_vigente (tenant_vigente),
    KEY idx_suscripciones_estado_fin (estado, periodo_fin_en),
    KEY idx_suscripciones_plan (plan_id),
    CONSTRAINT fk_suscripciones_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_suscripciones_plan   FOREIGN KEY (plan_id)   REFERENCES planes (id)  ON DELETE RESTRICT,
    CONSTRAINT ck_suscripciones_precio  CHECK (precio_pactado >= 0),
    CONSTRAINT ck_suscripciones_periodo CHECK (periodo_fin_en > inicio_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE pagos_saas (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    suscripcion_id      BIGINT UNSIGNED NOT NULL,
    monto               DECIMAL(10,2)   NOT NULL,
    moneda              CHAR(3)         NOT NULL DEFAULT 'PEN',
    metodo              ENUM('manual','transferencia','yape','plin','efectivo','pasarela') NOT NULL,
    estado              ENUM('pendiente','confirmado','rechazado','reembolsado') NOT NULL DEFAULT 'pendiente',
    referencia_externa  VARCHAR(120)    NULL COMMENT 'N.º de operación o ID de la pasarela. Nunca datos de tarjeta.',
    periodo_inicio      DATE            NOT NULL,
    periodo_fin         DATE            NOT NULL,
    pagado_en           DATETIME        NULL,
    registrado_por      BIGINT UNSIGNED NULL,
    notas               VARCHAR(500)    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_pagos_saas_referencia (metodo, referencia_externa),
    KEY idx_pagos_saas_tenant_fecha (tenant_id, pagado_en),
    KEY idx_pagos_saas_suscripcion (tenant_id, suscripcion_id),
    KEY idx_pagos_saas_estado (estado, pagado_en),
    KEY idx_pagos_saas_registrado_por (registrado_por),
    CONSTRAINT fk_pagos_saas_suscripcion FOREIGN KEY (tenant_id, suscripcion_id) REFERENCES suscripciones (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_pagos_saas_registrado_por FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT ck_pagos_saas_monto   CHECK (monto > 0),
    CONSTRAINT ck_pagos_saas_periodo CHECK (periodo_fin >= periodo_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Pagos de suscripción de las barberías a BARBERAPP';

-- ============================================================
-- BARBERAPP · Migración 0002 · Gestión de barberías
-- barberos, categorías de servicio, servicios, barbero_servicios,
-- horarios de barberos, bloqueos de agenda y políticas de reserva.
-- ============================================================

CREATE TABLE barberos (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id        BIGINT UNSIGNED NOT NULL,
    usuario_id       BIGINT UNSIGNED NULL COMMENT 'Cuenta de acceso del barbero (opcional)',
    sucursal_id      BIGINT UNSIGNED NOT NULL COMMENT 'Sucursal principal',
    nombre_publico   VARCHAR(100)    NOT NULL,
    biografia        VARCHAR(1000)   NULL,
    foto_url         VARCHAR(500)    NULL,
    activo           TINYINT(1)      NOT NULL DEFAULT 1,
    acepta_reservas_online TINYINT(1) NOT NULL DEFAULT 1,
    orden            SMALLINT        NOT NULL DEFAULT 0,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at       DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_barberos_tenant_id (tenant_id, id),
    UNIQUE KEY uk_barberos_tenant_usuario (tenant_id, usuario_id),
    KEY idx_barberos_sucursal (tenant_id, sucursal_id, activo),
    KEY idx_barberos_usuario (usuario_id),
    CONSTRAINT fk_barberos_tenant   FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_barberos_usuario  FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT,
    CONSTRAINT fk_barberos_sucursal FOREIGN KEY (tenant_id, sucursal_id) REFERENCES sucursales (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='La fila del barbero es el candado estable de su agenda (SELECT ... FOR UPDATE)';

CREATE TABLE categorias_servicio (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    nombre      VARCHAR(80)     NOT NULL,
    orden       SMALLINT        NOT NULL DEFAULT 0,
    activo      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_categorias_servicio_tenant_id (tenant_id, id),
    UNIQUE KEY uk_categorias_servicio_nombre (tenant_id, nombre),
    CONSTRAINT fk_categorias_servicio_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE servicios (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    categoria_id      BIGINT UNSIGNED NULL,
    nombre            VARCHAR(120)    NOT NULL,
    descripcion       VARCHAR(1000)   NULL,
    duracion_minutos  SMALLINT UNSIGNED NOT NULL,
    precio            DECIMAL(10,2)   NOT NULL,
    moneda            CHAR(3)         NOT NULL DEFAULT 'PEN',
    imagen_url        VARCHAR(500)    NULL,
    activo            TINYINT(1)      NOT NULL DEFAULT 1,
    orden             SMALLINT        NOT NULL DEFAULT 0,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_servicios_tenant_id (tenant_id, id),
    KEY idx_servicios_tenant_activo (tenant_id, activo, orden),
    KEY idx_servicios_categoria (tenant_id, categoria_id),
    KEY idx_servicios_precio (precio),
    CONSTRAINT fk_servicios_tenant    FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_servicios_categoria FOREIGN KEY (tenant_id, categoria_id) REFERENCES categorias_servicio (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT ck_servicios_duracion CHECK (duracion_minutos BETWEEN 1 AND 600),
    CONSTRAINT ck_servicios_precio   CHECK (precio >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE barbero_servicios (
    tenant_id                  BIGINT UNSIGNED NOT NULL,
    barbero_id                 BIGINT UNSIGNED NOT NULL,
    servicio_id                BIGINT UNSIGNED NOT NULL,
    duracion_minutos_override  SMALLINT UNSIGNED NULL COMMENT 'Si el barbero tarda distinto al estándar',
    precio_override            DECIMAL(10,2)   NULL,
    created_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (barbero_id, servicio_id),
    KEY idx_barbero_servicios_servicio (tenant_id, servicio_id),
    KEY idx_barbero_servicios_barbero (tenant_id, barbero_id),
    CONSTRAINT fk_barbero_servicios_barbero  FOREIGN KEY (tenant_id, barbero_id)  REFERENCES barberos (tenant_id, id)  ON DELETE CASCADE,
    CONSTRAINT fk_barbero_servicios_servicio FOREIGN KEY (tenant_id, servicio_id) REFERENCES servicios (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_barbero_servicios_duracion CHECK (duracion_minutos_override IS NULL OR duracion_minutos_override BETWEEN 1 AND 600),
    CONSTRAINT ck_barbero_servicios_precio   CHECK (precio_override IS NULL OR precio_override >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Servicios que puede realizar cada barbero (mismo tenant garantizado por FK compuesta)';

CREATE TABLE horarios_barberos (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    barbero_id     BIGINT UNSIGNED NOT NULL,
    sucursal_id    BIGINT UNSIGNED NOT NULL,
    dia_semana     TINYINT UNSIGNED NOT NULL COMMENT 'ISO-8601: 1 = lunes … 7 = domingo',
    hora_inicio    TIME            NOT NULL COMMENT 'Hora local de la sucursal',
    hora_fin       TIME            NOT NULL,
    vigente_desde  DATE            NULL,
    vigente_hasta  DATE            NULL,
    activo         TINYINT(1)      NOT NULL DEFAULT 1,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_horarios_barberos_tenant_id (tenant_id, id),
    KEY idx_horarios_barberos_dia (tenant_id, barbero_id, dia_semana, activo),
    KEY idx_horarios_barberos_sucursal (tenant_id, sucursal_id, dia_semana),
    CONSTRAINT fk_horarios_barberos_barbero  FOREIGN KEY (tenant_id, barbero_id)  REFERENCES barberos (tenant_id, id)   ON DELETE CASCADE,
    CONSTRAINT fk_horarios_barberos_sucursal FOREIGN KEY (tenant_id, sucursal_id) REFERENCES sucursales (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT ck_horarios_barberos_dia      CHECK (dia_semana BETWEEN 1 AND 7),
    CONSTRAINT ck_horarios_barberos_rango    CHECK (hora_fin > hora_inicio),
    CONSTRAINT ck_horarios_barberos_vigencia CHECK (vigente_desde IS NULL OR vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Jornada semanal. Los descansos se expresan como varios tramos por día (p. ej. 09-13 y 14-19)';

CREATE TABLE bloqueos_agenda (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NOT NULL,
    barbero_id    BIGINT UNSIGNED NULL COMMENT 'NULL = afecta a toda la sucursal',
    sucursal_id   BIGINT UNSIGNED NULL COMMENT 'NULL = afecta a todas las sucursales del barbero/tenant',
    tipo          ENUM('descanso','vacaciones','ausencia','feriado','bloqueo') NOT NULL,
    inicio_utc    DATETIME        NOT NULL,
    fin_utc       DATETIME        NOT NULL,
    motivo        VARCHAR(255)    NULL,
    estado        ENUM('solicitado','aprobado','rechazado') NOT NULL DEFAULT 'aprobado',
    creado_por    BIGINT UNSIGNED NULL,
    aprobado_por  BIGINT UNSIGNED NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_bloqueos_agenda_tenant_id (tenant_id, id),
    KEY idx_bloqueos_barbero_rango (tenant_id, barbero_id, estado, inicio_utc, fin_utc),
    KEY idx_bloqueos_sucursal_rango (tenant_id, sucursal_id, estado, inicio_utc, fin_utc),
    KEY idx_bloqueos_creado_por (creado_por),
    KEY idx_bloqueos_aprobado_por (aprobado_por),
    CONSTRAINT fk_bloqueos_tenant   FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bloqueos_barbero  FOREIGN KEY (tenant_id, barbero_id)  REFERENCES barberos (tenant_id, id)   ON DELETE RESTRICT,
    CONSTRAINT fk_bloqueos_sucursal FOREIGN KEY (tenant_id, sucursal_id) REFERENCES sucursales (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_bloqueos_creado_por   FOREIGN KEY (creado_por)   REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT fk_bloqueos_aprobado_por FOREIGN KEY (aprobado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT ck_bloqueos_rango CHECK (fin_utc > inicio_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Excepciones a la jornada: vacaciones, ausencias, feriados, bloqueos puntuales';

CREATE TABLE politicas_reserva (
    id                              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id                       BIGINT UNSIGNED NOT NULL,
    sucursal_id                     BIGINT UNSIGNED NULL COMMENT 'NULL = política por defecto del tenant',
    intervalo_minutos               SMALLINT UNSIGNED NOT NULL DEFAULT 15 COMMENT 'Granularidad de horarios ofrecidos',
    margen_entre_citas_minutos      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    anticipacion_minima_minutos     INT UNSIGNED    NOT NULL DEFAULT 60,
    horizonte_maximo_dias           SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    cancelacion_limite_minutos      INT UNSIGNED    NOT NULL DEFAULT 120 COMMENT 'Minutos antes del inicio en que el cliente aún puede cancelar',
    reprogramacion_limite_minutos   INT UNSIGNED    NOT NULL DEFAULT 120,
    max_reprogramaciones            TINYINT UNSIGNED NOT NULL DEFAULT 2,
    max_reservas_activas_cliente    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    confirmacion_automatica         TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1 = nace confirmada; 0 = nace pendiente',
    permite_cualquier_barbero       TINYINT(1)      NOT NULL DEFAULT 1,
    texto_politica_cancelacion      VARCHAR(1000)   NULL,
    -- Unicidad (tenant, sucursal) tratando NULL como "por defecto"
    sucursal_scope                  BIGINT UNSIGNED AS (IFNULL(sucursal_id, 0)) VIRTUAL,
    created_at                      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_politicas_reserva_scope (tenant_id, sucursal_scope),
    KEY idx_politicas_reserva_sucursal (tenant_id, sucursal_id),
    CONSTRAINT fk_politicas_reserva_tenant   FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_politicas_reserva_sucursal FOREIGN KEY (tenant_id, sucursal_id) REFERENCES sucursales (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT ck_politicas_reserva_intervalo CHECK (intervalo_minutos BETWEEN 5 AND 120),
    CONSTRAINT ck_politicas_reserva_horizonte CHECK (horizonte_maximo_dias BETWEEN 1 AND 365)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================
-- BARBERAPP · Migración 0003 · Clientes
-- clientes (persona), cliente_tenants (relación con cada barbería) y favoritos.
-- ============================================================

CREATE TABLE clientes (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id        BIGINT UNSIGNED NULL COMMENT 'NULL = cliente registrado en mostrador sin cuenta',
    nombres           VARCHAR(100)    NOT NULL,
    apellidos         VARCHAR(100)    NULL,
    telefono          VARCHAR(20)     NULL,
    email             VARCHAR(190)    NULL,
    fecha_nacimiento  DATE            NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at        DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_clientes_usuario (usuario_id),
    KEY idx_clientes_telefono (telefono),
    KEY idx_clientes_email (email),
    CONSTRAINT fk_clientes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE RESTRICT,
    CONSTRAINT ck_clientes_telefono CHECK (telefono IS NULL OR REGEXP_LIKE(telefono, '^\\+?[0-9]{6,15}$'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Persona que reserva. Una barbería solo ve clientes vinculados mediante cliente_tenants';

CREATE TABLE cliente_tenants (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id             BIGINT UNSIGNED NOT NULL,
    cliente_id            BIGINT UNSIGNED NOT NULL,
    origen                ENUM('marketplace','enlace','qr','panel','app') NOT NULL DEFAULT 'marketplace',
    notas_internas        VARCHAR(2000)   NULL COMMENT 'Observaciones autorizadas del personal; no visibles al cliente',
    bloqueado             TINYINT(1)      NOT NULL DEFAULT 0,
    acepta_marketing      TINYINT(1)      NOT NULL DEFAULT 0,
    primera_visita_en     DATETIME        NULL,
    ultima_visita_en      DATETIME        NULL,
    total_reservas        INT UNSIGNED    NOT NULL DEFAULT 0,
    total_inasistencias   INT UNSIGNED    NOT NULL DEFAULT 0,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_cliente_tenants_tenant_cliente (tenant_id, cliente_id),
    UNIQUE KEY uk_cliente_tenants_tenant_id (tenant_id, id),
    KEY idx_cliente_tenants_cliente (cliente_id),
    KEY idx_cliente_tenants_ultima_visita (tenant_id, ultima_visita_en),
    CONSTRAINT fk_cliente_tenants_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id)  ON DELETE RESTRICT,
    CONSTRAINT fk_cliente_tenants_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE favoritos (
    cliente_id  BIGINT UNSIGNED NOT NULL,
    tenant_id   BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cliente_id, tenant_id),
    KEY idx_favoritos_tenant (tenant_id),
    CONSTRAINT fk_favoritos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id) ON DELETE CASCADE,
    CONSTRAINT fk_favoritos_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================
-- BARBERAPP · Migración 0004 · Reservas
-- reservas, reserva_servicios (detalle con precios congelados) y
-- reserva_eventos (historial auditable inmutable).
--
-- Prevención de doble reserva (Fase 5): toda operación que cree o mueva una
-- reserva bloquea la fila del barbero con SELECT ... FOR UPDATE y verifica
-- solapamientos dentro de la misma transacción. MySQL no tiene restricciones
-- de exclusión por rangos, por eso el índice idx_reservas_agenda acelera esa
-- verificación (barbero + ocupa_agenda + rango).
-- ============================================================

CREATE TABLE reservas (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id                 BIGINT UNSIGNED NOT NULL,
    codigo                    CHAR(10)        NOT NULL COMMENT 'Código del comprobante mostrado al cliente',
    sucursal_id               BIGINT UNSIGNED NOT NULL,
    barbero_id                BIGINT UNSIGNED NOT NULL,
    cliente_id                BIGINT UNSIGNED NOT NULL,
    estado                    ENUM('pendiente','confirmada','completada','cancelada','no_asistio') NOT NULL DEFAULT 'pendiente',
    inicio_utc                DATETIME        NOT NULL,
    fin_utc                   DATETIME        NOT NULL,
    zona_horaria              VARCHAR(64)     NOT NULL COMMENT 'Zona de la sucursal al momento de reservar',
    duracion_total_minutos    SMALLINT UNSIGNED NOT NULL,
    precio_total              DECIMAL(10,2)   NOT NULL COMMENT 'Importe previsto; no equivale a ingreso cobrado',
    moneda                    CHAR(3)         NOT NULL DEFAULT 'PEN',
    canal                     ENUM('marketplace','enlace','qr','panel','app') NOT NULL,
    barbero_asignado_auto     TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = el cliente eligió "cualquier barbero"',
    notas_cliente             VARCHAR(500)    NULL,
    notas_internas            VARCHAR(1000)   NULL,
    confirmada_en             DATETIME        NULL,
    asistencia_confirmada_en  DATETIME        NULL COMMENT 'El cliente confirmó que asistirá',
    atencion_iniciada_en      DATETIME        NULL,
    atencion_finalizada_en    DATETIME        NULL,
    cancelada_en              DATETIME        NULL,
    cancelada_por             BIGINT UNSIGNED NULL,
    motivo_cancelacion        VARCHAR(255)    NULL,
    reprogramaciones          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    version                   INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT 'Control de concurrencia optimista',
    creado_por                BIGINT UNSIGNED NULL,
    ocupa_agenda              TINYINT(1) AS (IF(estado IN ('pendiente','confirmada'), 1, 0)) VIRTUAL,
    created_at                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_reservas_codigo (codigo),
    UNIQUE KEY uk_reservas_tenant_id (tenant_id, id),
    KEY idx_reservas_agenda (barbero_id, ocupa_agenda, inicio_utc, fin_utc),
    KEY idx_reservas_barbero_fecha (tenant_id, barbero_id, inicio_utc),
    KEY idx_reservas_sucursal_fecha (tenant_id, sucursal_id, inicio_utc),
    KEY idx_reservas_estado_fecha (tenant_id, estado, inicio_utc),
    KEY idx_reservas_cliente (cliente_id, inicio_utc),
    KEY idx_reservas_cliente_tenant (tenant_id, cliente_id),
    KEY idx_reservas_cancelada_por (cancelada_por),
    KEY idx_reservas_creado_por (creado_por),
    CONSTRAINT fk_reservas_sucursal FOREIGN KEY (tenant_id, sucursal_id) REFERENCES sucursales (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_reservas_barbero  FOREIGN KEY (tenant_id, barbero_id)  REFERENCES barberos (tenant_id, id)   ON DELETE RESTRICT,
    -- El cliente debe estar vinculado a ESTA barbería (cliente_tenants)
    CONSTRAINT fk_reservas_cliente  FOREIGN KEY (tenant_id, cliente_id)  REFERENCES cliente_tenants (tenant_id, cliente_id) ON DELETE RESTRICT,
    CONSTRAINT fk_reservas_cancelada_por FOREIGN KEY (cancelada_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT fk_reservas_creado_por    FOREIGN KEY (creado_por)    REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT ck_reservas_rango    CHECK (fin_utc > inicio_utc),
    CONSTRAINT ck_reservas_duracion CHECK (duracion_total_minutos > 0),
    CONSTRAINT ck_reservas_precio   CHECK (precio_total >= 0),
    CONSTRAINT ck_reservas_cancelacion CHECK (estado <> 'cancelada' OR cancelada_en IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE reserva_servicios (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    reserva_id        BIGINT UNSIGNED NOT NULL,
    servicio_id       BIGINT UNSIGNED NOT NULL,
    orden             TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Orden de atención consecutiva',
    nombre_servicio   VARCHAR(120)    NOT NULL COMMENT 'Copia al momento de reservar',
    duracion_minutos  SMALLINT UNSIGNED NOT NULL,
    precio            DECIMAL(10,2)   NOT NULL,
    inicio_utc        DATETIME        NOT NULL,
    fin_utc           DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_reserva_servicios_orden (reserva_id, orden),
    KEY idx_reserva_servicios_reserva (tenant_id, reserva_id),
    KEY idx_reserva_servicios_servicio (tenant_id, servicio_id),
    CONSTRAINT fk_reserva_servicios_reserva  FOREIGN KEY (tenant_id, reserva_id)  REFERENCES reservas (tenant_id, id)  ON DELETE CASCADE,
    CONSTRAINT fk_reserva_servicios_servicio FOREIGN KEY (tenant_id, servicio_id) REFERENCES servicios (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT ck_reserva_servicios_duracion CHECK (duracion_minutos > 0),
    CONSTRAINT ck_reserva_servicios_precio   CHECK (precio >= 0),
    CONSTRAINT ck_reserva_servicios_rango    CHECK (fin_utc > inicio_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE reserva_eventos (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    reserva_id           BIGINT UNSIGNED NOT NULL,
    tipo                 ENUM('creada','confirmada','reprogramada','cancelada','asistencia_confirmada',
                              'atencion_iniciada','completada','no_asistio','modificada','nota') NOT NULL,
    estado_anterior      VARCHAR(20)     NULL,
    estado_nuevo         VARCHAR(20)     NULL,
    inicio_anterior_utc  DATETIME        NULL,
    fin_anterior_utc     DATETIME        NULL,
    inicio_nuevo_utc     DATETIME        NULL,
    fin_nuevo_utc        DATETIME        NULL,
    barbero_anterior_id  BIGINT UNSIGNED NULL,
    barbero_nuevo_id     BIGINT UNSIGNED NULL,
    actor_tipo           ENUM('cliente','personal','sistema') NOT NULL,
    actor_usuario_id     BIGINT UNSIGNED NULL,
    datos                JSON            NULL,
    ip                   VARBINARY(16)   NULL COMMENT 'INET6_ATON()',
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reserva_eventos_reserva (tenant_id, reserva_id, created_at),
    KEY idx_reserva_eventos_tipo (tenant_id, tipo, created_at),
    KEY idx_reserva_eventos_actor (actor_usuario_id),
    CONSTRAINT fk_reserva_eventos_reserva FOREIGN KEY (tenant_id, reserva_id) REFERENCES reservas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_reserva_eventos_actor   FOREIGN KEY (actor_usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Historial inmutable: solo INSERT';

-- ============================================================
-- BARBERAPP · Migración 0005 · Notificaciones
-- dispositivos push, preferencias, plantillas y cola persistente de envíos.
-- ============================================================

CREATE TABLE dispositivos_push (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id     BIGINT UNSIGNED NOT NULL,
    plataforma     ENUM('android','ios','web') NOT NULL,
    proveedor      ENUM('fcm','apns','webpush') NOT NULL DEFAULT 'fcm',
    token          VARCHAR(1024)   NOT NULL,
    token_hash     CHAR(64)        NOT NULL COMMENT 'SHA-256 del token para unicidad',
    app_version    VARCHAR(20)     NULL,
    modelo         VARCHAR(80)     NULL,
    activo         TINYINT(1)      NOT NULL DEFAULT 1,
    ultimo_uso_en  DATETIME        NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_dispositivos_push_token (token_hash),
    KEY idx_dispositivos_push_usuario (usuario_id, activo),
    CONSTRAINT fk_dispositivos_push_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE preferencias_notificacion (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id    BIGINT UNSIGNED NOT NULL,
    tenant_id     BIGINT UNSIGNED NULL COMMENT 'NULL = preferencia global del usuario',
    canal         ENUM('push','email','interna','whatsapp','sms') NOT NULL,
    tipo          VARCHAR(50)     NOT NULL COMMENT 'confirmacion, recordatorio_24h, recordatorio_2h, cancelacion, reprogramacion, marketing',
    habilitado    TINYINT(1)      NOT NULL DEFAULT 1,
    tenant_scope  BIGINT UNSIGNED AS (IFNULL(tenant_id, 0)) VIRTUAL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_preferencias_notificacion (usuario_id, tenant_scope, canal, tipo),
    KEY idx_preferencias_notificacion_tenant (tenant_id),
    CONSTRAINT fk_preferencias_notificacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_preferencias_notificacion_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE plantillas_notificacion (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NULL COMMENT 'NULL = plantilla global de la plataforma',
    codigo        VARCHAR(60)     NOT NULL,
    canal         ENUM('push','email','interna','whatsapp','sms') NOT NULL,
    idioma        VARCHAR(10)     NOT NULL DEFAULT 'es-PE',
    asunto        VARCHAR(160)    NULL,
    cuerpo        TEXT            NOT NULL COMMENT 'Variables con sintaxis {{variable}}',
    variables     JSON            NULL,
    activo        TINYINT(1)      NOT NULL DEFAULT 1,
    version       INT UNSIGNED    NOT NULL DEFAULT 1,
    tenant_scope  BIGINT UNSIGNED AS (IFNULL(tenant_id, 0)) VIRTUAL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_plantillas_notificacion (tenant_scope, codigo, canal, idioma),
    KEY idx_plantillas_notificacion_tenant (tenant_id),
    CONSTRAINT fk_plantillas_notificacion_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE notificaciones (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id             BIGINT UNSIGNED NULL,
    usuario_id            BIGINT UNSIGNED NULL,
    reserva_id            BIGINT UNSIGNED NULL,
    plantilla_id          BIGINT UNSIGNED NULL,
    canal                 ENUM('push','email','interna','whatsapp','sms') NOT NULL,
    tipo                  VARCHAR(50)     NOT NULL,
    destino               VARCHAR(255)    NULL COMMENT 'Correo o teléfono; para push se resuelve por dispositivos_push',
    titulo                VARCHAR(160)    NULL,
    cuerpo                TEXT            NOT NULL,
    datos                 JSON            NULL COMMENT 'Payload adicional (deep link, acciones)',
    estado                ENUM('programada','procesando','enviada','fallida','cancelada') NOT NULL DEFAULT 'programada',
    programada_para_utc   DATETIME        NOT NULL,
    proximo_intento_utc   DATETIME        NULL,
    intentos              TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_intentos          TINYINT UNSIGNED NOT NULL DEFAULT 5,
    enviada_en            DATETIME        NULL,
    leida_en              DATETIME        NULL,
    ultimo_error          VARCHAR(1000)   NULL,
    dedupe_key            VARCHAR(191)    NULL COMMENT 'p. ej. reserva:123:recordatorio_24h:push',
    bloqueada_por         VARCHAR(64)     NULL COMMENT 'Identificador del worker que la tomó',
    bloqueada_en          DATETIME        NULL,
    created_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_notificaciones_dedupe (dedupe_key),
    KEY idx_notificaciones_cola (estado, proximo_intento_utc, programada_para_utc),
    KEY idx_notificaciones_bandeja (usuario_id, canal, leida_en),
    KEY idx_notificaciones_reserva (tenant_id, reserva_id),
    KEY idx_notificaciones_plantilla (plantilla_id),
    CONSTRAINT fk_notificaciones_tenant    FOREIGN KEY (tenant_id)    REFERENCES tenants (id)  ON DELETE CASCADE,
    CONSTRAINT fk_notificaciones_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_notificaciones_reserva   FOREIGN KEY (tenant_id, reserva_id) REFERENCES reservas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_notificaciones_plantilla FOREIGN KEY (plantilla_id) REFERENCES plantillas_notificacion (id) ON DELETE SET NULL,
    CONSTRAINT ck_notificaciones_reserva_tenant CHECK (reserva_id IS NULL OR tenant_id IS NOT NULL),
    CONSTRAINT ck_notificaciones_intentos CHECK (intentos <= max_intentos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Cola persistente procesada por worker PHP vía cron';

-- ============================================================
-- BARBERAPP · Migración 0006 · Sistema
-- auditoría, configuraciones, eventos de webhooks y claves de idempotencia.
-- ============================================================

CREATE TABLE auditoria (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NULL,
    usuario_id        BIGINT UNSIGNED NULL,
    accion            VARCHAR(100)    NOT NULL COMMENT 'p. ej. auth.login_fallido, servicio.actualizado',
    entidad           VARCHAR(64)     NULL,
    entidad_id        BIGINT UNSIGNED NULL,
    resultado         ENUM('exito','denegado','error') NOT NULL DEFAULT 'exito',
    datos_anteriores  JSON            NULL,
    datos_nuevos      JSON            NULL,
    ip                VARBINARY(16)   NULL,
    user_agent        VARCHAR(255)    NULL,
    request_id        VARCHAR(64)     NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_auditoria_tenant_fecha (tenant_id, created_at),
    KEY idx_auditoria_usuario_fecha (usuario_id, created_at),
    KEY idx_auditoria_entidad (entidad, entidad_id),
    KEY idx_auditoria_accion_fecha (accion, created_at),
    KEY idx_auditoria_resultado (resultado, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Bitácora inmutable (solo INSERT). Sin FKs a propósito: debe conservarse aunque se eliminen usuarios o tenants';

CREATE TABLE configuraciones (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id     BIGINT UNSIGNED NULL COMMENT 'NULL = parámetro global de la plataforma',
    clave         VARCHAR(100)    NOT NULL,
    valor         JSON            NOT NULL,
    descripcion   VARCHAR(255)    NULL,
    es_publica    TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = puede exponerse al frontend',
    actualizado_por BIGINT UNSIGNED NULL,
    tenant_scope  BIGINT UNSIGNED AS (IFNULL(tenant_id, 0)) VIRTUAL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_configuraciones_clave (tenant_scope, clave),
    KEY idx_configuraciones_tenant (tenant_id),
    KEY idx_configuraciones_actualizado_por (actualizado_por),
    CONSTRAINT fk_configuraciones_tenant  FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_configuraciones_usuario FOREIGN KEY (actualizado_por) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE webhook_eventos (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    proveedor           VARCHAR(50)     NOT NULL COMMENT 'p. ej. pasarela de pagos, fcm, whatsapp',
    evento_id_externo   VARCHAR(191)    NOT NULL,
    tipo                VARCHAR(100)    NOT NULL,
    tenant_id           BIGINT UNSIGNED NULL,
    payload             JSON            NOT NULL,
    firma_valida        TINYINT(1)      NOT NULL DEFAULT 0,
    estado              ENUM('recibido','procesado','fallido','ignorado') NOT NULL DEFAULT 'recibido',
    intentos            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_error        VARCHAR(1000)   NULL,
    recibido_en         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    procesado_en        DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_webhook_eventos_externo (proveedor, evento_id_externo),
    KEY idx_webhook_eventos_estado (estado, recibido_en),
    KEY idx_webhook_eventos_tenant (tenant_id),
    CONSTRAINT fk_webhook_eventos_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Deduplica y registra webhooks entrantes';

CREATE TABLE idempotency_keys (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id        BIGINT UNSIGNED NULL,
    tenant_id         BIGINT UNSIGNED NULL,
    clave             VARCHAR(100)    NOT NULL COMMENT 'Cabecera Idempotency-Key enviada por el cliente',
    metodo            VARCHAR(10)     NOT NULL,
    endpoint          VARCHAR(191)    NOT NULL,
    request_hash      CHAR(64)        NOT NULL COMMENT 'SHA-256 del cuerpo para detectar reutilización con otro contenido',
    estado            ENUM('procesando','completada','fallida') NOT NULL DEFAULT 'procesando',
    codigo_respuesta  SMALLINT UNSIGNED NULL,
    respuesta         JSON            NULL,
    recurso_tipo      VARCHAR(64)     NULL,
    recurso_id        BIGINT UNSIGNED NULL,
    usuario_scope     BIGINT UNSIGNED AS (IFNULL(usuario_id, 0)) VIRTUAL,
    expira_en         DATETIME        NOT NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_idempotency_keys (usuario_scope, metodo, endpoint, clave),
    KEY idx_idempotency_keys_expira (expira_en),
    KEY idx_idempotency_keys_tenant (tenant_id),
    CONSTRAINT fk_idempotency_keys_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_idempotency_keys_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Evita reservas duplicadas por reintentos de red';

-- Registra las migraciones incluidas para que `php bin/console migrate` no las repita.
INSERT INTO migraciones (migracion, checksum, lote) VALUES
    ('0001_nucleo_saas.sql', '09a74ba54a5988c50081099bd8610fdb78a2b380636abdb7b516239b05a7d194', 1),
    ('0002_gestion_barberias.sql', '8a2aa3447a7f65b84ad8a72b422f1235f204bd6f5d90165cdb5fb62d4981e4f4', 1),
    ('0003_clientes.sql', '903e8009021f10b125571bc1dc09e40cf022f739a6eee2754e34eb798cb88696', 1),
    ('0004_reservas.sql', '4dbc2b30266dbb391cb18887d8c90548e4b38c976da974d406c0713cf30afe87', 1),
    ('0005_notificaciones.sql', '0414ab60959f3fd6196ca4f4741c3e751258327c1d63d3ee9620861ecd40b772', 1),
    ('0006_sistema.sql', '611f9c0c55f5b38ce5499ed8c611655ee8c705fa86e4df32c9b7dcc28e50d903', 1);
