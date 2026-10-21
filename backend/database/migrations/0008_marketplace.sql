-- ============================================================
-- BARBERAPP · Migración 0008 · Marketplace (Fase 3)
-- Catálogo global de tipos de servicio (para filtrar barberías por servicio),
-- datos de contacto públicos de la barbería y galería de fotografías.
-- ============================================================

CREATE TABLE tipos_servicio (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo      VARCHAR(40)     NOT NULL,
    nombre      VARCHAR(80)     NOT NULL,
    orden       SMALLINT        NOT NULL DEFAULT 0,
    activo      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tipos_servicio_codigo (codigo),
    CONSTRAINT ck_tipos_servicio_codigo CHECK (REGEXP_LIKE(codigo, '^[a-z0-9_]+$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Clasificación global de servicios (corte, barba…). Cada barbería nombra libremente sus servicios y los clasifica aquí';

INSERT INTO tipos_servicio (codigo, nombre, orden) VALUES
    ('corte', 'Corte de cabello', 1),
    ('barba', 'Barba', 2),
    ('corte_barba', 'Corte y barba', 3),
    ('afeitado', 'Afeitado clásico', 4),
    ('infantil', 'Corte infantil', 5),
    ('diseno', 'Diseños y líneas', 6),
    ('cejas', 'Cejas', 7),
    ('color', 'Color y tinte', 8),
    ('tratamiento', 'Tratamiento capilar', 9),
    ('facial', 'Cuidado facial', 10),
    ('otro', 'Otros servicios', 99);

ALTER TABLE servicios
    ADD COLUMN tipo_servicio_id BIGINT UNSIGNED NULL COMMENT 'Clasificación global para los filtros del marketplace' AFTER categoria_id,
    ADD KEY idx_servicios_tipo (tipo_servicio_id, activo),
    ADD CONSTRAINT fk_servicios_tipo FOREIGN KEY (tipo_servicio_id) REFERENCES tipos_servicio (id) ON DELETE RESTRICT;

ALTER TABLE tenants
    ADD COLUMN whatsapp   VARCHAR(20)  NULL COMMENT 'Número E.164 para contacto por WhatsApp' AFTER telefono,
    ADD COLUMN instagram  VARCHAR(30)  NULL COMMENT 'Usuario sin @' AFTER whatsapp,
    ADD COLUMN facebook   VARCHAR(100) NULL COMMENT 'Nombre de página (facebook.com/{valor})' AFTER instagram,
    ADD COLUMN tiktok     VARCHAR(30)  NULL COMMENT 'Usuario sin @' AFTER facebook,
    ADD COLUMN sitio_web  VARCHAR(255) NULL AFTER tiktok,
    ADD CONSTRAINT ck_tenants_whatsapp  CHECK (whatsapp IS NULL OR REGEXP_LIKE(whatsapp, '^\\+?[0-9]{6,15}$')),
    ADD CONSTRAINT ck_tenants_instagram CHECK (instagram IS NULL OR REGEXP_LIKE(instagram, '^[A-Za-z0-9._]{1,30}$', 'c')),
    ADD CONSTRAINT ck_tenants_facebook  CHECK (facebook IS NULL OR REGEXP_LIKE(facebook, '^[A-Za-z0-9.\\-]{1,100}$', 'c')),
    ADD CONSTRAINT ck_tenants_tiktok    CHECK (tiktok IS NULL OR REGEXP_LIKE(tiktok, '^[A-Za-z0-9._]{1,30}$', 'c')),
    ADD CONSTRAINT ck_tenants_sitio_web CHECK (sitio_web IS NULL OR REGEXP_LIKE(sitio_web, '^https?://', 'c'));

CREATE TABLE tenant_fotos (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id          BIGINT UNSIGNED NOT NULL,
    sucursal_id        BIGINT UNSIGNED NULL COMMENT 'NULL = foto general de la barbería',
    tipo               ENUM('logo','portada','galeria') NOT NULL DEFAULT 'galeria',
    ruta               VARCHAR(255)    NOT NULL COMMENT 'Ruta relativa dentro de MEDIA_PATH (tenants/{id}/{aleatorio}.webp)',
    ancho              SMALLINT UNSIGNED NOT NULL,
    alto               SMALLINT UNSIGNED NOT NULL,
    bytes              INT UNSIGNED    NOT NULL,
    texto_alternativo  VARCHAR(160)    NULL COMMENT 'Descripción accesible de la imagen',
    orden              SMALLINT        NOT NULL DEFAULT 0,
    subido_por         BIGINT UNSIGNED NULL,
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenant_fotos_tenant_id (tenant_id, id),
    UNIQUE KEY uk_tenant_fotos_ruta (ruta),
    KEY idx_tenant_fotos_tipo (tenant_id, tipo, orden),
    KEY idx_tenant_fotos_sucursal (tenant_id, sucursal_id),
    KEY idx_tenant_fotos_subido_por (subido_por),
    CONSTRAINT fk_tenant_fotos_tenant     FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_fotos_sucursal   FOREIGN KEY (tenant_id, sucursal_id) REFERENCES sucursales (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_tenant_fotos_subido_por FOREIGN KEY (subido_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT ck_tenant_fotos_ruta CHECK (REGEXP_LIKE(ruta, '^tenants/[0-9]+/[a-f0-9]{32}\\.(webp|jpg|png)$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Fotografías reales subidas por la barbería (logo, portada y galería). Se recodifican al subirse';

ALTER TABLE sucursales
    ADD KEY idx_sucursales_tenant_activo (tenant_id, activo);
