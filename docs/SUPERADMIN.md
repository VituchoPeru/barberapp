# Panel del superadministrador SaaS (Fase 9)

Panel exclusivo del equipo de BARBERAPP en `frontend/www/pages/plataforma/` (entrada: *Mi cuenta → Abrir panel de la plataforma*). La API vive en `/api/v1/plataforma/*` y exige sesión y un rol de plataforma (`PlatformMiddleware`); cada ruta pide además su permiso `plataforma.*`.

## Roles

| Rol | Permisos | Para qué |
|---|---|---|
| `superadmin` | todos los `plataforma.*` | Dueño de la plataforma |
| `operador` (nuevo) | `tenants.gestionar`, `suscripciones.gestionar`, `pagos.gestionar`, `reportes.ver`, `auditoria.ver` | Soporte y cobranza sin tocar planes, plantillas, configuración ni roles |

El rol de plataforma se asigna desde *Usuarios* (solo a cuentas con correo verificado). Cambiarlo o bloquear una cuenta cierra todas sus sesiones. Nadie puede bloquearse ni cambiarse el rol a sí mismo, y siempre queda al menos un superadministrador activo.

## Privacidad

El superadministrador **no** tiene permisos de barbería (`clientes.ver`, `reservas.ver_todas`, …): no ve clientes, citas ni teléfonos de las barberías. Ve solo cifras agregadas (sucursales, barberos, reservas, clientes como número). La búsqueda de cuentas exige 3 caracteres, devuelve como máximo 20 resultados sin teléfono ni citas, y cada búsqueda y cada ficha consultada quedan en la auditoría (`plataforma.usuarios.busqueda`, `plataforma.usuarios.consulta`).

## Módulos

| Módulo | Página | Endpoints |
|---|---|---|
| Dashboard | `index.html` | `GET /plataforma/dashboard?excluir_demo=1`, `GET /plataforma/sistema` |
| Barberías | `barberias.html` | `GET /barberias?q=&estado=&plan=&demo=`, `GET/PUT /barberias/{id}`, `POST /barberias/{id}/aprobar · rechazar · suspender · reactivar` |
| Suscripciones | `suscripciones.html` | `GET/POST /suscripciones`, `GET /suscripciones/{id}`, `POST …/cambiar-plan · renovar · suspender · reactivar · cancelar` |
| Pagos | `pagos.html` | `GET /pagos`, `POST /suscripciones/{id}/pagos`, `POST /pagos/{id}/confirmar · rechazar · reembolsar` |
| Planes | `planes.html` | `GET/POST /planes`, `PUT /planes/{id}` |
| Usuarios | `usuarios.html` | `GET /usuarios`, `GET /usuarios/buscar?q=`, `GET /usuarios/{id}`, `POST …/bloquear · desbloquear`, `PUT …/rol` |
| Avisos | `avisos.html` | `GET /plantillas`, `PUT/DELETE /plantillas/{codigo}/{push\|email}`, `POST …/vista-previa` |
| Reportes | `reportes.html` | `GET /reportes?meses=1..24&excluir_demo=1&formato=csv` |
| Auditoría | `auditoria.html` | `GET /auditoria?accion=&resultado=&tenant=&usuario=&desde=&hasta=&antes_de=`, `GET /auditoria/acciones` |
| Configuración | `configuracion.html` | `GET/PUT /configuracion` |

## Indicadores (cómo se calculan)

Todo sale de la base de datos; nada se estima en el navegador. Las barberías de demostración tienen `tenants.es_demo = 1`: el dashboard y los reportes las incluyen por defecto **y lo dicen** con un aviso, y el interruptor *Excluir datos de demostración* (`excluir_demo=1`) las quita de todas las cifras.

- **Estado de la suscripción de una barbería:** el de su suscripción más reciente. Una suscripción `prueba`/`activa` cuyo periodo terminó cuenta como **vencida** aunque el cron aún no la haya marcado.
- **Vigente en un instante t:** `inicio_en ≤ t < min(periodo_fin_en, terminada_en)`.
- **MRR:** suma de `precio_pactado` de las suscripciones `activa` vigentes (anual ÷ 12), por moneda. Las pruebas no suman.
- **Churn 30 días:** de las suscripciones de pago vigentes hace 30 días, cuántas ya no lo están y su barbería no tiene otra vigente hoy. Sin base, se muestra «—».
- **Ingresos:** pagos `confirmado` por fecha de pago. Los pendientes se muestran aparte; los rechazados y reembolsados no cuentan.
- **Nuevas altas:** barberías creadas en los últimos 30 días (y los 30 anteriores para comparar).
- **Reservas agregadas:** reservas creadas por fecha de creación; atendidas, canceladas y no asistió por estado.
- **Consumo por plan:** barberías vigentes por plan, sus sucursales y barberos activos, reservas en 30 días y cuántas superan los límites del plan (desde la Fase 10 el límite se aplica al crear o reactivar; ver [MONETIZACION.md](MONETIZACION.md)).
- Los meses de los reportes se cortan en la zona horaria de la plataforma (`plataforma.zona_horaria`).

## Ciclo de vida

**Barbería:** `pendiente → activo` (aprobar) o `→ cancelado` (rechazar con motivo); `activo ⇄ suspendido` (con motivo). Suspender la saca del marketplace y bloquea reservas nuevas, pero **no** cancela las citas ya agendadas; su equipo sigue entrando al panel y ve un aviso fijo con el motivo y el correo de soporte. Cada cambio avisa a los propietarios por la bandeja y por correo (plantillas `barberia_aprobada`, `barberia_rechazada`, `barberia_suspendida`, `barberia_reactivada`). Con `plataforma.aprobacion_manual_tenants = false` las barberías nuevas nacen activas.

**Suscripción:** una sola vigente por barbería. Una fila terminada (vencida, suspendida o cancelada) nunca vuelve a estar vigente: reactivar o renovar después de vencer crea una fila nueva. Cada cambio queda en `suscripcion_eventos` (alta, cambio de plan, renovación, pago, suspensión, reactivación, cancelación, vencimiento) con antes/después.

- Renovar con el periodo vigente extiende desde el fin actual (una prueba pasa a activa); si ya terminó, empieza hoy.
- Cambiar de plan conserva el periodo; el precio pactado nuevo rige desde ya para el MRR.
- Cambiar el precio de un plan **no** cambia el precio pactado de las suscripciones existentes.
- Reembolsar un pago no acorta el periodo: si corresponde, se suspende o cancela la suscripción.
- Un pago confirmado con `periodos > 0` renueva la suscripción. El número de operación no se puede repetir.

## Puesta en marcha

```bash
php bin/console migrate          # aplica 0014_superadmin_saas.sql
php bin/console db:seed          # agrega el rol operador y plataforma.soporte_email (idempotente)
```

Cron cada hora para marcar vencidas las suscripciones cuyo periodo terminó:

```cron
5 * * * * cd /ruta/barberapp/backend && php bin/console suscripciones:vencer >> storage/logs/cron.log 2>&1
```

Configura el correo de soporte en *Configuración*: se muestra a las barberías suspendidas o pendientes.

## Fase 10

La pasarela de pago y los webhooks, la prueba automática, el bloqueo por límites del plan, los avisos de vencimiento y la restricción tras la gracia están en [MONETIZACION.md](MONETIZACION.md). En este panel se agregan: *Configuración → Suscripciones y cobros* (prueba automática y su plan, días de gracia, restricción y días de aviso) y *Pagos → Pagos en línea* (órdenes de todas las barberías; las que no cuadran quedan «En revisión» con un pago por confirmar).

## Pruebas

`tests/Integration/Platform/` (30 pruebas): acceso por rol y sin permisos de barbería, transiciones de barbería con avisos y auditoría, suscripciones y pagos (referencias duplicadas, renovaciones, reactivación tras prueba, vencimiento), métricas contra valores calculados a mano (MRR, churn, meses en zona horaria, exclusión de demo, CSV), usuarios (último superadmin, autobloqueo, cierre de sesiones, búsqueda auditada), plantillas, configuración y auditoría.

Recorrido E2E en Chromium (escritorio 1366×900 y móvil 390×844): los 10 módulos sin errores de consola ni desbordes; aprobar, suspender y reactivar; registrar pago y rechazo de referencia duplicada; crear suscripción y plan; bloquear y desbloquear; editar plantilla con vista previa; configuración con validación; CSV; auditoría; menú reducido del operador; cliente sin acceso.
