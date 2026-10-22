# Diccionario de datos

_Generado con `php bin/console docs:dictionary` a partir de la base de datos migrada. No editar a mano._

Convenciones: fechas de eventos en UTC; importes en DECIMAL(10,2); `tenant_id` identifica la barbería.

## `auditoria`

Bitácora inmutable (solo INSERT). Sin FKs a propósito: debe conservarse aunque se eliminen usuarios o tenants

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | sí |  | MUL |  |
| `usuario_id` | bigint unsigned | sí |  | MUL |  |
| `accion` | varchar(100) | no |  | MUL | p. ej. auth.login_fallido, servicio.actualizado |
| `entidad` | varchar(64) | sí |  | MUL |  |
| `entidad_id` | bigint unsigned | sí |  |  |  |
| `resultado` | enum('exito','denegado','error') | no | `exito` | MUL |  |
| `datos_anteriores` | json | sí |  |  |  |
| `datos_nuevos` | json | sí |  |  |  |
| `ip` | varbinary(16) | sí |  |  |  |
| `user_agent` | varchar(255) | sí |  |  |  |
| `request_id` | varchar(64) | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

## `barbero_servicios`

Servicios que puede realizar cada barbero (mismo tenant garantizado por FK compuesta)

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `barbero_id` | bigint unsigned | no |  | PRI |  |
| `servicio_id` | bigint unsigned | no |  | PRI |  |
| `duracion_minutos_override` | smallint unsigned | sí |  |  | Si el barbero tarda distinto al estándar |
| `precio_override` | decimal(10,2) | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id,barbero_id) → `barberos`(tenant_id,id); (tenant_id,servicio_id) → `servicios`(tenant_id,id)

## `barberos`

La fila del barbero es el candado estable de su agenda (SELECT ... FOR UPDATE)

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `usuario_id` | bigint unsigned | sí |  | MUL | Cuenta de acceso del barbero (opcional) |
| `sucursal_id` | bigint unsigned | no |  |  | Sucursal principal |
| `nombre_publico` | varchar(100) | no |  |  |  |
| `especialidad` | varchar(120) | sí |  |  | Texto corto visible, p. ej. "Degradados y diseños" |
| `biografia` | varchar(1000) | sí |  |  |  |
| `foto_url` | varchar(500) | sí |  |  |  |
| `activo` | tinyint(1) | no | `1` |  |  |
| `acepta_reservas_online` | tinyint(1) | no | `1` |  |  |
| `orden` | smallint | no | `0` |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |
| `deleted_at` | datetime | sí |  |  |  |

**Claves foráneas:** (tenant_id,sucursal_id) → `sucursales`(tenant_id,id); (tenant_id) → `tenants`(id); (usuario_id) → `usuarios`(id)

## `bloqueos_agenda`

Excepciones a la jornada: vacaciones, ausencias, feriados, bloqueos puntuales

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `barbero_id` | bigint unsigned | sí |  |  | NULL = afecta a toda la sucursal |
| `sucursal_id` | bigint unsigned | sí |  |  | NULL = afecta a todas las sucursales del barbero/tenant |
| `tipo` | enum('descanso','vacaciones','ausencia','feriado','bloqueo') | no |  |  |  |
| `inicio_utc` | datetime | no |  |  |  |
| `fin_utc` | datetime | no |  |  |  |
| `motivo` | varchar(255) | sí |  |  |  |
| `estado` | enum('solicitado','aprobado','rechazado') | no | `aprobado` |  |  |
| `creado_por` | bigint unsigned | sí |  | MUL |  |
| `aprobado_por` | bigint unsigned | sí |  | MUL |  |
| `respuesta` | varchar(255) | sí |  |  | Comentario de quien aprobó o rechazó la solicitud |
| `respondido_en` | datetime | sí |  |  | UTC; cuándo se aprobó o rechazó la solicitud |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (aprobado_por) → `usuarios`(id); (tenant_id,barbero_id) → `barberos`(tenant_id,id); (creado_por) → `usuarios`(id); (tenant_id,sucursal_id) → `sucursales`(tenant_id,id); (tenant_id) → `tenants`(id)

## `categorias_servicio`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `nombre` | varchar(80) | no |  |  |  |
| `orden` | smallint | no | `0` |  |  |
| `activo` | tinyint(1) | no | `1` |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id) → `tenants`(id)

## `cliente_tenants`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `cliente_id` | bigint unsigned | no |  | MUL |  |
| `origen` | enum('marketplace','enlace','qr','panel','app') | no | `marketplace` |  |  |
| `notas_internas` | varchar(2000) | sí |  |  | Observaciones autorizadas del personal; no visibles al cliente |
| `bloqueado` | tinyint(1) | no | `0` |  |  |
| `acepta_marketing` | tinyint(1) | no | `0` |  |  |
| `primera_visita_en` | datetime | sí |  |  |  |
| `ultima_visita_en` | datetime | sí |  |  |  |
| `total_reservas` | int unsigned | no | `0` |  |  |
| `total_inasistencias` | int unsigned | no | `0` |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (cliente_id) → `clientes`(id); (tenant_id) → `tenants`(id)

## `clientes`

Persona que reserva. Una barbería solo ve clientes vinculados mediante cliente_tenants

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `usuario_id` | bigint unsigned | sí |  | UNI | NULL = cliente registrado en mostrador sin cuenta |
| `nombres` | varchar(100) | no |  |  |  |
| `apellidos` | varchar(100) | sí |  |  |  |
| `telefono` | varchar(20) | sí |  | MUL |  |
| `email` | varchar(190) | sí |  | MUL |  |
| `fecha_nacimiento` | date | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |
| `deleted_at` | datetime | sí |  |  |  |

**Claves foráneas:** (usuario_id) → `usuarios`(id)

## `configuraciones`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | sí |  | MUL | NULL = parámetro global de la plataforma |
| `clave` | varchar(100) | no |  |  |  |
| `valor` | json | no |  |  |  |
| `descripcion` | varchar(255) | sí |  |  |  |
| `es_publica` | tinyint(1) | no | `0` |  | 1 = puede exponerse al frontend |
| `actualizado_por` | bigint unsigned | sí |  | MUL |  |
| `tenant_scope` | bigint unsigned VIRTUAL GENERATED | sí |  | MUL |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id) → `tenants`(id); (actualizado_por) → `usuarios`(id)

## `descansos_barberos`

Descansos semanales recurrentes; se descuentan de la jornada al calcular disponibilidad

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `barbero_id` | bigint unsigned | no |  |  |  |
| `dia_semana` | tinyint unsigned | no |  |  | ISO-8601: 1 = lunes … 7 = domingo |
| `hora_inicio` | time | no |  |  | Hora local de la sucursal donde trabaja ese día |
| `hora_fin` | time | no |  |  |  |
| `motivo` | varchar(80) | sí |  |  | Texto interno, p. ej. "Almuerzo" |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id,barbero_id) → `barberos`(tenant_id,id)

## `dispositivos_push`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `usuario_id` | bigint unsigned | no |  | MUL |  |
| `plataforma` | enum('android','ios','web') | no |  |  |  |
| `proveedor` | enum('fcm','apns','webpush') | no | `fcm` |  |  |
| `token` | varchar(1024) | no |  |  |  |
| `token_hash` | char(64) | no |  | UNI | SHA-256 del token para unicidad |
| `app_version` | varchar(20) | sí |  |  |  |
| `modelo` | varchar(80) | sí |  |  |  |
| `activo` | tinyint(1) | no | `1` |  |  |
| `desactivado_en` | datetime | sí |  |  | UTC; cuándo dejó de recibir avisos |
| `motivo_desactivado` | varchar(120) | sí |  |  | p. ej. token rechazado por FCM (UNREGISTERED) o cierre de sesión |
| `ultimo_uso_en` | datetime | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (usuario_id) → `usuarios`(id)

## `favoritos`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `cliente_id` | bigint unsigned | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | PRI |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (cliente_id) → `clientes`(id); (tenant_id) → `tenants`(id)

## `horarios_barberos`

Jornada semanal. Los descansos se expresan como varios tramos por día (p. ej. 09-13 y 14-19)

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `barbero_id` | bigint unsigned | no |  |  |  |
| `sucursal_id` | bigint unsigned | no |  |  |  |
| `dia_semana` | tinyint unsigned | no |  |  | ISO-8601: 1 = lunes … 7 = domingo |
| `hora_inicio` | time | no |  |  | Hora local de la sucursal |
| `hora_fin` | time | no |  |  |  |
| `vigente_desde` | date | sí |  |  |  |
| `vigente_hasta` | date | sí |  |  |  |
| `activo` | tinyint(1) | no | `1` |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id,barbero_id) → `barberos`(tenant_id,id); (tenant_id,sucursal_id) → `sucursales`(tenant_id,id)

## `horarios_sucursales`

Horario comercial publicado en el marketplace

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `sucursal_id` | bigint unsigned | no |  | MUL |  |
| `dia_semana` | tinyint unsigned | no |  |  | ISO-8601: 1 = lunes … 7 = domingo |
| `hora_apertura` | time | no |  |  | Hora local de la sucursal |
| `hora_cierre` | time | no |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id,sucursal_id) → `sucursales`(tenant_id,id)

## `idempotency_keys`

Evita reservas duplicadas por reintentos de red

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `usuario_id` | bigint unsigned | sí |  | MUL |  |
| `tenant_id` | bigint unsigned | sí |  | MUL |  |
| `clave` | varchar(100) | no |  |  | Cabecera Idempotency-Key enviada por el cliente |
| `metodo` | varchar(10) | no |  |  |  |
| `endpoint` | varchar(191) | no |  |  |  |
| `request_hash` | char(64) | no |  |  | SHA-256 del cuerpo para detectar reutilización con otro contenido |
| `estado` | enum('procesando','completada','fallida') | no | `procesando` |  |  |
| `codigo_respuesta` | smallint unsigned | sí |  |  |  |
| `respuesta` | json | sí |  |  |  |
| `recurso_tipo` | varchar(64) | sí |  |  |  |
| `recurso_id` | bigint unsigned | sí |  |  |  |
| `usuario_scope` | bigint unsigned VIRTUAL GENERATED | sí |  | MUL |  |
| `expira_en` | datetime | no |  | MUL |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id) → `tenants`(id); (usuario_id) → `usuarios`(id)

## `limites_intentos`

Contadores de ventana fija para limitar intentos de autenticación

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `clave` | char(64) | no |  | PRI | SHA-256 de ámbito + identificador (correo, IP, usuario) |
| `ambito` | varchar(40) | no |  |  | login, registro, recuperacion, restablecer, verificacion… |
| `intentos` | int unsigned | no | `0` |  |  |
| `ventana_inicio` | datetime | no |  | MUL |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

## `membresias`

Rol de un usuario dentro de una barbería (un usuario puede tener roles distintos en barberías distintas)

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `usuario_id` | bigint unsigned | no |  | MUL |  |
| `rol_id` | bigint unsigned | no |  | MUL |  |
| `estado` | enum('invitado','activo','suspendido','revocado') | no | `activo` |  |  |
| `invitado_por` | bigint unsigned | sí |  | MUL |  |
| `aceptado_en` | datetime | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (invitado_por) → `usuarios`(id); (rol_id) → `roles`(id); (tenant_id) → `tenants`(id); (usuario_id) → `usuarios`(id)

## `notificacion_envios`

Intentos de envío de la cola de notificaciones (registro de errores y métricas)

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `notificacion_id` | bigint unsigned | no |  | MUL |  |
| `tenant_id` | bigint unsigned | sí |  | MUL |  |
| `intento` | tinyint unsigned | no |  |  |  |
| `canal` | enum('push','email','interna','whatsapp','sms') | no |  |  |  |
| `proveedor` | varchar(30) | no |  |  | fcm, smtp, log, array, interna |
| `resultado` | enum('enviada','error_temporal','error_permanente','omitida') | no |  |  |  |
| `codigo` | varchar(60) | sí |  |  | Código de respuesta del proveedor (p. ej. UNREGISTERED, http_503) |
| `detalle` | varchar(1000) | sí |  |  |  |
| `dispositivos` | smallint unsigned | sí |  |  | Push: dispositivos a los que se intentó enviar |
| `duracion_ms` | int unsigned | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (notificacion_id) → `notificaciones`(id); (tenant_id) → `tenants`(id)

## `notificaciones`

Cola persistente procesada por worker PHP vía cron

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | sí |  | MUL |  |
| `usuario_id` | bigint unsigned | sí |  | MUL |  |
| `reserva_id` | bigint unsigned | sí |  |  |  |
| `plantilla_id` | bigint unsigned | sí |  | MUL |  |
| `canal` | enum('push','email','interna','whatsapp','sms') | no |  |  |  |
| `tipo` | varchar(50) | no |  |  |  |
| `destino` | varchar(255) | sí |  |  | Correo o teléfono; para push se resuelve por dispositivos_push |
| `titulo` | varchar(160) | sí |  |  |  |
| `cuerpo` | text | no |  |  |  |
| `datos` | json | sí |  |  | Payload adicional (deep link, acciones) |
| `estado` | enum('programada','procesando','enviada','fallida','cancelada','omitida') | no | `programada` | MUL |  |
| `programada_para_utc` | datetime | no |  |  |  |
| `proximo_intento_utc` | datetime | sí |  |  |  |
| `intentos` | tinyint unsigned | no | `0` |  |  |
| `max_intentos` | tinyint unsigned | no | `5` |  |  |
| `enviada_en` | datetime | sí |  |  |  |
| `leida_en` | datetime | sí |  |  |  |
| `ultimo_error` | varchar(1000) | sí |  |  |  |
| `dedupe_key` | varchar(191) | sí |  | UNI | p. ej. reserva:123:recordatorio_24h:push |
| `bloqueada_por` | varchar(64) | sí |  |  | Identificador del worker que la tomó |
| `bloqueada_en` | datetime | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (plantilla_id) → `plantillas_notificacion`(id); (tenant_id,reserva_id) → `reservas`(tenant_id,id); (tenant_id) → `tenants`(id); (usuario_id) → `usuarios`(id)

## `pagos_saas`

Pagos de suscripción de las barberías a BARBERAPP

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `suscripcion_id` | bigint unsigned | no |  |  |  |
| `monto` | decimal(10,2) | no |  |  |  |
| `moneda` | char(3) | no | `PEN` |  |  |
| `metodo` | enum('manual','transferencia','yape','plin','efectivo','pasarela') | no |  | MUL |  |
| `estado` | enum('pendiente','confirmado','rechazado','reembolsado') | no | `pendiente` | MUL |  |
| `referencia_externa` | varchar(120) | sí |  |  | N.º de operación o ID de la pasarela. Nunca datos de tarjeta. |
| `periodo_inicio` | date | no |  |  |  |
| `periodo_fin` | date | no |  |  |  |
| `pagado_en` | datetime | sí |  |  |  |
| `registrado_por` | bigint unsigned | sí |  | MUL |  |
| `notas` | varchar(500) | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (registrado_por) → `usuarios`(id); (tenant_id,suscripcion_id) → `suscripciones`(tenant_id,id)

## `permisos`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `codigo` | varchar(80) | no |  | UNI | modulo.accion, p. ej. reservas.cancelar |
| `modulo` | varchar(40) | no |  | MUL |  |
| `descripcion` | varchar(255) | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

## `planes`

Planes comerciales del SaaS

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `codigo` | varchar(40) | no |  | UNI |  |
| `nombre` | varchar(80) | no |  |  |  |
| `descripcion` | varchar(500) | sí |  |  |  |
| `precio_mensual` | decimal(10,2) | no |  |  |  |
| `precio_anual` | decimal(10,2) | sí |  |  |  |
| `moneda` | char(3) | no | `PEN` |  |  |
| `dias_prueba` | smallint unsigned | no | `0` |  |  |
| `max_sucursales` | int unsigned | sí |  |  | NULL = ilimitado |
| `max_barberos` | int unsigned | sí |  |  | NULL = ilimitado |
| `caracteristicas` | json | sí |  |  | Funcionalidades habilitadas: reportes_avanzados, personalizacion, canales... |
| `publico` | tinyint(1) | no | `1` |  |  |
| `activo` | tinyint(1) | no | `1` |  |  |
| `orden` | smallint | no | `0` |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

## `plantillas_notificacion`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | sí |  | MUL | NULL = plantilla global de la plataforma |
| `codigo` | varchar(60) | no |  |  |  |
| `canal` | enum('push','email','interna','whatsapp','sms') | no |  |  |  |
| `idioma` | varchar(10) | no | `es-PE` |  |  |
| `asunto` | varchar(160) | sí |  |  |  |
| `cuerpo` | text | no |  |  | Variables con sintaxis {{variable}} |
| `variables` | json | sí |  |  |  |
| `activo` | tinyint(1) | no | `1` |  |  |
| `version` | int unsigned | no | `1` |  |  |
| `tenant_scope` | bigint unsigned VIRTUAL GENERATED | sí |  | MUL |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id) → `tenants`(id)

## `politicas_reserva`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `sucursal_id` | bigint unsigned | sí |  |  | NULL = política por defecto del tenant |
| `intervalo_minutos` | smallint unsigned | no | `15` |  | Granularidad de horarios ofrecidos |
| `margen_entre_citas_minutos` | smallint unsigned | no | `0` |  |  |
| `anticipacion_minima_minutos` | int unsigned | no | `60` |  |  |
| `horizonte_maximo_dias` | smallint unsigned | no | `30` |  |  |
| `cancelacion_limite_minutos` | int unsigned | no | `120` |  | Minutos antes del inicio en que el cliente aún puede cancelar |
| `reprogramacion_limite_minutos` | int unsigned | no | `120` |  |  |
| `max_reprogramaciones` | tinyint unsigned | no | `2` |  |  |
| `max_reservas_activas_cliente` | tinyint unsigned | no | `3` |  |  |
| `confirmacion_automatica` | tinyint(1) | no | `1` |  | 1 = nace confirmada; 0 = nace pendiente |
| `permite_cualquier_barbero` | tinyint(1) | no | `1` |  |  |
| `texto_politica_cancelacion` | varchar(1000) | sí |  |  |  |
| `sucursal_scope` | bigint unsigned VIRTUAL GENERATED | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id,sucursal_id) → `sucursales`(tenant_id,id); (tenant_id) → `tenants`(id)

## `preferencias_notificacion`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `usuario_id` | bigint unsigned | no |  | MUL |  |
| `tenant_id` | bigint unsigned | sí |  | MUL | NULL = preferencia global del usuario |
| `canal` | enum('push','email','interna','whatsapp','sms') | no |  |  |  |
| `tipo` | varchar(50) | no |  |  | recordatorios, cambios (citas del cliente), agenda (avisos del personal), marketing |
| `habilitado` | tinyint(1) | no | `1` |  |  |
| `tenant_scope` | bigint unsigned VIRTUAL GENERATED | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id) → `tenants`(id); (usuario_id) → `usuarios`(id)

## `reserva_eventos`

Historial inmutable: solo INSERT

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `reserva_id` | bigint unsigned | no |  |  |  |
| `tipo` | enum('creada','confirmada','reprogramada','cancelada','asistencia_confirmada','atencion_iniciada','completada','no_asistio','modificada','nota','pago_registrado','pago_anulado') | no |  |  |  |
| `estado_anterior` | varchar(20) | sí |  |  |  |
| `estado_nuevo` | varchar(20) | sí |  |  |  |
| `inicio_anterior_utc` | datetime | sí |  |  |  |
| `fin_anterior_utc` | datetime | sí |  |  |  |
| `inicio_nuevo_utc` | datetime | sí |  |  |  |
| `fin_nuevo_utc` | datetime | sí |  |  |  |
| `barbero_anterior_id` | bigint unsigned | sí |  |  |  |
| `barbero_nuevo_id` | bigint unsigned | sí |  |  |  |
| `actor_tipo` | enum('cliente','personal','sistema') | no |  |  |  |
| `actor_usuario_id` | bigint unsigned | sí |  | MUL |  |
| `datos` | json | sí |  |  |  |
| `ip` | varbinary(16) | sí |  |  | INET6_ATON() |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (actor_usuario_id) → `usuarios`(id); (tenant_id,reserva_id) → `reservas`(tenant_id,id)

## `reserva_pagos`

Cobros registrados por la barbería; fuente de los ingresos del panel

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `reserva_id` | bigint unsigned | no |  |  |  |
| `monto` | decimal(10,2) | no |  |  |  |
| `moneda` | char(3) | no | `PEN` |  |  |
| `metodo` | enum('efectivo','tarjeta','yape','plin','transferencia','otro') | no |  |  |  |
| `referencia` | varchar(100) | sí |  |  | N.º de operación o comprobante (opcional) |
| `pagado_en` | datetime | no |  |  | UTC |
| `registrado_por` | bigint unsigned | sí |  | MUL |  |
| `anulado_en` | datetime | sí |  |  |  |
| `anulado_por` | bigint unsigned | sí |  | MUL |  |
| `motivo_anulacion` | varchar(255) | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (anulado_por) → `usuarios`(id); (registrado_por) → `usuarios`(id); (tenant_id,reserva_id) → `reservas`(tenant_id,id)

## `reserva_servicios`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `reserva_id` | bigint unsigned | no |  | MUL |  |
| `servicio_id` | bigint unsigned | no |  |  |  |
| `orden` | tinyint unsigned | no | `1` |  | Orden de atención consecutiva |
| `nombre_servicio` | varchar(120) | no |  |  | Copia al momento de reservar |
| `duracion_minutos` | smallint unsigned | no |  |  |  |
| `precio` | decimal(10,2) | no |  |  |  |
| `inicio_utc` | datetime | no |  |  |  |
| `fin_utc` | datetime | no |  |  |  |

**Claves foráneas:** (tenant_id,reserva_id) → `reservas`(tenant_id,id); (tenant_id,servicio_id) → `servicios`(tenant_id,id)

## `reservas`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `codigo` | char(10) | no |  | UNI | Código del comprobante mostrado al cliente |
| `sucursal_id` | bigint unsigned | no |  |  |  |
| `barbero_id` | bigint unsigned | no |  | MUL |  |
| `cliente_id` | bigint unsigned | no |  | MUL |  |
| `estado` | enum('pendiente','confirmada','completada','cancelada','no_asistio') | no | `pendiente` | MUL |  |
| `inicio_utc` | datetime | no |  |  |  |
| `fin_utc` | datetime | no |  |  |  |
| `zona_horaria` | varchar(64) | no |  |  | Zona de la sucursal al momento de reservar |
| `duracion_total_minutos` | smallint unsigned | no |  |  |  |
| `precio_total` | decimal(10,2) | no |  |  | Importe previsto; no equivale a ingreso cobrado |
| `moneda` | char(3) | no | `PEN` |  |  |
| `canal` | enum('marketplace','enlace','qr','panel','app') | no |  |  |  |
| `barbero_asignado_auto` | tinyint(1) | no | `0` |  | 1 = el cliente eligió "cualquier barbero" |
| `notas_cliente` | varchar(500) | sí |  |  |  |
| `notas_internas` | varchar(1000) | sí |  |  |  |
| `confirmada_en` | datetime | sí |  |  |  |
| `asistencia_confirmada_en` | datetime | sí |  |  | El cliente confirmó que asistirá |
| `atencion_iniciada_en` | datetime | sí |  |  |  |
| `atencion_finalizada_en` | datetime | sí |  |  |  |
| `cancelada_en` | datetime | sí |  |  |  |
| `cancelada_por` | bigint unsigned | sí |  | MUL |  |
| `cancelada_por_tipo` | enum('cliente','personal','sistema') | sí |  |  | Origen de la cancelación (para reportes) |
| `motivo_cancelacion` | varchar(255) | sí |  |  |  |
| `reprogramaciones` | tinyint unsigned | no | `0` |  |  |
| `version` | int unsigned | no | `1` |  | Control de concurrencia optimista |
| `creado_por` | bigint unsigned | sí |  | MUL |  |
| `ocupa_agenda` | tinyint(1) VIRTUAL GENERATED | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id,barbero_id) → `barberos`(tenant_id,id); (cancelada_por) → `usuarios`(id); (tenant_id,cliente_id) → `cliente_tenants`(tenant_id,cliente_id); (creado_por) → `usuarios`(id); (tenant_id,sucursal_id) → `sucursales`(tenant_id,id)

## `rol_permisos`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `rol_id` | bigint unsigned | no |  | PRI |  |
| `permiso_id` | bigint unsigned | no |  | PRI |  |

**Claves foráneas:** (permiso_id) → `permisos`(id); (rol_id) → `roles`(id)

## `roles`

superadmin, propietario, administrador, barbero, cliente

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `codigo` | varchar(40) | no |  | UNI |  |
| `nombre` | varchar(80) | no |  |  |  |
| `descripcion` | varchar(255) | sí |  |  |  |
| `ambito` | enum('plataforma','tenant','cliente') | no |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

## `servicios`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `categoria_id` | bigint unsigned | sí |  |  |  |
| `tipo_servicio_id` | bigint unsigned | sí |  | MUL | Clasificación global para los filtros del marketplace |
| `nombre` | varchar(120) | no |  |  |  |
| `descripcion` | varchar(1000) | sí |  |  |  |
| `duracion_minutos` | smallint unsigned | no |  |  |  |
| `precio` | decimal(10,2) | no |  | MUL |  |
| `moneda` | char(3) | no | `PEN` |  |  |
| `imagen_url` | varchar(500) | sí |  |  |  |
| `activo` | tinyint(1) | no | `1` |  |  |
| `orden` | smallint | no | `0` |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |
| `deleted_at` | datetime | sí |  |  |  |

**Claves foráneas:** (tenant_id,categoria_id) → `categorias_servicio`(tenant_id,id); (tenant_id) → `tenants`(id); (tipo_servicio_id) → `tipos_servicio`(id)

## `sesiones`

Sesiones autenticadas; revocables individualmente

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `usuario_id` | bigint unsigned | no |  | MUL |  |
| `canal` | enum('web','movil') | no |  |  | web = cookie HttpOnly + CSRF; movil = access token corto + refresh token |
| `token_hash` | char(64) | sí |  | UNI | SHA-256 del token de la cookie de sesión (solo canal web) |
| `tenant_id` | bigint unsigned | sí |  | MUL | Barbería seleccionada como contexto de trabajo |
| `nombre_dispositivo` | varchar(120) | sí |  |  |  |
| `ip` | varbinary(16) | sí |  |  |  |
| `user_agent` | varchar(255) | sí |  |  |  |
| `ultimo_uso_en` | datetime | no |  |  |  |
| `expira_en` | datetime | no |  | MUL | Vencimiento absoluto de la sesión |
| `revocada_en` | datetime | sí |  |  |  |
| `motivo_revocacion` | varchar(40) | sí |  |  | logout, logout_todas, password_reset, refresh_reutilizado, usuario |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (tenant_id) → `tenants`(id); (usuario_id) → `usuarios`(id)

## `sucursales`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `nombre` | varchar(120) | no |  |  |  |
| `slug` | varchar(80) | no |  |  |  |
| `direccion` | varchar(255) | no |  |  |  |
| `referencia` | varchar(255) | sí |  |  |  |
| `distrito` | varchar(80) | no |  |  |  |
| `provincia` | varchar(80) | no | `Lima` |  |  |
| `departamento` | varchar(80) | no | `Lima` | MUL |  |
| `ubigeo` | char(6) | sí |  |  |  |
| `latitud` | decimal(9,6) | sí |  | MUL |  |
| `longitud` | decimal(9,6) | sí |  |  |  |
| `telefono` | varchar(20) | sí |  |  |  |
| `email` | varchar(190) | sí |  |  |  |
| `zona_horaria` | varchar(64) | no | `America/Lima` |  |  |
| `es_principal` | tinyint(1) | no | `0` |  |  |
| `activo` | tinyint(1) | no | `1` |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |
| `deleted_at` | datetime | sí |  |  |  |

**Claves foráneas:** (tenant_id) → `tenants`(id)

## `suscripciones`

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `plan_id` | bigint unsigned | no |  | MUL |  |
| `estado` | enum('prueba','activa','vencida','suspendida','cancelada') | no |  | MUL |  |
| `ciclo` | enum('mensual','anual') | no | `mensual` |  |  |
| `precio_pactado` | decimal(10,2) | no |  |  |  |
| `moneda` | char(3) | no | `PEN` |  |  |
| `inicio_en` | datetime | no |  |  |  |
| `periodo_fin_en` | datetime | no |  |  | Fin del periodo pagado o de la prueba |
| `prueba_fin_en` | datetime | sí |  |  |  |
| `cancelada_en` | datetime | sí |  |  |  |
| `renovacion_automatica` | tinyint(1) | no | `0` |  |  |
| `notas` | varchar(500) | sí |  |  |  |
| `tenant_vigente` | bigint unsigned VIRTUAL GENERATED | sí |  | UNI |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (plan_id) → `planes`(id); (tenant_id) → `tenants`(id)

## `tenant_fotos`

Fotografías reales subidas por la barbería (logo, portada y galería). Se recodifican al subirse

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `tenant_id` | bigint unsigned | no |  | MUL |  |
| `sucursal_id` | bigint unsigned | sí |  |  | NULL = foto general de la barbería |
| `tipo` | enum('logo','portada','galeria') | no | `galeria` |  |  |
| `ruta` | varchar(255) | no |  | UNI | Ruta relativa dentro de MEDIA_PATH (tenants/{id}/{aleatorio}.webp) |
| `ancho` | smallint unsigned | no |  |  |  |
| `alto` | smallint unsigned | no |  |  |  |
| `bytes` | int unsigned | no |  |  |  |
| `texto_alternativo` | varchar(160) | sí |  |  | Descripción accesible de la imagen |
| `orden` | smallint | no | `0` |  |  |
| `subido_por` | bigint unsigned | sí |  | MUL |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (subido_por) → `usuarios`(id); (tenant_id,sucursal_id) → `sucursales`(tenant_id,id); (tenant_id) → `tenants`(id)

## `tenants`

Cada barbería es un tenant

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `slug` | varchar(80) | no |  | UNI | Enlace público /b/{slug} |
| `nombre_comercial` | varchar(120) | no |  | MUL |  |
| `razon_social` | varchar(160) | sí |  |  |  |
| `ruc` | char(11) | sí |  | UNI |  |
| `email` | varchar(190) | sí |  |  |  |
| `telefono` | varchar(20) | sí |  |  |  |
| `whatsapp` | varchar(20) | sí |  |  | Número E.164 para contacto por WhatsApp |
| `instagram` | varchar(30) | sí |  |  | Usuario sin @ |
| `facebook` | varchar(100) | sí |  |  | Nombre de página (facebook.com/{valor}) |
| `tiktok` | varchar(30) | sí |  |  | Usuario sin @ |
| `sitio_web` | varchar(255) | sí |  |  |  |
| `descripcion` | text | sí |  |  |  |
| `logo_url` | varchar(500) | sí |  |  |  |
| `portada_url` | varchar(500) | sí |  |  |  |
| `color_primario` | char(7) | no | `#C9A45C` |  |  |
| `color_secundario` | char(7) | no | `#111111` |  |  |
| `zona_horaria` | varchar(64) | no | `America/Lima` |  |  |
| `estado` | enum('pendiente','activo','suspendido','cancelado') | no | `pendiente` | MUL |  |
| `publicado_marketplace` | tinyint(1) | no | `0` |  |  |
| `propietario_usuario_id` | bigint unsigned | sí |  | MUL |  |
| `aprobado_en` | datetime | sí |  |  |  |
| `suspendido_en` | datetime | sí |  |  |  |
| `motivo_suspension` | varchar(255) | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |
| `deleted_at` | datetime | sí |  |  |  |

**Claves foráneas:** (propietario_usuario_id) → `usuarios`(id)

## `tipos_servicio`

Clasificación global de servicios (corte, barba…). Cada barbería nombra libremente sus servicios y los clasifica aquí

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `codigo` | varchar(40) | no |  | UNI |  |
| `nombre` | varchar(80) | no |  |  |  |
| `orden` | smallint | no | `0` |  |  |
| `activo` | tinyint(1) | no | `1` |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

## `tokens_refresco`

Refresh tokens rotativos de la app móvil

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `sesion_id` | bigint unsigned | no |  | MUL |  |
| `token_hash` | char(64) | no |  | UNI |  |
| `expira_en` | datetime | no |  | MUL |  |
| `usado_en` | datetime | sí |  |  | Rotación: cada token se usa una sola vez. Reutilizar uno usado revoca la sesión |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (sesion_id) → `sesiones`(id)

## `tokens_usuario`

Tokens de un solo uso enviados por correo

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `usuario_id` | bigint unsigned | no |  | MUL |  |
| `tipo` | enum('verificar_email','restablecer_password') | no |  |  |  |
| `token_hash` | char(64) | no |  | UNI |  |
| `email` | varchar(190) | no |  |  | Correo al que se envió; si el usuario cambia de correo el token deja de valer |
| `expira_en` | datetime | no |  | MUL |  |
| `usado_en` | datetime | sí |  |  |  |
| `ip` | varbinary(16) | sí |  |  |  |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |

**Claves foráneas:** (usuario_id) → `usuarios`(id)

## `usuarios`

Cuenta única por persona; los roles por barbería están en membresias

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `email` | varchar(190) | no |  | UNI |  |
| `password_hash` | varchar(255) | sí |  |  | password_hash(); NULL si solo usa acceso social o invitación pendiente |
| `nombres` | varchar(100) | no |  |  |  |
| `apellidos` | varchar(100) | sí |  |  |  |
| `telefono` | varchar(20) | sí |  | MUL | Formato E.164, p. ej. +51987654321 |
| `avatar_url` | varchar(500) | sí |  |  |  |
| `rol_plataforma_id` | bigint unsigned | sí |  | MUL | Solo personal de BARBERAPP (superadmin) |
| `estado` | enum('activo','bloqueado','eliminado') | no | `activo` |  |  |
| `email_verificado_en` | datetime | sí |  |  |  |
| `ultimo_login_en` | datetime | sí |  |  |  |
| `acepto_terminos_en` | datetime | sí |  |  |  |
| `acepto_privacidad_en` | datetime | sí |  |  | Consentimiento Ley 29733 |
| `created_at` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `updated_at` | datetime DEFAULT_GENERATED on update CURRENT_TIMESTAMP | no | `CURRENT_TIMESTAMP` |  |  |
| `deleted_at` | datetime | sí |  |  |  |

**Claves foráneas:** (rol_plataforma_id) → `roles`(id)

## `webhook_eventos`

Deduplica y registra webhooks entrantes

| Columna | Tipo | Nulo | Defecto | Clave | Descripción |
|---|---|---|---|---|---|
| `id` | bigint unsigned auto_increment | no |  | PRI |  |
| `proveedor` | varchar(50) | no |  | MUL | p. ej. pasarela de pagos, fcm, whatsapp |
| `evento_id_externo` | varchar(191) | no |  |  |  |
| `tipo` | varchar(100) | no |  |  |  |
| `tenant_id` | bigint unsigned | sí |  | MUL |  |
| `payload` | json | no |  |  |  |
| `firma_valida` | tinyint(1) | no | `0` |  |  |
| `estado` | enum('recibido','procesado','fallido','ignorado') | no | `recibido` | MUL |  |
| `intentos` | tinyint unsigned | no | `0` |  |  |
| `ultimo_error` | varchar(1000) | sí |  |  |  |
| `recibido_en` | datetime DEFAULT_GENERATED | no | `CURRENT_TIMESTAMP` |  |  |
| `procesado_en` | datetime | sí |  |  |  |

**Claves foráneas:** (tenant_id) → `tenants`(id)

