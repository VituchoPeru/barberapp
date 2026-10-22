# Marketplace, perfil público y QR (Fase 3)

El marketplace es la parte pública de BARBERAPP: cualquier persona, con o sin cuenta, puede buscar barberías, ver su perfil, guardar favoritas y llegar al flujo de reserva. Cada barbería tiene además un enlace directo `https://app.barberapp.pe/b/{slug}` y un código QR descargable que funciona aunque no aparezca en el listado.

## Pantallas

Todas están en `frontend/www/` y funcionan en el navegador del móvil (diseño desde 360 px, tabbar inferior, objetivos táctiles de 44 px) y en escritorio.

| Pantalla | Archivo | Qué hace |
|---|---|---|
| Splash | `index.html` | Comprueba `GET /health`. Si responde, lleva al onboarding la primera vez y luego a Inicio. Si no, muestra el error y un botón Reintentar. |
| Onboarding | `pages/onboarding.html` | 3 pasos (buscar, reservar, guardar). Se recuerda en `localStorage` que ya se vio. |
| Inicio | `pages/inicio.html` | Buscador, accesos por tipo de servicio, botón "Cerca" (pide permiso de ubicación), favoritas (con sesión), distritos y barberías recientes. |
| Explorar / resultados | `pages/explorar.html` | Búsqueda por nombre, filtros por distrito, tipo de servicio, rango de precio y orden. El estado vive en la URL (se puede compartir y el botón Atrás funciona). Paginación con "Cargar más". |
| Perfil de barbería | `pages/barberia.html?slug=…` | Portada, logo, estado abierto/cerrado según el horario real, pestañas Servicios, Equipo, Fotos y Ubicación y contacto. Botones Reservar, favorito, compartir y QR. |
| Favoritos | `pages/favoritos.html` | Barberías guardadas. Pide iniciar sesión y vuelve a la misma pantalla tras el login. |
| Reservar | `pages/reservar.html?slug=…` | Elige sede, servicios (solo combinaciones que un mismo barbero realiza) y barbero o "cualquiera". Desde la Fase 5 continúa con fecha, hora, resumen y confirmación (ver [RESERVAS.md](RESERVAS.md)). |
| Perfil público (panel) | `pages/panel/perfil-publico.html` | Para el propietario: requisitos para publicar, interruptor de visibilidad, enlace y QR, logo, portada, galería y datos de contacto. Se llega desde "Mi cuenta". |

## Reglas de visibilidad

| Situación | Listado y búsqueda | Enlace directo `/b/{slug}` |
|---|---|---|
| Activa + publicada + ≥1 sucursal activa + ≥1 servicio activo | Sí | Sí |
| Activa pero no publicada | No | Sí (útil para barberías que solo usan su QR) |
| Pendiente de aprobación, suspendida o cancelada | No | No (404, mismo mensaje que si no existiera) |

Dentro del perfil solo se muestran sucursales, servicios y barberos activos, y solo los barberos que tienen al menos un servicio activo. Para **publicar** el propietario necesita: sucursal activa, horario semanal, servicio activo y barbero activo. Si falta algo, la API responde `422 profile_incomplete` con la lista de faltantes y el panel los muestra.

Lo que **no** se muestra a propósito:

- **Calificaciones y reseñas.** No existen reservas atendidas que las respalden todavía; no se inventan estrellas.
- **Distancias** cuando la sucursal no tiene coordenadas o el usuario no compartió su ubicación. La sede demo de Chorrillos no tiene coordenadas para probar este caso.
- **Fotos de relleno.** Si la barbería no subió portada o logo se usa un fondo con su color e iniciales.

## API

Base: `/api/v1`. Formato de respuesta y errores igual que en [AUTENTICACION.md](AUTENTICACION.md).

### Públicas (sesión opcional)

Con sesión, las respuestas incluyen `favorito: true|false`; sin sesión, `favorito: null`. Una cookie vencida no rompe la página: se trata como visitante y se borra. Un `Authorization: Bearer` inválido sí devuelve 401, para que la app móvil renueve su token.

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/marketplace/filtros` | Distritos con barberías visibles, tipos de servicio en uso y rango de precios. |
| GET | `/marketplace/barberias` | Búsqueda. Parámetros: `q` (nombre, sin distinguir tildes), `distrito`, `tipo` (código de `tipos_servicio`), `precio_min`, `precio_max`, `lat` + `lng` (ambos o ninguno), `radio_km` (0.5–50), `orden` (`relevancia`, `nombre`, `precio`, `distancia`, `recientes`), `page`, `per_page` (máx. 48). `orden=distancia` exige coordenadas. |
| GET | `/marketplace/barberias/{slug}` | Perfil completo: sucursales con horarios y estado abierto/cerrado, servicios, barberos con los servicios que realizan, fotos, política de reserva y contacto (`whatsapp_url`, redes, sitio web). |
| GET | `/marketplace/barberias/{slug}/qr` | Código QR del enlace directo. `formato=svg|png`, `tam` (4–20 píxeles por módulo), `descargar=1` para `Content-Disposition: attachment`. Cacheable 24 h. |

El QR codifica `{PUBLIC_BOOKING_URL}/b/{slug}?o=qr` para que en el futuro se pueda medir cuántas visitas llegan desde el código impreso.

### Cliente (requiere sesión)

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/me/favoritos` | Barberías favoritas activas (máx. 200). |
| PUT | `/me/favoritos/{slug}` | Agrega. Idempotente. Crea el perfil de cliente si la cuenta aún no lo tenía. |
| DELETE | `/me/favoritos/{slug}` | Quita. Idempotente. |

### Panel de la barbería (permiso `negocio.perfil.gestionar`, cabecera `X-Tenant`)

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/panel/perfil-publico` | Datos públicos, requisitos, si está lista para publicar, enlace, fotos. |
| PUT | `/panel/perfil-publico` | `descripcion`, `telefono`, `whatsapp` (9 dígitos peruanos se normalizan a `+51…`), `instagram`, `facebook`, `tiktok` (acepta `@usuario` o la URL completa), `sitio_web`, `publicado_marketplace`. Se audita con valores anteriores y nuevos. |
| POST | `/panel/fotos?tipo=logo|portada|galeria&alt=…` | Cuerpo = la imagen en binario (`Content-Type: image/jpeg|png|webp`), máx. 5 MB. Logo y portada reemplazan a la anterior; la galería admite 12. |
| DELETE | `/panel/fotos/{id}` | Borra la foto y su archivo. Una foto de otra barbería responde 404. |

## Fotos

`ImageStore` (en `backend/app/Services/Media/`):

1. Rechaza por tamaño antes de leer la imagen y detecta el tipo por su contenido, no por la extensión ni por el `Content-Type`.
2. Rechaza imágenes de más de 8000 px por lado o 40 megapíxeles (evita bombas de descompresión) y demasiado pequeñas (logo < 64 px, resto < 320 px).
3. Corrige la orientación EXIF, reduce al tamaño máximo (logo 512, portada 1920, galería 1600 px) y **recodifica** a WebP (PNG si el GD del servidor no tiene WebP). Así se descartan metadatos como la ubicación GPS y cualquier contenido extra.
4. Guarda con nombre aleatorio en `MEDIA_PATH/tenants/{tenant_id}/`. La base solo acepta rutas con ese patrón.

`backend/public/media/.htaccess` impide ejecutar PHP y sirve los archivos con `X-Content-Type-Options: nosniff`. En Nginx y Apache el bloque equivalente lo genera `php bin/console despliegue:config` (plantillas en `infrastructure/`, ver [DESPLIEGUE.md](DESPLIEGUE.md)): las fotos viven en `MEDIA_PATH`, compartida entre versiones.

Variables de entorno: `MEDIA_PATH`, `MEDIA_URL`, `MEDIA_MAX_UPLOAD_BYTES`, `PUBLIC_BOOKING_URL`.

## Enlace directo `/b/{slug}`

`frontend/www/.htaccess` redirige `/b/{slug}` a `pages/barberia.html?slug={slug}` conservando la query (`?o=qr`). Para XAMPP el vhost necesita `AllowOverride FileInfo` (ver `infrastructure/xampp/httpd-vhosts.conf.example`).

## Datos demo

`php bin/console db:seed` crea, además de las barberías de la Fase 2:

| Slug | Estado | Para probar |
|---|---|---|
| `demo-centro` | publicada, 2 sedes con coordenadas | perfil completo, WhatsApp, barbero inactivo oculto |
| `demo-norte` | publicada, sede con coordenadas | búsqueda y distancias |
| `demo-sur` | publicada, sede en Chorrillos **sin** coordenadas | que no se invente distancia; filtros afeitado y color |
| `demo-privada` | activa, **no** publicada | no sale en el listado pero `/b/demo-privada` funciona |
| `demo-suspendida` | suspendida | 404 en todo |

Todos los nombres incluyen "Demo" y no tienen fotos: no se presentan como barberías reales.

## Pruebas

- PHPUnit: `MarketplaceApiTest`, `FavoritesTest`, `PublicProfileTest`, `MarketplaceUnitTest` y `QrCodeTest` (este último decodifica los QR generados con `zbarimg` si está instalado). Las pruebas de subida de fotos se omiten si PHP no tiene la extensión GD.
- Recorrido en navegador móvil (Playwright, 390×844) de todas las pantallas anteriores; ver la sección de pruebas del [README](../README.md).
