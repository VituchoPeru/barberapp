-- ============================================================
-- BARBERAPP · Migración 0015 · Planes y monetización (Fase 10)
--
--   - ordenes_pago: cada intento de pago en línea que inicia una barbería (renovar,
--     contratar o subir de plan). Guarda el importe calculado por el servidor, la
--     pasarela y su referencia, y el estado. Solo cuando la pasarela confirma el pago
--     (webhook verificado + consulta del pago) se registra el pago en pagos_saas y se
--     aplica a la suscripción. Nunca se guardan datos de tarjeta.
--   - pagos_saas.orden_id: el pago confirmado apunta a la orden que lo originó.
--   - suscripciones.plan_programado_id / ciclo_programado / precio_programado /
--     cambio_programado_en: bajar de plan durante un periodo pagado se aplica al
--     terminar ese periodo (el cron lo aplica).
--   - suscripcion_eventos.tipo: + 'cambio_programado' y 'prueba_automatica'.
--   - tenants.restringido_en: la barbería sin suscripción vigente pasado el periodo de
--     gracia deja de recibir reservas nuevas y sale del marketplace (lo marca el cron y se
--     levanta al pagar o al asignarle una suscripción). Las citas existentes se mantienen.
-- ============================================================

CREATE TABLE ordenes_pago (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id        BIGINT UNSIGNED NOT NULL,
    codigo           CHAR(24)        NOT NULL COMMENT 'Referencia pública (external_reference en la pasarela)',
    tipo             ENUM('renovacion','contratacion','mejora') NOT NULL COMMENT 'renovacion = mismo plan; contratacion = sin suscripción vigente; mejora = subir de plan a mitad del periodo',
    suscripcion_id   BIGINT UNSIGNED NULL COMMENT 'Suscripción sobre la que se aplica (NULL si no hay ninguna)',
    plan_id          BIGINT UNSIGNED NOT NULL,
    ciclo            ENUM('mensual','anual') NOT NULL,
    periodos         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    monto            DECIMAL(10,2)   NOT NULL,
    moneda           CHAR(3)         NOT NULL DEFAULT 'PEN',
    detalle          VARCHAR(255)    NOT NULL COMMENT 'Concepto que ve el cliente en la pasarela',
    estado           ENUM('pendiente','pagada','fallida','expirada','anulada') NOT NULL DEFAULT 'pendiente',
    pasarela         VARCHAR(30)     NOT NULL,
    checkout_id      VARCHAR(120)    NULL COMMENT 'ID de la preferencia o sesión de pago en la pasarela',
    checkout_url     VARCHAR(500)    NULL,
    pago_externo_id  VARCHAR(120)    NULL COMMENT 'ID del pago en la pasarela',
    pago_saas_id     BIGINT UNSIGNED NULL,
    solicitado_por   BIGINT UNSIGNED NULL,
    error            VARCHAR(500)    NULL,
    expira_en        DATETIME        NOT NULL,
    pagada_en        DATETIME        NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ordenes_pago_codigo (codigo),
    UNIQUE KEY uk_ordenes_pago_tenant_id (tenant_id, id),
    UNIQUE KEY uk_ordenes_pago_externo (pasarela, pago_externo_id),
    KEY idx_ordenes_pago_tenant_estado (tenant_id, estado, created_at),
    KEY idx_ordenes_pago_estado_expira (estado, expira_en),
    KEY idx_ordenes_pago_plan (plan_id),
    KEY idx_ordenes_pago_suscripcion (tenant_id, suscripcion_id),
    KEY idx_ordenes_pago_pago (pago_saas_id),
    KEY idx_ordenes_pago_solicitado (solicitado_por),
    CONSTRAINT fk_ordenes_pago_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ordenes_pago_plan FOREIGN KEY (plan_id) REFERENCES planes (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ordenes_pago_suscripcion FOREIGN KEY (tenant_id, suscripcion_id) REFERENCES suscripciones (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_ordenes_pago_pago FOREIGN KEY (pago_saas_id) REFERENCES pagos_saas (id) ON DELETE SET NULL,
    CONSTRAINT fk_ordenes_pago_solicitado FOREIGN KEY (solicitado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT ck_ordenes_pago_monto CHECK (monto > 0),
    CONSTRAINT ck_ordenes_pago_periodos CHECK (periodos BETWEEN 1 AND 24),
    CONSTRAINT ck_ordenes_pago_moneda CHECK (REGEXP_LIKE(moneda, '^[A-Z]{3}$', 'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Intentos de pago en línea de las barberías (Fase 10)';

ALTER TABLE pagos_saas
    ADD COLUMN orden_id BIGINT UNSIGNED NULL COMMENT 'Orden de pago en línea que originó el pago' AFTER suscripcion_id,
    ADD KEY idx_pagos_saas_orden (orden_id),
    ADD CONSTRAINT fk_pagos_saas_orden FOREIGN KEY (orden_id) REFERENCES ordenes_pago (id) ON DELETE SET NULL;

ALTER TABLE suscripciones
    ADD COLUMN plan_programado_id   BIGINT UNSIGNED NULL COMMENT 'Plan que regirá al terminar el periodo actual' AFTER renovacion_automatica,
    ADD COLUMN ciclo_programado     ENUM('mensual','anual') NULL AFTER plan_programado_id,
    ADD COLUMN precio_programado    DECIMAL(10,2) NULL AFTER ciclo_programado,
    ADD COLUMN cambio_programado_en DATETIME NULL COMMENT 'UTC; cuándo aplicar el cambio (fin del periodo pagado)' AFTER precio_programado,
    ADD KEY idx_suscripciones_cambio (cambio_programado_en),
    ADD KEY idx_suscripciones_plan_programado (plan_programado_id),
    ADD CONSTRAINT fk_suscripciones_plan_programado FOREIGN KEY (plan_programado_id) REFERENCES planes (id) ON DELETE RESTRICT,
    ADD CONSTRAINT ck_suscripciones_programado CHECK (
        (plan_programado_id IS NULL AND ciclo_programado IS NULL AND precio_programado IS NULL AND cambio_programado_en IS NULL)
        OR (plan_programado_id IS NOT NULL AND ciclo_programado IS NOT NULL AND precio_programado >= 0 AND cambio_programado_en IS NOT NULL));

ALTER TABLE suscripcion_eventos
    MODIFY COLUMN tipo ENUM('alta','cambio_plan','renovacion','pago','suspension','reactivacion','cancelacion','vencimiento',
                            'cambio_programado','prueba_automatica') NOT NULL;

ALTER TABLE tenants
    ADD COLUMN restringido_en DATETIME NULL COMMENT 'UTC; sin suscripción vigente pasado el periodo de gracia: sin reservas nuevas ni marketplace' AFTER motivo_suspension,
    ADD KEY idx_tenants_restringido (restringido_en);

-- ---------- Permiso nuevo: pagar y cambiar el plan desde el panel (solo propietario)
INSERT IGNORE INTO permisos (codigo, modulo, descripcion)
VALUES ('negocio.suscripcion.gestionar', 'negocio', 'Pagar, renovar y cambiar el plan de la barbería');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'negocio.suscripcion.gestionar'
 WHERE r.codigo = 'propietario';
