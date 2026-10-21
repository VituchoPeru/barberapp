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
