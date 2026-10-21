-- ============================================================
-- BARBERAPP · Migración 0007 · Autenticación (Fase 2)
-- sesiones (web y móvil), tokens de renovación, tokens de un solo uso
-- (verificación de correo / recuperación de contraseña) y límites de intentos.
-- Ningún token se guarda en claro: solo su SHA-256.
-- ============================================================

CREATE TABLE sesiones (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id         BIGINT UNSIGNED NOT NULL,
    canal              ENUM('web','movil') NOT NULL COMMENT 'web = cookie HttpOnly + CSRF; movil = access token corto + refresh token',
    token_hash         CHAR(64)        NULL COMMENT 'SHA-256 del token de la cookie de sesión (solo canal web)',
    tenant_id          BIGINT UNSIGNED NULL COMMENT 'Barbería seleccionada como contexto de trabajo',
    nombre_dispositivo VARCHAR(120)    NULL,
    ip                 VARBINARY(16)   NULL,
    user_agent         VARCHAR(255)    NULL,
    ultimo_uso_en      DATETIME        NOT NULL,
    expira_en          DATETIME        NOT NULL COMMENT 'Vencimiento absoluto de la sesión',
    revocada_en        DATETIME        NULL,
    motivo_revocacion  VARCHAR(40)     NULL COMMENT 'logout, logout_todas, password_reset, refresh_reutilizado, usuario',
    created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_sesiones_token (token_hash),
    KEY idx_sesiones_usuario_activas (usuario_id, revocada_en, expira_en),
    KEY idx_sesiones_tenant (tenant_id),
    KEY idx_sesiones_expira (expira_en),
    CONSTRAINT fk_sesiones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE,
    CONSTRAINT fk_sesiones_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id)  ON DELETE SET NULL,
    CONSTRAINT ck_sesiones_token_web CHECK (canal <> 'web' OR token_hash IS NOT NULL),
    CONSTRAINT ck_sesiones_expira CHECK (expira_en >= ultimo_uso_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Sesiones autenticadas; revocables individualmente';

CREATE TABLE tokens_refresco (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sesion_id   BIGINT UNSIGNED NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    expira_en   DATETIME        NOT NULL,
    usado_en    DATETIME        NULL COMMENT 'Rotación: cada token se usa una sola vez. Reutilizar uno usado revoca la sesión',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tokens_refresco_hash (token_hash),
    KEY idx_tokens_refresco_sesion (sesion_id),
    KEY idx_tokens_refresco_expira (expira_en),
    CONSTRAINT fk_tokens_refresco_sesion FOREIGN KEY (sesion_id) REFERENCES sesiones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Refresh tokens rotativos de la app móvil';

CREATE TABLE tokens_usuario (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id  BIGINT UNSIGNED NOT NULL,
    tipo        ENUM('verificar_email','restablecer_password') NOT NULL,
    token_hash  CHAR(64)        NOT NULL,
    email       VARCHAR(190)    NOT NULL COMMENT 'Correo al que se envió; si el usuario cambia de correo el token deja de valer',
    expira_en   DATETIME        NOT NULL,
    usado_en    DATETIME        NULL,
    ip          VARBINARY(16)   NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tokens_usuario_hash (token_hash),
    KEY idx_tokens_usuario_usuario_tipo (usuario_id, tipo, usado_en),
    KEY idx_tokens_usuario_expira (expira_en),
    CONSTRAINT fk_tokens_usuario_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Tokens de un solo uso enviados por correo';

CREATE TABLE limites_intentos (
    clave           CHAR(64)        NOT NULL COMMENT 'SHA-256 de ámbito + identificador (correo, IP, usuario)',
    ambito          VARCHAR(40)     NOT NULL COMMENT 'login, registro, recuperacion, restablecer, verificacion…',
    intentos        INT UNSIGNED    NOT NULL DEFAULT 0,
    ventana_inicio  DATETIME        NOT NULL,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (clave),
    KEY idx_limites_intentos_ventana (ventana_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Contadores de ventana fija para limitar intentos de autenticación';
