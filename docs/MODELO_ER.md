# Modelo entidad-relación

<!-- generado:resumen -->
44 tablas en 6 dominios (más la tabla técnica `migraciones`).
<!-- /generado:resumen -->
El detalle de cada columna (tipo, nulos, valores por defecto, comentarios y claves foráneas) está en [DICCIONARIO_DATOS.md](DICCIONARIO_DATOS.md). Tanto ese archivo como los diagramas de abajo se **generan desde la base migrada** con `php bin/console docs:generar`; la prueba `DocumentationTest` falla si una migración cambia el modelo y no se regeneran.

GitHub muestra los diagramas Mermaid directamente. En local: VS Code con la extensión *Markdown Preview Mermaid Support*, o pega el bloque en https://mermaid.live. Versiones SVG para imprimir en [`docs/diagramas/`](diagramas/) (se regeneran con `node frontend/scripts/diagramas-svg.mjs`).

Cómo leer los diagramas: cada línea va de la tabla padre a la hija y lleva el nombre de la columna que las une. `||--o{` = una a muchas (columna obligatoria), `|o--o{` = la hija puede no tener padre (columna que admite NULL), `--o|` = una a una (índice único). En las claves foráneas compuestas `(tenant_id, x_id)` se muestra solo `x_id`. En cada tabla aparecen sus claves (PK, FK, UK) y sus columnas de estado o tipo con los valores posibles. `tenants` y `usuarios` se dibujan solo en el núcleo, aunque casi todas las tablas las referencian.

## Reglas clave del modelo

| Regla | Cómo se garantiza |
|---|---|
| Aislamiento entre barberías | Cada tabla de negocio tiene `tenant_id` y `UNIQUE (tenant_id, id)`. Las relaciones hijas usan **FK compuestas** `(tenant_id, x_id) → padre(tenant_id, id)`, así la base de datos rechaza, por ejemplo, asignar a un barbero un servicio de otra barbería. |
| El cliente reserva solo donde está vinculado | `reservas (tenant_id, cliente_id) → cliente_tenants (tenant_id, cliente_id)` |
| Una persona, varios roles | `usuarios` es global; `membresias (usuario, tenant, rol)` permite ser barbero en una barbería y administrador en otra. |
| Superadmin sin acceso implícito a datos de barberías | El rol de plataforma vive en `usuarios.rol_plataforma_id`, no en `membresias`; sus permisos son solo `plataforma.*`. |
| Una suscripción vigente por barbería | Columna generada `tenant_vigente` con índice único. |
| Duración > 0, precio ≥ 0, rangos válidos | Restricciones `CHECK` en servicios, reservas, horarios, bloqueos, pagos. |
| Doble reserva | `SELECT … FOR UPDATE` sobre la fila de `barberos` + verificación de solapamiento en la transacción (Fase 5), apoyada por el índice `idx_reservas_agenda (barbero_id, ocupa_agenda, inicio_utc, fin_utc)`. |
| Reintentos de red | `idempotency_keys` única por (usuario, método, endpoint, clave). |
| Recordatorios sin duplicados | `notificaciones.dedupe_key` única. |
| Fechas | Eventos en UTC (`*_utc`, `*_en`); horarios semanales en hora local de la sucursal (`TIME`) junto con su `zona_horaria`. |
| Importes | `DECIMAL(10,2)` + `moneda` ISO 4217 (PEN). El precio de la reserva es **previsto**; lo cobrado está en `reserva_pagos` (Fase 6). |
| Cobros de la plataforma | `ordenes_pago` (pago en línea, Fase 10) y `pagos_saas` (manual o confirmado por webhook); `webhook_eventos` evita aplicar dos veces el mismo aviso de la pasarela. |
| Historial auditable | `reserva_eventos` (cada cambio de una cita), `suscripcion_eventos` (Fase 9) y `auditoria` (solo INSERT, sin FK para sobrevivir a borrados). |
| Datos personales | `solicitudes_datos` registra los pedidos ARCO con su plazo; eliminar una cuenta anonimiza `usuarios` y `clientes` sin romper reservas ni cobros (Fase 13). |

## Migraciones

| Migración | Fase | Qué agrega |
|---|---|---|
| `0001_nucleo_saas.sql` | 1 | Barberías, sucursales, cuentas, roles y permisos, planes, suscripciones y pagos |
| `0002_gestion_barberias.sql` | 1 | Barberos, servicios, horarios, bloqueos y reglas de reserva |
| `0003_clientes.sql` | 1 | Clientes, vínculo con cada barbería y favoritos |
| `0004_reservas.sql` | 1 | Reservas, sus servicios y su historial de eventos |
| `0005_notificaciones.sql` | 1 | Avisos, plantillas, preferencias y dispositivos push |
| `0006_sistema.sql` | 1 | Auditoría, configuraciones, webhooks e idempotencia |
| `0007_autenticacion.sql` | 2 | Sesiones, refresh tokens, tokens de un uso y límites de intentos |
| `0008_marketplace.sql` | 3 | Tipos de servicio, fotos y redes de la barbería |
| `0009_agenda_barberos.sql` | 4 | Categorías, descansos, vigencias y bloqueos por sucursal |
| `0010_motor_reservas.sql` | 5 | Columnas y restricciones del motor de reservas y la política |
| `0011_panel_barberia.sql` | 6 | Cobros de las citas, notas internas y datos de mostrador |
| `0012_panel_barbero.sql` | 7 | Solicitudes de ausencia con aprobación |
| `0013_recordatorios.sql` | 8 | Cola de avisos con reintentos y registro de envíos |
| `0014_superadmin_saas.sql` | 9 | Datos de demostración, bloqueo de cuentas, eventos de suscripción, rol operador |
| `0015_planes_monetizacion.sql` | 10 | Órdenes de pago en línea, cambios de plan programados, restricción por falta de pago |
| `0016_seguridad_datos.sql` | 13 | Solicitudes de derechos ARCO, versiones de textos legales, cambio de correo |

## Diagramas por dominio

<!-- generado:diagramas -->
### Núcleo SaaS: barberías, sucursales, cuentas y roles

```mermaid
erDiagram
    usuarios |o--o{ tenants : "propietario_usuario_id"
    tenants ||--o{ sucursales : "tenant_id"
    sucursales ||--o{ horarios_sucursales : "sucursal_id"
    roles |o--o{ usuarios : "rol_plataforma_id"
    permisos ||--o{ rol_permisos : "permiso_id"
    roles ||--o{ rol_permisos : "rol_id"
    usuarios |o--o{ membresias : "invitado_por"
    roles ||--o{ membresias : "rol_id"
    tenants ||--o{ membresias : "tenant_id"
    usuarios ||--o{ membresias : "usuario_id"
    tenants {
        bigint id PK
        varchar slug UK
        char ruc UK
        enum estado "pendiente,activo,suspendido,cancelado"
        bigint propietario_usuario_id FK
    }
    sucursales {
        bigint id PK
        bigint tenant_id FK
    }
    horarios_sucursales {
        bigint id PK
        bigint tenant_id FK
        bigint sucursal_id FK
    }
    usuarios {
        bigint id PK
        varchar email UK
        bigint rol_plataforma_id FK
        enum estado "activo,bloqueado,eliminado"
    }
    roles {
        bigint id PK
        varchar codigo UK
        enum ambito "plataforma,tenant,cliente"
    }
    permisos {
        bigint id PK
        varchar codigo UK
    }
    rol_permisos {
        bigint rol_id PK, FK
        bigint permiso_id PK, FK
    }
    membresias {
        bigint id PK
        bigint tenant_id FK
        bigint usuario_id FK
        bigint rol_id FK
        enum estado "invitado,activo,suspendido,revocado"
        bigint invitado_por FK
    }
```

### Planes, suscripciones y cobros de la plataforma

```mermaid
erDiagram
    planes ||--o{ suscripciones : "plan_id"
    planes |o--o{ suscripciones : "plan_programado_id"
    pagos_saas |o--o{ suscripcion_eventos : "pago_id"
    suscripciones ||--o{ suscripcion_eventos : "suscripcion_id"
    ordenes_pago |o--o{ pagos_saas : "orden_id"
    suscripciones ||--o{ pagos_saas : "suscripcion_id"
    pagos_saas |o--o{ ordenes_pago : "pago_saas_id"
    planes ||--o{ ordenes_pago : "plan_id"
    suscripciones |o--o{ ordenes_pago : "suscripcion_id"
    planes {
        bigint id PK
        varchar codigo UK
    }
    suscripciones {
        bigint id PK
        bigint tenant_id FK
        bigint plan_id FK
        enum estado "prueba,activa,vencida,suspendida,cancelada"
        bigint plan_programado_id FK
        bigint tenant_vigente UK
    }
    suscripcion_eventos {
        bigint id PK
        bigint tenant_id FK
        bigint suscripcion_id FK
        enum tipo "alta,cambio_plan,renovacion,pago,suspension,reactivacion,cancelacion,vencimiento,cambio_programado,prueba_automatica"
        bigint pago_id FK
        bigint usuario_id FK
    }
    pagos_saas {
        bigint id PK
        bigint tenant_id FK
        bigint suscripcion_id FK
        bigint orden_id FK
        enum metodo "manual,transferencia,yape,plin,efectivo,pasarela"
        enum estado "pendiente,confirmado,rechazado,reembolsado"
        bigint registrado_por FK
    }
    ordenes_pago {
        bigint id PK
        bigint tenant_id FK
        char codigo UK
        enum tipo "renovacion,contratacion,mejora"
        bigint suscripcion_id FK
        bigint plan_id FK
        enum estado "pendiente,pagada,fallida,expirada,anulada"
        bigint pago_saas_id FK
        bigint solicitado_por FK
    }
```

### Catálogo y agenda de la barbería

```mermaid
erDiagram
    categorias_servicio |o--o{ servicios : "categoria_id"
    tipos_servicio |o--o{ servicios : "tipo_servicio_id"
    sucursales ||--o{ barberos : "sucursal_id"
    barberos ||--o{ barbero_servicios : "barbero_id"
    servicios ||--o{ barbero_servicios : "servicio_id"
    barberos ||--o{ horarios_barberos : "barbero_id"
    sucursales ||--o{ horarios_barberos : "sucursal_id"
    barberos ||--o{ descansos_barberos : "barbero_id"
    barberos |o--o{ bloqueos_agenda : "barbero_id"
    sucursales |o--o{ bloqueos_agenda : "sucursal_id"
    sucursales |o--o{ politicas_reserva : "sucursal_id"
    sucursales |o--o{ tenant_fotos : "sucursal_id"
    categorias_servicio {
        bigint id PK
        bigint tenant_id FK
    }
    tipos_servicio {
        bigint id PK
        varchar codigo UK
    }
    servicios {
        bigint id PK
        bigint tenant_id FK
        bigint categoria_id FK
        bigint tipo_servicio_id FK
    }
    barberos {
        bigint id PK
        bigint tenant_id FK
        bigint usuario_id FK
        bigint sucursal_id FK
    }
    barbero_servicios {
        bigint tenant_id FK
        bigint barbero_id PK, FK
        bigint servicio_id PK, FK
    }
    horarios_barberos {
        bigint id PK
        bigint tenant_id FK
        bigint barbero_id FK
        bigint sucursal_id FK
    }
    descansos_barberos {
        bigint id PK
        bigint tenant_id FK
        bigint barbero_id FK
    }
    bloqueos_agenda {
        bigint id PK
        bigint tenant_id FK
        bigint barbero_id FK
        bigint sucursal_id FK
        enum tipo "descanso,vacaciones,ausencia,feriado,bloqueo"
        enum estado "solicitado,aprobado,rechazado"
        bigint creado_por FK
        bigint aprobado_por FK
    }
    politicas_reserva {
        bigint id PK
        bigint tenant_id FK
        bigint sucursal_id FK
    }
    tenant_fotos {
        bigint id PK
        bigint tenant_id FK
        bigint sucursal_id FK
        enum tipo "logo,portada,galeria"
        varchar ruta UK
        bigint subido_por FK
    }
    sucursales {
        bigint id PK
    }
```

### Clientes y reservas

```mermaid
erDiagram
    clientes ||--o{ cliente_tenants : "cliente_id"
    clientes ||--o{ favoritos : "cliente_id"
    barberos ||--o{ reservas : "barbero_id"
    cliente_tenants ||--o{ reservas : "cliente_id"
    sucursales ||--o{ reservas : "sucursal_id"
    reservas ||--o{ reserva_servicios : "reserva_id"
    servicios ||--o{ reserva_servicios : "servicio_id"
    reservas ||--o{ reserva_eventos : "reserva_id"
    reservas ||--o{ reserva_pagos : "reserva_id"
    clientes {
        bigint id PK
        bigint usuario_id FK, UK
    }
    cliente_tenants {
        bigint id PK
        bigint tenant_id FK
        bigint cliente_id FK
    }
    favoritos {
        bigint cliente_id PK, FK
        bigint tenant_id PK, FK
    }
    reservas {
        bigint id PK
        bigint tenant_id FK
        char codigo UK
        bigint sucursal_id FK
        bigint barbero_id FK
        bigint cliente_id FK
        enum estado "pendiente,confirmada,completada,cancelada,no_asistio"
        enum canal "marketplace,enlace,qr,panel,app"
        bigint cancelada_por FK
        bigint creado_por FK
    }
    reserva_servicios {
        bigint id PK
        bigint tenant_id FK
        bigint reserva_id FK
        bigint servicio_id FK
    }
    reserva_eventos {
        bigint id PK
        bigint tenant_id FK
        bigint reserva_id FK
        enum tipo "creada,confirmada,reprogramada,cancelada,asistencia_confirmada,atencion_iniciada,completada,no_asistio,modificada,nota,pago_registrado,pago_anulado"
        bigint actor_usuario_id FK
    }
    reserva_pagos {
        bigint id PK
        bigint tenant_id FK
        bigint reserva_id FK
        enum metodo "efectivo,tarjeta,yape,plin,transferencia,otro"
        bigint registrado_por FK
        bigint anulado_por FK
    }
    idempotency_keys {
        bigint id PK
        bigint usuario_id FK
        bigint tenant_id FK
        enum estado "procesando,completada,fallida"
    }
    barberos {
        bigint id PK
    }
    sucursales {
        bigint id PK
    }
    servicios {
        bigint id PK
    }
```

### Avisos y notificaciones

```mermaid
erDiagram
    plantillas_notificacion |o--o{ notificaciones : "plantilla_id"
    reservas |o--o{ notificaciones : "reserva_id"
    notificaciones ||--o{ notificacion_envios : "notificacion_id"
    notificaciones {
        bigint id PK
        bigint tenant_id FK
        bigint usuario_id FK
        bigint reserva_id FK
        bigint plantilla_id FK
        enum canal "push,email,interna,whatsapp,sms"
        enum estado "programada,procesando,enviada,fallida,cancelada,omitida"
        varchar dedupe_key UK
    }
    notificacion_envios {
        bigint id PK
        bigint notificacion_id FK
        bigint tenant_id FK
        enum canal "push,email,interna,whatsapp,sms"
        enum resultado "enviada,error_temporal,error_permanente,omitida"
    }
    plantillas_notificacion {
        bigint id PK
        bigint tenant_id FK
        enum canal "push,email,interna,whatsapp,sms"
    }
    preferencias_notificacion {
        bigint id PK
        bigint usuario_id FK
        bigint tenant_id FK
        enum canal "push,email,interna,whatsapp,sms"
    }
    dispositivos_push {
        bigint id PK
        bigint usuario_id FK
        char token_hash UK
    }
    reservas {
        bigint id PK
    }
```

### Seguridad, sesiones y sistema

```mermaid
erDiagram
    sesiones ||--o{ tokens_refresco : "sesion_id"
    sesiones {
        bigint id PK
        bigint usuario_id FK
        enum canal "web,movil"
        char token_hash UK
        bigint tenant_id FK
    }
    tokens_refresco {
        bigint id PK
        bigint sesion_id FK
        char token_hash UK
    }
    tokens_usuario {
        bigint id PK
        bigint usuario_id FK
        enum tipo "verificar_email,restablecer_password,cambiar_email"
        char token_hash UK
    }
    limites_intentos {
        char clave PK
    }
    auditoria {
        bigint id PK
        enum resultado "exito,denegado,error"
    }
    configuraciones {
        bigint id PK
        bigint tenant_id FK
        bigint actualizado_por FK
    }
    webhook_eventos {
        bigint id PK
        bigint tenant_id FK
        enum estado "recibido,procesado,fallido,ignorado"
    }
    solicitudes_datos {
        bigint id PK
        char codigo UK
        bigint usuario_id FK
        enum tipo "acceso,rectificacion,cancelacion,oposicion"
        enum estado "recibida,en_proceso,atendida,rechazada"
        bigint atendida_por FK
    }
```
<!-- /generado:diagramas -->
