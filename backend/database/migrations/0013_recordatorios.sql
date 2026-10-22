-- ============================================================
-- BARBERAPP · Migración 0013 · Recordatorios automáticos y envío real de avisos (Fase 8)
--
--   - notificaciones: nuevo estado `omitida` (no se envió por preferencia del usuario,
--     canal desactivado por la barbería o usuario sin dispositivos). Así las métricas
--     separan "falló el envío" de "no correspondía enviarlo". Índices para las métricas
--     del panel y para la bandeja ordenada por fecha.
--   - notificacion_envios: registro de cada intento de envío (resultado, proveedor,
--     código de respuesta, error y duración). Es el "registro de errores" de la cola.
--   - dispositivos_push: motivo y fecha de desactivación (token rechazado por FCM).
--   - reservas: índice (estado, inicio_utc) para que el planificador encuentre las citas
--     próximas de todas las barberías sin recorrer la tabla.
--   - preferencias_notificacion: los tipos pasan a dos categorías que el cliente entiende:
--     `recordatorios` y `cambios` (confirmación, cancelación y reprogramación), más
--     `agenda` para los avisos del personal. Las filas antiguas se convierten conservando
--     la opción más restrictiva (si desactivó alguno, la categoría queda desactivada).
--   - permiso notificaciones.gestionar (propietario y administrador): reglas de envío.
-- ============================================================

ALTER TABLE notificaciones
    MODIFY estado ENUM('programada','procesando','enviada','fallida','cancelada','omitida') NOT NULL DEFAULT 'programada',
    ADD KEY idx_notificaciones_metricas (tenant_id, tipo, programada_para_utc),
    ADD KEY idx_notificaciones_bandeja_fecha (usuario_id, canal, estado, created_at);

CREATE TABLE notificacion_envios (
    id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    notificacion_id   BIGINT UNSIGNED  NOT NULL,
    tenant_id         BIGINT UNSIGNED  NULL,
    intento           TINYINT UNSIGNED NOT NULL,
    canal             ENUM('push','email','interna','whatsapp','sms') NOT NULL,
    proveedor         VARCHAR(30)      NOT NULL COMMENT 'fcm, smtp, log, array, interna',
    resultado         ENUM('enviada','error_temporal','error_permanente','omitida') NOT NULL,
    codigo            VARCHAR(60)      NULL COMMENT 'Código de respuesta del proveedor (p. ej. UNREGISTERED, http_503)',
    detalle           VARCHAR(1000)    NULL,
    dispositivos      SMALLINT UNSIGNED NULL COMMENT 'Push: dispositivos a los que se intentó enviar',
    duracion_ms       INT UNSIGNED     NULL,
    created_at        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notificacion_envios_notificacion (notificacion_id),
    KEY idx_notificacion_envios_tenant (tenant_id, resultado, created_at),
    CONSTRAINT fk_notificacion_envios_notificacion FOREIGN KEY (notificacion_id) REFERENCES notificaciones (id) ON DELETE CASCADE,
    CONSTRAINT fk_notificacion_envios_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Intentos de envío de la cola de notificaciones (registro de errores y métricas)';

ALTER TABLE dispositivos_push
    ADD COLUMN desactivado_en     DATETIME     NULL COMMENT 'UTC; cuándo dejó de recibir avisos' AFTER activo,
    ADD COLUMN motivo_desactivado VARCHAR(120) NULL COMMENT 'p. ej. token rechazado por FCM (UNREGISTERED) o cierre de sesión' AFTER desactivado_en;

ALTER TABLE reservas
    ADD KEY idx_reservas_proximas (estado, inicio_utc);

-- ---------- Preferencias: tipos antiguos → categorías
INSERT IGNORE INTO preferencias_notificacion (usuario_id, tenant_id, canal, tipo, habilitado)
SELECT usuario_id, tenant_id, canal, 'recordatorios', MIN(habilitado)
  FROM preferencias_notificacion
 WHERE tipo IN ('recordatorio_24h', 'recordatorio_2h')
 GROUP BY usuario_id, tenant_id, canal;

INSERT IGNORE INTO preferencias_notificacion (usuario_id, tenant_id, canal, tipo, habilitado)
SELECT usuario_id, tenant_id, canal, 'cambios', MIN(habilitado)
  FROM preferencias_notificacion
 WHERE tipo IN ('confirmacion', 'cancelacion', 'reprogramacion')
 GROUP BY usuario_id, tenant_id, canal;

DELETE FROM preferencias_notificacion
 WHERE tipo IN ('recordatorio_24h', 'recordatorio_2h', 'confirmacion', 'cancelacion', 'reprogramacion');

ALTER TABLE preferencias_notificacion
    MODIFY tipo VARCHAR(50) NOT NULL COMMENT 'recordatorios, cambios (citas del cliente), agenda (avisos del personal), marketing';

-- ---------- Plantillas de la Fase 1 reemplazadas por el código genérico "recordatorio"
UPDATE plantillas_notificacion SET activo = 0
 WHERE tenant_id IS NULL AND codigo IN ('recordatorio_24h', 'recordatorio_2h');

-- ---------- Permiso para configurar las reglas de envío
INSERT IGNORE INTO permisos (codigo, modulo, descripcion)
VALUES ('notificaciones.gestionar', 'notificaciones', 'Reglas de recordatorios y avisos a clientes');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'notificaciones.gestionar'
 WHERE r.codigo IN ('propietario', 'administrador');
