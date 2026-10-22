-- ============================================================
-- BARBERAPP · Migración 0016 · Seguridad y protección de datos (Fase 13)
--
--   - solicitudes_datos: registro de las solicitudes de derechos sobre datos personales
--     (acceso, rectificación, cancelación y oposición; Ley N.º 29733 y su reglamento).
--     Las que la persona resuelve sola desde la app (descargar sus datos, eliminar su
--     cuenta) quedan como "atendida" al instante; las que escribe a mano las responde el
--     equipo de BARBERAPP desde el panel de la plataforma dentro del plazo legal.
--     No guarda el correo en claro: solo su SHA-256, para acreditar la atención aunque la
--     cuenta se haya eliminado.
--   - usuarios.terminos_version / privacidad_version: versión de los textos legales que la
--     persona aceptó. Cuando se publica una versión nueva, la app pide aceptarla.
--   - tokens_usuario.tipo: + 'cambiar_email' (confirmar el correo nuevo antes de usarlo).
--   - Permiso nuevo plataforma.privacidad.gestionar (superadministrador y operador).
-- ============================================================

CREATE TABLE solicitudes_datos (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo           CHAR(12)        NOT NULL COMMENT 'Número de solicitud que ve la persona',
    usuario_id       BIGINT UNSIGNED NULL COMMENT 'NULL si la cuenta ya no existe',
    email_hash       CHAR(64)        NOT NULL COMMENT 'SHA-256 del correo al crear la solicitud (prueba de atención sin guardar el correo)',
    tipo             ENUM('acceso','rectificacion','cancelacion','oposicion') NOT NULL,
    origen           ENUM('autoservicio','formulario') NOT NULL COMMENT 'autoservicio = resuelta por la app al instante',
    estado           ENUM('recibida','en_proceso','atendida','rechazada') NOT NULL DEFAULT 'recibida',
    detalle          VARCHAR(2000)   NULL COMMENT 'Lo que pide la persona (formulario)',
    respuesta        VARCHAR(2000)   NULL,
    plazo_vence_en   DATE            NOT NULL COMMENT 'Fecha límite de respuesta (días hábiles según el tipo)',
    atendida_en      DATETIME        NULL,
    atendida_por     BIGINT UNSIGNED NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_solicitudes_datos_codigo (codigo),
    KEY idx_solicitudes_datos_usuario (usuario_id, created_at),
    KEY idx_solicitudes_datos_estado (estado, plazo_vence_en),
    CONSTRAINT fk_solicitudes_datos_usuario  FOREIGN KEY (usuario_id)   REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT fk_solicitudes_datos_atendida FOREIGN KEY (atendida_por) REFERENCES usuarios (id) ON DELETE SET NULL,
    CONSTRAINT ck_solicitudes_datos_atendida CHECK (estado NOT IN ('atendida','rechazada') OR atendida_en IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Derechos ARCO (Ley 29733): acceso, rectificación, cancelación y oposición';

ALTER TABLE usuarios
    ADD COLUMN terminos_version   VARCHAR(20) NULL COMMENT 'Versión de los términos de uso aceptada' AFTER acepto_terminos_en,
    ADD COLUMN privacidad_version VARCHAR(20) NULL COMMENT 'Versión de la política de privacidad aceptada' AFTER acepto_privacidad_en;

ALTER TABLE tokens_usuario
    MODIFY COLUMN tipo ENUM('verificar_email','restablecer_password','cambiar_email') NOT NULL;

-- ---------- Permiso nuevo: atender solicitudes sobre datos personales
INSERT IGNORE INTO permisos (codigo, modulo, descripcion)
VALUES ('plataforma.privacidad.gestionar', 'plataforma', 'Atender solicitudes de derechos sobre datos personales');

INSERT IGNORE INTO rol_permisos (rol_id, permiso_id)
SELECT r.id, p.id FROM roles r JOIN permisos p ON p.codigo = 'plataforma.privacidad.gestionar'
 WHERE r.codigo IN ('superadmin', 'operador');
