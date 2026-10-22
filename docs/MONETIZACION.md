# Planes y monetización (Fase 10)

Cómo cobra BARBERAPP a las barberías: planes con límites, prueba gratuita, pago en línea con Mercado Pago, vencimientos, avisos y restricción. Las decisiones están en [DECISIONES.md](DECISIONES.md) (078 en adelante).

## Resumen

| Pieza | Dónde |
|---|---|
| Planes y precios (editables por el superadministrador) | Plataforma → Planes (`planes`) |
| Límites y funciones del plan vigente | `App\Services\Billing\Entitlements` |
| Prueba gratuita automática | `SubscriptionService::startTrial` (registro y aprobación) |
| Pantalla del propietario | `pages/panel/suscripcion.html` |
| Órdenes de pago y checkout | `BillingService`, tabla `ordenes_pago` |
| Pasarelas | `App\Services\Billing\Gateway\*` (Mercado Pago, simulador local) |
| Webhook | `POST /api/v1/webhooks/pagos/{mercadopago|sandbox}` → `PaymentProcessor` |
| Cron | `php bin/console suscripciones:vencer` cada hora → `Lifecycle` |
| Parámetros globales | Plataforma → Configuración → *Suscripciones y cobros* |
| Pagos en línea de todas las barberías | Plataforma → Pagos → *Pagos en línea* |

## Planes y límites

Los tres planes del seeder (precios editables desde el panel de la plataforma; un cambio de precio no toca el precio pactado de las suscripciones existentes):

| Plan | Mensual | Anual | Sucursales | Barberos | Reportes avanzados | Colores propios | Canales |
|---|---|---|---|---|---|---|---|
| Básico | S/ 39 | S/ 390 | 1 | 3 | No | No | Bandeja, push |
| Profesional | S/ 79 | S/ 790 | 2 | 10 | Sí | Sí | Bandeja, push, correo |
| Premium | S/ 149 | S/ 1490 | Sin límite | Sin límite | Sí | Sí | Bandeja, push, correo |

**Plan que rige a una barbería:** el de su suscripción vigente (`prueba` o `activa` con `periodo_fin_en` futuro); si no tiene una vigente, el de su última suscripción. Una barbería que nunca tuvo suscripción (por ejemplo, creada antes de esta fase o con la prueba automática apagada) usa el plan de prueba configurado y no se restringe.

**Cómo se aplican los límites**

- Sucursales y barberos activos: al crear o reactivar uno se bloquea la fila de la barbería (`FOR UPDATE`) y se cuenta. Si se supera el límite: `409 plan_limit` con `{recurso, limite, uso, plan}`. **Nunca se desactiva nada automáticamente**: si una barbería baja de plan con más de lo permitido, conserva lo que tiene pero no puede añadir más. Bajar a un plan que ya se excede se rechaza hasta desactivar lo sobrante.
- Reportes avanzados: sin ellos, el reporte no trae ocupación ni evolución mensual (`null`) y el rango máximo es de 31 días (`403 plan_feature` si se pide más). La pantalla lo explica y enlaza a los planes.
- Colores propios: cambiar los colores sin la función responde `403 plan_feature`; guardar el resto del negocio sigue funcionando.
- Canales: un canal fuera del plan no se envía aunque el interruptor esté encendido (el worker lo revisa antes de enviar). Avisos lo indica junto al interruptor.

## Prueba gratuita

- Al quedar activa una barbería (registro sin aprobación manual, o cuando la plataforma la aprueba) se crea una suscripción `prueba` del plan `plataforma.plan_prueba` (por defecto Profesional) con los días de prueba del plan (`dias_prueba`, 14 por defecto). Evento `prueba_automatica`.
- Solo una vez por barbería: suspender y reactivar no regala otra.
- Durante la prueba el propietario puede cambiar de plan gratis (la fecha de fin no cambia) y contratar: el periodo pagado empieza cuando termina la prueba, así no pierde días.
- Se apaga con `plataforma.prueba_automatica`.

## Contratar, renovar, mejorar y bajar de plan

El monto lo calcula siempre el servidor (`POST /panel/suscripcion/cotizar` muestra lo mismo que se cobrará).

| Situación | Acción | Monto |
|---|---|---|
| En prueba, sin suscripción o vencida | `contratacion` | Precio de lista × periodos (1–12 meses o 1–2 años). Empieza al terminar la prueba, o ahora si no hay una vigente |
| Activa, mismo plan y ciclo | `renovacion` | Precio pactado × periodos. Extiende desde el fin del periodo actual |
| Activa, plan más caro con el mismo ciclo | `mejora` | (precio nuevo − pactado) × tiempo restante ÷ 30 o 365 días. Cambia el plan ya; la fecha de fin no cambia |
| Mejora cuyo prorrateo es menor que el mínimo cobrable (S/ 1.00, `min_amount` en `config/billing.php`) | `mejora_sin_cobro` | Se aplica al instante sin cobro |
| Bajar de plan o cambiar de ciclo durante un periodo pagado | `programar` | Sin cobro. Se guarda en `plan_programado_*` y el cron lo aplica al terminar el periodo; desde ahí se renueva a ese precio. Se puede anular |

Una orden nueva anula las pendientes anteriores de la misma barbería. Las órdenes vencen a las 24 h (`PAYMENT_ORDER_TTL_MINUTES`). Una barbería `pendiente` de aprobación, cancelada o con la suscripción suspendida por la plataforma no puede pagar en línea (`409`). Pagar exige correo verificado.

## Pago en línea

### Flujo

1. El propietario elige el plan → `POST /panel/suscripcion/pagar` crea la orden (`ordenes_pago`, código aleatorio de 24 hex) y la preferencia en la pasarela, y devuelve la URL de pago.
2. Paga en la página de Mercado Pago. **BARBERAPP nunca recibe ni guarda datos de tarjeta.**
3. Mercado Pago llama al webhook. Se verifica la firma, se registra el evento en `webhook_eventos` (idempotente) y **se consulta el pago a la API** (`GET /v1/payments/{id}`); nunca se confía en el cuerpo del webhook.
4. Si el pago está aprobado, su `external_reference` coincide con la orden y el monto y la moneda coinciden: dentro de una transacción con la orden bloqueada se aplica a la suscripción, se crea el pago `confirmado` (método `pasarela`) en `pagos_saas`, la orden queda `pagada`, se levanta la restricción si la había y se avisa al propietario (`pago_confirmado`).
5. Al volver a `suscripcion.html?orden=…`, la página consulta la orden (y con `payment_id` sincroniza contra la API si el webhook aún no llegó). Nunca da un pago por bueno desde la URL.

### Casos especiales

- **Monto distinto, suscripción que cambió entre tanto o segundo pago de una orden ya pagada:** no se aplica. Queda un pago `pendiente` en *Plataforma → Pagos* con la orden marcada «En revisión»; el equipo lo confirma (con los periodos que correspondan) o lo rechaza/reembolsa.
- **Rechazado:** la orden queda `fallida`; el propietario puede intentar otra vez.
- **Reembolso o contracargo en Mercado Pago:** el pago pasa a `reembolsado`. El periodo no se acorta (igual que en la Fase 9); si corresponde, la plataforma suspende la suscripción.
- **Pasarela caída al consultar el pago:** el webhook responde `503` para que Mercado Pago reintente; el evento queda `fallido` y el reintento se procesa.
- **Webhook repetido:** responde `200` con `duplicado` y no aplica dos veces.

### Configuración (`backend/.env`)

```ini
# none = sin pago en línea (pagos manuales de la Fase 9), sandbox = simulador local, mercadopago = real
PAYMENT_DRIVER=mercadopago
PAYMENT_ORDER_TTL_MINUTES=1440
MP_ACCESS_TOKEN=APP_USR-...          # Credencial de producción (o TEST-... de prueba) de tu aplicación
MP_WEBHOOK_SECRET=...                # Clave secreta de las notificaciones (Webhooks) de la aplicación
MP_STATEMENT_DESCRIPTOR=BARBERAPP    # Texto en el estado de cuenta de la tarjeta
MP_SIGNATURE_TOLERANCE=600           # Segundos de antigüedad aceptados en la firma
```

Pasos en Mercado Pago (cuenta de Perú):

1. En *Tus integraciones* crea una aplicación de **Checkout Pro** y copia el *Access Token* (primero el de prueba).
2. En *Webhooks* configura la URL `https://TU_DOMINIO/api/v1/webhooks/pagos/mercadopago` con el evento **Pagos** y copia la clave secreta en `MP_WEBHOOK_SECRET`.
3. `APP_URL` y `FRONTEND_URL` deben ser públicas y con HTTPS en producción: Mercado Pago necesita llegar al webhook, y solo con HTTPS se activa la vuelta automática (`auto_return`).
4. Prueba con usuarios y tarjetas de prueba de Mercado Pago antes de pasar al token de producción.

La firma `x-signature` se valida con HMAC-SHA256 del texto `id:{data.id};request-id:{x-request-id};ts:{ts};` (el `data.id` alfanumérico en minúsculas), con comparación de tiempo constante y tolerancia de `MP_SIGNATURE_TOLERANCE`. La integración usa la API REST con los streams de PHP (sin SDK ni curl obligatorio).

### Simulador local (`PAYMENT_DRIVER=sandbox`)

Para desarrollo y pruebas sin cuenta de Mercado Pago: el botón *Pagar* lleva a `pages/panel/pago-simulado.html`, donde se aprueba o rechaza la orden. El simulador envía al backend un webhook firmado (clave derivada de `APP_KEY`) que recorre el mismo `PaymentProcessor` que Mercado Pago. **Con `APP_ENV=production` el simulador se desactiva solo** (se comporta como `none`) y su endpoint responde 404.

## Vencimiento, avisos y restricción

El cron `suscripciones:vencer` (cada hora, con candado de archivo) hace, en orden:

1. Aplica los cambios de plan programados cuyo periodo terminó.
2. Marca `vencida` las suscripciones cuyo periodo terminó y avisa al propietario (`suscripcion_vencida`).
3. Restringe las barberías sin suscripción vigente pasados `plataforma.dias_gracia` días (3 por defecto) y avisa (`suscripcion_restringida`). Si se apaga `plataforma.restringir_sin_suscripcion`, libera las restringidas.
4. Avisa antes de vencer según `plataforma.avisos_vencimiento` (7, 3 y 1 días por defecto): `prueba_por_vencer` o `suscripcion_por_vencer`, una sola vez por umbral y periodo.
5. Marca `expirada` las órdenes pendientes vencidas.

Los avisos van por la bandeja y por correo a los propietarios, con plantillas editables en *Plataforma → Avisos → Suscripción y pagos* (variables `{barberia}`, `{plan}`, `{fecha}`, `{dias}`, `{monto}`).

**Barbería restringida** (`tenants.restringido_en`):

- No aparece en el marketplace ni en su enlace directo, y no recibe reservas nuevas (ni de clientes ni manuales del panel: `402 subscription_required`). Los clientes tampoco pueden reprogramar sus citas; sí cancelarlas.
- No puede crear ni reactivar sucursales o barberos.
- **Se mantiene todo lo demás:** las citas ya registradas, su atención y cobro, reprogramarlas desde el panel, reportes, clientes y la configuración. Nada se borra.
- Se levanta al instante cuando se confirma un pago (en línea o manual) o la plataforma registra una suscripción vigente.

El panel muestra un aviso fijo cuando faltan 7 días o menos, tras vencer (con la fecha en que se restringirá) y mientras está restringida.

## Endpoints

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/v1/panel/suscripcion` | `negocio.suscripcion.ver` |
| POST | `/api/v1/panel/suscripcion/cotizar` | `negocio.suscripcion.gestionar` |
| POST | `/api/v1/panel/suscripcion/pagar` | `negocio.suscripcion.gestionar` + correo verificado |
| POST | `/api/v1/panel/suscripcion/cambiar-plan` | `negocio.suscripcion.gestionar` |
| DELETE | `/api/v1/panel/suscripcion/cambio-programado` | `negocio.suscripcion.gestionar` |
| GET | `/api/v1/panel/suscripcion/ordenes/{codigo}` | `negocio.suscripcion.ver` |
| POST | `/api/v1/panel/suscripcion/ordenes/{codigo}/simular` | `negocio.suscripcion.gestionar` (solo simulador) |
| POST | `/api/v1/webhooks/pagos/{proveedor}` | Público, con firma |
| GET | `/api/v1/plataforma/ordenes` | `plataforma.pagos.gestionar` |

El permiso nuevo `negocio.suscripcion.gestionar` lo tiene el propietario (`negocio.*`). El administrador no ve la suscripción.

## Apps móviles (Apple y Google)

Las tiendas tienen reglas propias para cobrar suscripciones dentro de una app (en general exigen su sistema de compras para contenido digital y limitan los botones o enlaces a pagos externos; hay excepciones para servicios a empresas y cambios recientes por país). Mientras no se revise caso por caso, **la app nativa no muestra botones de pago**: en Capacitor (`window.Capacitor.isNativePlatform()`) la pantalla de suscripción muestra el plan, el uso y el historial, y dice que el pago se hace desde la web. Esto debe verificarse con las guías vigentes de App Store y Google Play antes de publicar (Fase 12).

## Impuestos y comprobantes (SUNAT)

**Pendiente, no implementado.** Cobrar a barberías en Perú requiere emitir comprobante electrónico (factura o boleta) por cada pago y definir si los precios incluyen IGV. Antes de cobrar en producción:

- Definir con un contador si los precios publicados incluyen IGV (18 %) y mostrarlo así en los planes.
- Pedir RUC o DNI y razón social al contratar (hoy no se piden).
- Integrar un proveedor de facturación electrónica (OSE/PSE) o emitir desde SUNAT, enlazando cada `pagos_saas` confirmado con su comprobante.
- Considerar la comisión de Mercado Pago y su retención en la conciliación.

## Pruebas

`tests/Integration/Billing` (34 pruebas): límites y funciones por plan, prueba automática, cotización y checkout con el simulador (contratación, renovación, mejora con prorrateo, cambios programados, montos y estados inválidos, revisión manual), Mercado Pago con la API simulada en memoria (forma de la preferencia, firma, consulta del pago, duplicados, reembolso, caída de la pasarela y sincronización al volver) y el cron (avisos por umbral sin repetirse, vencimiento, restricción tras la gracia, desbloqueo al pagar, órdenes expiradas y validación de la configuración).
