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
