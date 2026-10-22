-- ============================================================
-- BARBERAPP · Migración 0012 · Panel del barbero (Fase 7)
--
--   - bloqueos_agenda: respuesta del encargado al aprobar o rechazar una solicitud del
--     barbero (p. ej. "Ese sábado hay mucha demanda") y fecha de esa respuesta. Así el
--     barbero sabe por qué se rechazó y cuándo se atendió su pedido.
--   - Índice para las solicitudes pendientes de la barbería (aviso en el panel) y
--     para "mis solicitudes" del barbero.
--   - Los ajustes del equipo (si las ausencias de los barberos requieren aprobación y si
--     el barbero puede editar su propia jornada) se guardan en `configuraciones` por
--     barbería; sin fila se aplican los valores seguros por defecto (requiere aprobación,
--     no edita su jornada). No necesitan columnas nuevas.
-- ============================================================

ALTER TABLE bloqueos_agenda
    ADD COLUMN respuesta     VARCHAR(255) NULL COMMENT 'Comentario de quien aprobó o rechazó la solicitud' AFTER aprobado_por,
    ADD COLUMN respondido_en DATETIME     NULL COMMENT 'UTC; cuándo se aprobó o rechazó la solicitud' AFTER respuesta,
    ADD KEY idx_bloqueos_estado (tenant_id, estado, inicio_utc),
    ADD KEY idx_bloqueos_solicitante (tenant_id, creado_por, estado);

-- Las solicitudes ya atendidas antes de esta migración toman su fecha de la última actualización.
UPDATE bloqueos_agenda SET respondido_en = updated_at
 WHERE estado IN ('aprobado', 'rechazado') AND aprobado_por IS NOT NULL AND respondido_en IS NULL;
