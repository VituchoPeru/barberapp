-- ============================================================
-- BARBERAPP · Migración 0009 · Servicios, barberos y horarios (Fase 4)
-- Descansos semanales recurrentes de cada barbero (p. ej. almuerzo de 13:00 a 14:00)
-- y datos de presentación del barbero en el panel y el marketplace.
--
-- Modelo de agenda resultante (todo en hora local de la sucursal salvo bloqueos):
--   horarios_sucursales  horario comercial (el barbero nunca trabaja fuera de él)
--   horarios_barberos    jornada semanal por tramos y sucursal
--   descansos_barberos   descansos recurrentes dentro de la jornada
--   bloqueos_agenda      excepciones puntuales en UTC: vacaciones, ausencias, feriados, bloqueos
-- ============================================================

CREATE TABLE descansos_barberos (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id      BIGINT UNSIGNED NOT NULL,
    barbero_id     BIGINT UNSIGNED NOT NULL,
    dia_semana     TINYINT UNSIGNED NOT NULL COMMENT 'ISO-8601: 1 = lunes … 7 = domingo',
    hora_inicio    TIME            NOT NULL COMMENT 'Hora local de la sucursal donde trabaja ese día',
    hora_fin       TIME            NOT NULL,
    motivo         VARCHAR(80)     NULL COMMENT 'Texto interno, p. ej. "Almuerzo"',
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_descansos_barberos_tenant_id (tenant_id, id),
    KEY idx_descansos_barberos_dia (tenant_id, barbero_id, dia_semana),
    CONSTRAINT fk_descansos_barberos_barbero FOREIGN KEY (tenant_id, barbero_id) REFERENCES barberos (tenant_id, id) ON DELETE CASCADE,
    CONSTRAINT ck_descansos_barberos_dia   CHECK (dia_semana BETWEEN 1 AND 7),
    CONSTRAINT ck_descansos_barberos_rango CHECK (hora_fin > hora_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Descansos semanales recurrentes; se descuentan de la jornada al calcular disponibilidad';

ALTER TABLE barberos
    ADD COLUMN especialidad VARCHAR(120) NULL COMMENT 'Texto corto visible, p. ej. "Degradados y diseños"' AFTER nombre_publico,
    ADD CONSTRAINT ck_barberos_nombre CHECK (CHAR_LENGTH(TRIM(nombre_publico)) >= 2);

ALTER TABLE servicios
    ADD CONSTRAINT ck_servicios_nombre CHECK (CHAR_LENGTH(TRIM(nombre)) >= 2);

-- Bloqueos por rango: el listado del panel filtra por tenant + rango sin barbero concreto.
ALTER TABLE bloqueos_agenda
    ADD KEY idx_bloqueos_tenant_rango (tenant_id, inicio_utc, fin_utc);
