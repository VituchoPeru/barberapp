# Modelo entidad-relación

32 tablas en 6 dominios (más la tabla técnica `migraciones`). El detalle de cada columna está en [DICCIONARIO_DATOS.md](DICCIONARIO_DATOS.md), generado desde la base real.

GitHub muestra los diagramas Mermaid directamente. Para verlos en local: VS Code con la extensión *Markdown Preview Mermaid Support*, o pega el bloque en https://mermaid.live.

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
| Importes | `DECIMAL(10,2)` + `moneda` ISO 4217 (PEN). El precio de la reserva es **previsto**, no ingreso cobrado. |

## Núcleo SaaS

```mermaid
erDiagram
    planes ||--o{ suscripciones : "contrata"
    tenants ||--o{ suscripciones : "tiene"
    suscripciones ||--o{ pagos_saas : "se paga con"
    usuarios ||--o{ pagos_saas : "registra"
    tenants ||--o{ sucursales : "opera"
    sucursales ||--o{ horarios_sucursales : "abre"
    usuarios ||--o{ membresias : "pertenece"
    tenants ||--o{ membresias : "agrupa"
    roles ||--o{ membresias : "asigna"
    roles ||--o{ rol_permisos : ""
    permisos ||--o{ rol_permisos : ""
    roles |o--o{ usuarios : "rol de plataforma"
    usuarios |o--o{ tenants : "propietario"

    planes {
        bigint id PK
        varchar codigo UK
        decimal precio_mensual
        int max_sucursales
        int max_barberos
        json caracteristicas
    }
    tenants {
        bigint id PK
        varchar slug UK
        varchar nombre_comercial
        char ruc UK
        enum estado
        varchar zona_horaria
        bool publicado_marketplace
    }
    usuarios {
        bigint id PK
        varchar email UK
        varchar password_hash
        bigint rol_plataforma_id FK
        enum estado
    }
    roles {
        bigint id PK
        varchar codigo UK
        enum ambito
    }
    permisos {
        bigint id PK
        varchar codigo UK
    }
    rol_permisos {
        bigint rol_id PK
        bigint permiso_id PK
    }
    membresias {
        bigint id PK
        bigint tenant_id FK
        bigint usuario_id FK
        bigint rol_id FK
        enum estado
    }
    sucursales {
        bigint id PK
        bigint tenant_id FK
        varchar slug
        varchar distrito
        decimal latitud
        decimal longitud
        varchar zona_horaria
    }
    horarios_sucursales {
        bigint id PK
        bigint tenant_id FK
        bigint sucursal_id FK
        tinyint dia_semana
        time hora_apertura
        time hora_cierre
    }
    suscripciones {
        bigint id PK
        bigint tenant_id FK
        bigint plan_id FK
        enum estado
        decimal precio_pactado
        datetime periodo_fin_en
    }
    pagos_saas {
        bigint id PK
        bigint tenant_id FK
        bigint suscripcion_id FK
        decimal monto
        enum metodo
        enum estado
    }
```

## Gestión de barberías

```mermaid
erDiagram
    tenants ||--o{ barberos : "emplea"
    sucursales ||--o{ barberos : "sede principal"
    usuarios |o--o| barberos : "cuenta de acceso"
    tenants ||--o{ categorias_servicio : ""
    categorias_servicio |o--o{ servicios : "agrupa"
    tenants ||--o{ servicios : "ofrece"
    barberos ||--o{ barbero_servicios : "realiza"
    servicios ||--o{ barbero_servicios : "realizado por"
    barberos ||--o{ horarios_barberos : "jornada"
    sucursales ||--o{ horarios_barberos : "en"
    barberos |o--o{ bloqueos_agenda : "ausencias"
    sucursales |o--o{ bloqueos_agenda : "cierres"
    tenants ||--o{ politicas_reserva : "define"
    sucursales |o--o| politicas_reserva : "sobrescribe"

    barberos {
        bigint id PK
        bigint tenant_id FK
        bigint usuario_id FK
        bigint sucursal_id FK
        varchar nombre_publico
        bool activo
    }
    servicios {
        bigint id PK
        bigint tenant_id FK
        bigint categoria_id FK
        varchar nombre
        smallint duracion_minutos "CHECK 1..600"
        decimal precio "CHECK >= 0"
        bool activo
    }
    categorias_servicio {
        bigint id PK
        bigint tenant_id FK
        varchar nombre
    }
    barbero_servicios {
        bigint barbero_id PK
        bigint servicio_id PK
        bigint tenant_id FK
        smallint duracion_minutos_override
        decimal precio_override
    }
    horarios_barberos {
        bigint id PK
        bigint tenant_id FK
        bigint barbero_id FK
        bigint sucursal_id FK
        tinyint dia_semana
        time hora_inicio
        time hora_fin
    }
    bloqueos_agenda {
        bigint id PK
        bigint tenant_id FK
        bigint barbero_id FK
        bigint sucursal_id FK
        enum tipo
        datetime inicio_utc
        datetime fin_utc
        enum estado
    }
    politicas_reserva {
        bigint id PK
        bigint tenant_id FK
        bigint sucursal_id FK
        smallint intervalo_minutos
        int anticipacion_minima_minutos
        smallint horizonte_maximo_dias
        int cancelacion_limite_minutos
    }
```

## Clientes y reservas

```mermaid
erDiagram
    usuarios |o--o| clientes : "cuenta"
    clientes ||--o{ cliente_tenants : "vinculado a"
    tenants ||--o{ cliente_tenants : "atiende"
    clientes ||--o{ favoritos : "marca"
    tenants ||--o{ favoritos : "favorita de"
    cliente_tenants ||--o{ reservas : "reserva"
    sucursales ||--o{ reservas : "en"
    barberos ||--o{ reservas : "atiende"
    reservas ||--|{ reserva_servicios : "incluye"
    servicios ||--o{ reserva_servicios : ""
    reservas ||--o{ reserva_eventos : "historial"

    clientes {
        bigint id PK
        bigint usuario_id FK "NULL = mostrador"
        varchar nombres
        varchar telefono
    }
    cliente_tenants {
        bigint id PK
        bigint tenant_id FK
        bigint cliente_id FK
        enum origen
        varchar notas_internas
        int total_reservas
        int total_inasistencias
    }
    favoritos {
        bigint cliente_id PK
        bigint tenant_id PK
    }
    reservas {
        bigint id PK
        bigint tenant_id FK
        char codigo UK
        bigint sucursal_id FK
        bigint barbero_id FK
        bigint cliente_id FK
        enum estado
        datetime inicio_utc
        datetime fin_utc
        decimal precio_total
        int version
        bool ocupa_agenda "generada"
    }
    reserva_servicios {
        bigint id PK
        bigint tenant_id FK
        bigint reserva_id FK
        bigint servicio_id FK
        tinyint orden
        smallint duracion_minutos
        decimal precio
    }
    reserva_eventos {
        bigint id PK
        bigint tenant_id FK
        bigint reserva_id FK
        enum tipo
        datetime inicio_anterior_utc
        datetime inicio_nuevo_utc
        enum actor_tipo
    }
```

## Notificaciones y sistema

```mermaid
erDiagram
    usuarios ||--o{ dispositivos_push : "registra"
    usuarios ||--o{ preferencias_notificacion : "configura"
    tenants |o--o{ preferencias_notificacion : ""
    tenants |o--o{ plantillas_notificacion : "personaliza"
    plantillas_notificacion |o--o{ notificaciones : "genera"
    usuarios |o--o{ notificaciones : "recibe"
    reservas |o--o{ notificaciones : "sobre"
    tenants |o--o{ configuraciones : ""
    tenants |o--o{ webhook_eventos : ""
    usuarios |o--o{ idempotency_keys : ""

    dispositivos_push {
        bigint id PK
        bigint usuario_id FK
        enum plataforma
        char token_hash UK
    }
    preferencias_notificacion {
        bigint id PK
        bigint usuario_id FK
        bigint tenant_id FK
        enum canal
        varchar tipo
        bool habilitado
    }
    plantillas_notificacion {
        bigint id PK
        bigint tenant_id FK "NULL = global"
        varchar codigo
        enum canal
        text cuerpo
    }
    notificaciones {
        bigint id PK
        bigint tenant_id FK
        bigint usuario_id FK
        bigint reserva_id FK
        enum estado
        datetime programada_para_utc
        tinyint intentos
        varchar dedupe_key UK
    }
    auditoria {
        bigint id PK
        bigint tenant_id
        bigint usuario_id
        varchar accion
        enum resultado
        json datos_anteriores
        json datos_nuevos
    }
    configuraciones {
        bigint id PK
        bigint tenant_id FK
        varchar clave
        json valor
    }
    webhook_eventos {
        bigint id PK
        varchar proveedor
        varchar evento_id_externo
        json payload
        enum estado
    }
    idempotency_keys {
        bigint id PK
        bigint usuario_id FK
        varchar clave
        varchar endpoint
        char request_hash
        json respuesta
    }
```
