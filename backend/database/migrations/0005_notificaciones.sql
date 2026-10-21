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
