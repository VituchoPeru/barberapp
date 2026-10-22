-- ============================================================
-- BARBERAPP · Migración 0010 · Motor de reservas (Fase 5)
--
-- Las tablas de reservas existen desde la migración 0004. Aquí solo se agrega lo que el
-- motor necesita para operar y auditar:
--   - quién canceló (cliente, personal o sistema) para reportes de cancelación;
--   - límites razonables en la política de reserva, validados también en la base;
--   - índice para el historial del cliente ordenado por fecha dentro de cada barbería.
--
-- Prevención de dobles reservas (recordatorio del diseño):
--   1. Toda operación que crea, mueve o libera un intervalo bloquea la fila del barbero
--      (SELECT … FOR UPDATE, candado estable de su agenda) en orden de id.
--   2. Dentro de la misma transacción (READ COMMITTED) se verifica que el intervalo cabe en
--      la jornada efectiva y que no se solapa con otra reserva activa del barbero.
--   3. idempotency_keys (0006) evita duplicados por reintentos de red: la clave se inserta en la
--      MISMA transacción que la reserva, así un reintento simultáneo espera y luego reproduce
--      la respuesta original.
-- ============================================================

ALTER TABLE reservas
    ADD COLUMN cancelada_por_tipo ENUM('cliente','personal','sistema') NULL
        COMMENT 'Origen de la cancelación (para reportes)' AFTER cancelada_por,
    ADD KEY idx_reservas_cliente_tenant_fecha (cliente_id, tenant_id, inicio_utc);

-- Reservas canceladas antes de esta migración: se infiere el origen desde el último evento.
UPDATE reservas r
   SET r.cancelada_por_tipo = COALESCE(
       (SELECT e.actor_tipo FROM reserva_eventos e
         WHERE e.tenant_id = r.tenant_id AND e.reserva_id = r.id AND e.tipo = 'cancelada'
         ORDER BY e.id DESC LIMIT 1), 'sistema')
 WHERE r.estado = 'cancelada';

ALTER TABLE reservas
    ADD CONSTRAINT ck_reservas_cancelada_por_tipo CHECK (estado <> 'cancelada' OR cancelada_por_tipo IS NOT NULL);

ALTER TABLE politicas_reserva
    ADD CONSTRAINT ck_politicas_reserva_margen        CHECK (margen_entre_citas_minutos <= 120),
    ADD CONSTRAINT ck_politicas_reserva_anticipacion  CHECK (anticipacion_minima_minutos <= 10080),
    ADD CONSTRAINT ck_politicas_reserva_cancelacion   CHECK (cancelacion_limite_minutos <= 10080),
    ADD CONSTRAINT ck_politicas_reserva_reprogramacion CHECK (reprogramacion_limite_minutos <= 10080),
    ADD CONSTRAINT ck_politicas_reserva_max_reprog    CHECK (max_reprogramaciones <= 10),
    ADD CONSTRAINT ck_politicas_reserva_max_activas   CHECK (max_reservas_activas_cliente BETWEEN 1 AND 20);
