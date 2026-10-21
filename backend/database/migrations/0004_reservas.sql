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
