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
