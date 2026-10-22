-- ============================================================
-- BARBERAPP · Migración 0011 · Panel administrativo de la barbería (Fase 6)
--
--   - reserva_pagos: cobros registrados en mostrador para una cita. Son la ÚNICA fuente de
--     "ingresos registrados" del panel; el importe previsto de la reserva (precio_total) nunca
--     se presenta como ingreso. Un cobro no se borra: se anula con motivo.
--   - reserva_eventos.tipo: nuevos eventos pago_registrado y pago_anulado.
--   - reservas: índice para la agenda por sucursal y estado en un rango de fechas.
--   - permiso reservas.cobrar (propietario y administrador por reservas.*), también para
--     instalaciones existentes sin volver a ejecutar el seeder.
-- ============================================================

CREATE TABLE reserva_pagos (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    reserva_id        BIGINT UNSIGNED NOT NULL,
    monto             DECIMAL(10,2)   NOT NULL,
    moneda            CHAR(3)         NOT NULL DEFAULT 'PEN',
    metodo            ENUM('efectivo','tarjeta','yape','plin','transferencia','otro') NOT NULL,
    referencia        VARCHAR(100)    NULL COMMENT 'N.º de operación o comprobante (opcional)',
    pagado_en         DATETIME        NOT NULL COMMENT 'UTC',
    registrado_por    BIGINT UNSIGNED NULL,
    anulado_en        DATETIME        NULL,
    anulado_por       BIGINT UNSIGNED NULL,
    motivo_anulacion  VARCHAR(255)    NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reserva_pagos_reserva (tenant_id, reserva_id),
    KEY idx_reserva_pagos_fecha (tenant_id, pagado_en),
    KEY idx_reserva_pagos_registrado_por (registrado_por),
    KEY idx_reserva_pagos_anulado_por (anulado_por),
    CONSTRAINT fk_reserva_pagos_reserva       FOREIGN KEY (tenant_id, reserva_id) REFERENCES reservas (tenant_id, id) ON DELETE RESTRICT,
    CONSTRAINT fk_reserva_pagos_registrado_por FOREIGN KEY (registrado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT fk_reserva_pagos_anulado_por   FOREIGN KEY (anulado_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT ck_reserva_pagos_monto  CHECK (monto > 0 AND monto <= 99999.99),
    CONSTRAINT ck_reserva_pagos_anulado CHECK ((anulado_en IS NULL AND motivo_anulacion IS NULL) OR (anulado_en IS NOT NULL AND motivo_anulacion IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Cobros registrados por la barbería; fuente de los ingresos del panel';

ALTER TABLE reserva_eventos
    MODIFY tipo ENUM('creada','confirmada','reprogramada','cancelada','asistencia_confirmada',
                     'atencion_iniciada','completada','no_asistio','modificada','nota',
                     'pago_registrado','pago_anulado') NOT NULL;

ALTER TABLE reservas
    ADD KEY idx_reservas_sucursal_estado_fecha (tenant_id, sucursal_id, estado, inicio_utc);

INSERT IGNORE INTO permisos (codigo, modulo, descripcion)
VALUES ('reservas.cobrar', 'reservas', 'Registrar y anular cobros de citas');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'reservas.cobrar'
 WHERE r.codigo IN ('propietario', 'administrador');
