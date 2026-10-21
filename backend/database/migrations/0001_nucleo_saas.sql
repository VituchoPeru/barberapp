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
