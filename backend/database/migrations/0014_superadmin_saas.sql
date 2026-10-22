-- ============================================================
-- BARBERAPP · Migración 0014 · Panel del superadministrador SaaS (Fase 9)
--
--   - tenants.es_demo: marca las barberías de demostración para que los indicadores
--     globales las muestren como tales (o las excluyan) en lugar de mezclarlas con datos
--     reales. tenants.nota_interna: comentario del equipo de BARBERAPP, nunca visible
--     para la barbería.
--   - usuarios.bloqueado_en / motivo_bloqueo: bloqueo de cuentas desde el panel SaaS
--     (estado = 'bloqueado' ya existía; ahora queda cuándo y por qué).
--   - suscripciones.terminada_en: momento en que la suscripción dejó de estar vigente
--     (vencida, suspendida o cancelada). Permite calcular churn y bajas por mes con
--     datos reales. Se completa para las filas existentes.
--   - suscripcion_eventos: historial de cada suscripción (alta, cambio de plan,
--     renovación, pago, suspensión, reactivación, cancelación, vencimiento).
--   - Rol de plataforma `operador`: gestiona barberías, suscripciones y pagos y consulta
--     reportes y auditoría, sin tocar planes, plantillas, configuración ni usuarios.
-- ============================================================

ALTER TABLE tenants
    ADD COLUMN es_demo      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = barbería de demostración (los indicadores la marcan o la excluyen)' AFTER publicado_marketplace,
    ADD COLUMN nota_interna VARCHAR(500) NULL COMMENT 'Nota del equipo de BARBERAPP; no se muestra a la barbería' AFTER motivo_suspension,
    ADD KEY idx_tenants_creado (created_at),
    ADD KEY idx_tenants_demo_estado (es_demo, estado);

-- Barberías creadas por el seeder de demostración (dominio reservado .test) en instalaciones existentes.
UPDATE tenants SET es_demo = 1 WHERE slug LIKE 'demo-%' AND email LIKE '%@barberapp.test';

ALTER TABLE usuarios
    ADD COLUMN bloqueado_en   DATETIME     NULL COMMENT 'UTC; cuándo se bloqueó la cuenta' AFTER estado,
    ADD COLUMN motivo_bloqueo VARCHAR(255) NULL AFTER bloqueado_en;

ALTER TABLE suscripciones
    ADD COLUMN terminada_en DATETIME NULL COMMENT 'UTC; cuándo dejó de estar vigente (vencida, suspendida o cancelada)' AFTER cancelada_en,
    ADD KEY idx_suscripciones_tenant_periodo (tenant_id, inicio_en, periodo_fin_en);

UPDATE suscripciones
   SET terminada_en = LEAST(periodo_fin_en, COALESCE(cancelada_en, periodo_fin_en))
 WHERE estado IN ('vencida', 'suspendida', 'cancelada') AND terminada_en IS NULL;

CREATE TABLE suscripcion_eventos (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    suscripcion_id       BIGINT UNSIGNED NOT NULL,
    tipo                 ENUM('alta','cambio_plan','renovacion','pago','suspension','reactivacion','cancelacion','vencimiento') NOT NULL,
    estado_anterior      VARCHAR(20)     NULL,
    estado_nuevo         VARCHAR(20)     NULL,
    plan_anterior_id     BIGINT UNSIGNED NULL,
    plan_nuevo_id        BIGINT UNSIGNED NULL,
    precio_anterior      DECIMAL(10,2)   NULL,
    precio_nuevo         DECIMAL(10,2)   NULL,
    periodo_fin_anterior DATETIME        NULL,
    periodo_fin_nuevo    DATETIME        NULL,
    pago_id              BIGINT UNSIGNED NULL,
    usuario_id           BIGINT UNSIGNED NULL COMMENT 'Quién lo hizo; NULL = proceso automático',
    notas                VARCHAR(500)    NULL,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_suscripcion_eventos_suscripcion (tenant_id, suscripcion_id, created_at),
    KEY idx_suscripcion_eventos_tipo_fecha (tipo, created_at),
    KEY idx_suscripcion_eventos_usuario (usuario_id),
    KEY idx_suscripcion_eventos_pago (pago_id),
    CONSTRAINT fk_suscripcion_eventos_suscripcion FOREIGN KEY (tenant_id, suscripcion_id) REFERENCES suscripciones (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT fk_suscripcion_eventos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT fk_suscripcion_eventos_pago FOREIGN KEY (pago_id) REFERENCES pagos_saas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Historial de cambios de cada suscripción SaaS';

INSERT INTO suscripcion_eventos (tenant_id, suscripcion_id, tipo, estado_nuevo, plan_nuevo_id, precio_nuevo, periodo_fin_nuevo, notas, created_at)
SELECT tenant_id, id, 'alta', estado, plan_id, precio_pactado, periodo_fin_en, 'Registrada antes del historial (migración 0014)', created_at
  FROM suscripciones;

-- Indicadores globales: reservas creadas por periodo en cada barbería.
ALTER TABLE reservas
    ADD KEY idx_reservas_tenant_creada (tenant_id, created_at);

-- ---------- Rol de plataforma "operador"
INSERT IGNORE INTO roles (codigo, nombre, descripcion, ambito)
VALUES ('operador', 'Operador de plataforma', 'Equipo de BARBERAPP: barberías, suscripciones y pagos', 'plataforma');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p
    ON p.codigo IN ('plataforma.tenants.gestionar', 'plataforma.suscripciones.gestionar', 'plataforma.pagos.gestionar',
                    'plataforma.reportes.ver', 'plataforma.auditoria.ver')
 WHERE r.codigo = 'operador';

-- ---------- Parámetro nuevo
INSERT IGNORE INTO configuraciones (tenant_id, clave, valor, descripcion, es_publica)
VALUES (NULL, 'plataforma.soporte_email', '""', 'Correo de soporte que se muestra a las barberías (vacío = sin configurar)', 1);
