# PWA, Android e iOS (Fase 12)

Un solo frontend (`frontend/www`) se publica como web, se instala como **PWA** y se empaqueta como app de **Android** e **iOS** con **Capacitor 8**. Todo lo que depende del sistema operativo pasa por un adaptador (`js/native.js`); el resto del código es el mismo en las tres formas.

## 1. Qué se hizo

| Tema | Implementación |
|---|---|
| Manifest | `www/manifest.webmanifest`: nombre, colores, `display: standalone`, iconos normales y *maskable* (192 y 512), atajos a Explorar, Mis citas y Mi cuenta. |
| Service worker | `www/sw.js`. Guarda solo archivos estáticos. **Nunca** guarda respuestas de la API ni peticiones POST/PUT/DELETE. Páginas: red primero y, sin red, la copia guardada o `offline.html`. CSS/JS/fuentes/iconos: copia guardada y actualización en segundo plano. Al publicar, sube `VERSION`. |
| Iconos y splash | Generados desde `frontend/resources/emblema.svg` con `npm run recursos` (PWA, Android: launcher, adaptable, monocromo, icono de notificación, splash de Android 12+ y anteriores; iOS: AppIcon 1024 y splash). |
| Pantalla de instalación | `pages/instalar.html` (botón "Instalar ahora" cuando el navegador lo permite y pasos para Safari, Chrome Android y escritorio) y una tarjeta descartable en Inicio. |
| Sin conexión | Aviso fijo "Sin conexión" en todas las pantallas; `offline.html` para pantallas no guardadas. Reservar comprueba la conexión antes de enviar y la reserva solo existe con la respuesta del servidor (el reintento usa la misma clave de idempotencia). |
| Fuente | Manrope servida desde `www/assets/fonts` (OFL): funciona sin conexión y dentro de la app, y no envía la IP de los visitantes a Google. |
| Sesión en la app | Login con `canal: "movil"`: access token de 15 min y refresh token rotativo. El refresh token va al llavero (Keychain / Keystore, plugin `capacitor-secure-storage-plugin`); el access token, a `sessionStorage` de la WebView. Una sola renovación a la vez; si el servidor responde `401 invalid_token` se renueva y se repite la petición. |
| Push | `@capacitor/push-notifications`. El permiso se pide al iniciar sesión o con "Activar avisos en este teléfono" en Mi cuenta, nunca al abrir la app. El token se registra en `POST /me/dispositivos` y se da de baja al cerrar sesión. Tocar un aviso abre la cita, la bandeja o "Confirmar asistencia". En iPhone el aviso trae el botón **Confirmar asistencia**. |
| Descargas | Reportes CSV, Excel y **PDF**, QR y `.ics` de la cita: en web se descargan; en la app se guardan en la caché y se abre la hoja **Compartir** (Guardar en Archivos, WhatsApp, correo…). |
| PDF de reportes | Nuevo `formato=pdf` en `GET /panel/reportes/exportar`, generado en el servidor sin dependencias (`PdfWriter`). La WebView no tiene diálogo de impresión. En web siguen "Imprimir con gráficos" y el PDF. |
| Enlaces directos | `https://app.barberapp.pe/b/{slug}` abre la app si está instalada (Android App Links e iOS Universal Links) y si no, la web. |
| Pagos | La app no muestra precios, botones de compra, cambios de plan ni textos que lleven a pagar fuera (ver §6). |

## 2. Versiones verificadas (22 de octubre de 2026)

| Herramienta | Versión | Nota |
|---|---|---|
| Node.js | 22 o superior | Solo para compilar (Capacitor 8 exige Node 22). |
| @capacitor/cli, core, android, ios | 8.5.3 | |
| Plugins oficiales | app 8.1.2, filesystem 8.1.4, push-notifications 8.1.3, share 8.0.3, splash-screen 8.0.2 | Barras de sistema con `SystemBars`, incluido en el núcleo de Capacitor 8. |
| capacitor-secure-storage-plugin | 0.13.0 | Requiere `@capacitor/core >= 8`. |
| Android | compileSdk / targetSdk **36**, minSdk **24** (Android 7) | Gradle 8.14.3, Android Gradle Plugin 8.13.0, **JDK 21**, Android Studio actual. Firebase Messaging 25.0.1 (lo trae el plugin). |
| iOS | Mínimo **iOS 15**, Swift Package Manager | Xcode 26 o superior en macOS (exigencia actual de App Store Connect). |

Las versiones quedan fijas en `frontend/package.json` (sin `^`). `npm audit` informa un aviso moderado en `uuid`, dependencia de la herramienta `@capacitor/cli` (no viaja dentro de la app); se resolverá al actualizar la CLI.

## 3. Configurar y sincronizar

```bash
cd frontend
npm ci                                   # instala Capacitor y plugins
BARBERAPP_API_URL=https://api.barberapp.pe/api/v1 \
BARBERAPP_WEB_URL=https://app.barberapp.pe \
npm run configurar -- --produccion       # escribe www/js/runtime-config.js y el dominio de los enlaces
npx cap sync                             # copia www/ a Android e iOS y actualiza plugins
npm run verificar                        # comprueba la PWA (núcleo del SW, iconos, pantallas, imports)
```

- `runtime-config.js` se confirma en el repositorio **vacío**; no subas la versión configurada.
- El backend debe aceptar los orígenes de la app en `CORS_ALLOWED_ORIGINS`: `https://localhost` (Android) y `capacitor://localhost` (iOS). La API expone `Content-Disposition` para que la app conozca el nombre de cada archivo.
- **Probar con XAMPP en un teléfono Android:** compila en modo debug, conecta el teléfono por USB y ejecuta `adb reverse tcp:8000 tcp:80`; usa `BARBERAPP_API_URL=http://localhost:8000/barberapp/backend/public/api/v1`. Solo las compilaciones debug permiten `http` hacia `localhost` (`android/app/src/debug`).

## 4. Android

### Compilar un APK de pruebas

```bash
npm run android            # configura, sincroniza y abre Android Studio
# o sin Android Studio:
npm run android:apk        # android/app/build/outputs/apk/debug/app-debug.apk
```

Identificador: `pe.barberapp.app`. La versión se cambia en `android/gradle.properties` (`barberappVersionCode` siempre crece; `barberappVersionName` es la visible).

### Firmar la versión de Google Play (AAB)

1. Crea la clave de subida una sola vez y guárdala fuera del repositorio:
   `keytool -genkeypair -v -keystore barberapp-upload.jks -alias barberapp -keyalg RSA -keysize 4096 -validity 10000`
2. Crea `android/keystore.properties` (ignorado por git):
   ```properties
   storeFile=/ruta/segura/barberapp-upload.jks
   storePassword=…
   keyAlias=barberapp
   keyPassword=…
   ```
   O usa variables de entorno en CI: `BARBERAPP_KEYSTORE`, `BARBERAPP_KEYSTORE_PASSWORD`, `BARBERAPP_KEY_ALIAS`, `BARBERAPP_KEY_PASSWORD`.
3. `npm run android:aab` → `android/app/build/outputs/bundle/release/app-release.aab`. Sin clave configurada el AAB sale sin firmar y Play lo rechaza.
4. En Play Console activa **Firma de apps de Play**; Google firma lo que se instala con su certificado.

### Push en Android

1. En Firebase crea el proyecto (el mismo que usa el backend en `FCM_CREDENTIALS`) y agrega la app Android `pe.barberapp.app`.
2. Descarga `google-services.json` a `android/app/` (ignorado por git). Sin ese archivo la app compila, pero no recibe push.
3. El canal `citas` (importancia alta) lo crea la app; el icono pequeño es `ic_stat_barberapp` y el color, el dorado de la marca.

### Enlaces directos (App Links)

Publica en `https://app.barberapp.pe/.well-known/assetlinks.json` el archivo de `infrastructure/well-known/assetlinks.json.example` con la huella SHA-256 del **certificado de firma de Play** (Play Console → Integridad de la app). La configuración de Nginx de referencia ya sirve esa ruta.

### Permisos

| Permiso | Para qué | Cuándo se pide |
|---|---|---|
| `INTERNET` | API | No se pide (normal). |
| `POST_NOTIFICATIONS` | Recordatorios (Android 13+) | Al iniciar sesión o en Mi cuenta. |
| `ACCESS_COARSE_LOCATION`, `ACCESS_FINE_LOCATION` | "Cerca de mí" en el marketplace | Solo al tocar "Cerca"; el sistema deja elegir ubicación aproximada. |

`allowBackup=false` y reglas de extracción vacías: la sesión y los datos locales no se copian a la nube ni a otro teléfono.

## 5. iOS (requiere macOS con Xcode)

```bash
npm run ios                # configura, sincroniza y abre Xcode
```

En Xcode, con la cuenta del equipo de Apple Developer:

1. **Signing & Capabilities** → elige el equipo. El Bundle Identifier es `pe.barberapp.app`. El archivo `App/App.entitlements` ya declara **Push Notifications** y **Associated Domains** (`applinks:app.barberapp.pe`).
2. **Push en iOS** (el backend envía todo por FCM):
   - En Apple Developer crea una clave APNs (.p8) y súbela a Firebase → Configuración → Cloud Messaging.
   - En Firebase agrega la app iOS y copia `GoogleService-Info.plist` a `ios/App/App/` (ignorado por git) y agrégalo al target en Xcode.
   - File → Add Package Dependencies → `https://github.com/firebase/firebase-ios-sdk` → producto **FirebaseMessaging**.
   - `AppDelegate.swift` ya convierte el token de APNs en token de FCM cuando Firebase está presente. Sin Firebase la app compila y funciona, pero no registra el teléfono para push.
3. **Enlaces universales:** publica `https://app.barberapp.pe/.well-known/apple-app-site-association` desde `infrastructure/well-known/apple-app-site-association.example` con tu Team ID (sin extensión, `application/json`, sin redirecciones).
4. **Versión:** `MARKETING_VERSION` y `CURRENT_PROJECT_VERSION` del target App.
5. **TestFlight:** Product → Archive → Distribute App → App Store Connect → Upload. En App Store Connect agrega testers internos y, para externos, envía la build a revisión beta.
6. **App Store:** completa la ficha (capturas reales, descripción, URL de soporte y de la política de privacidad), la sección **Privacidad de la app** de acuerdo con `App/PrivacyInfo.xcprivacy` (nombre, correo, teléfono, ID de usuario, token del dispositivo, fotos subidas por la barbería; sin rastreo) y envía a revisión.

Textos de permisos (`Info.plist`): ubicación ("Cerca de mí"), cámara y fotos (subir fotos del perfil). La app solo usa HTTPS, por eso declara `ITSAppUsesNonExemptEncryption = false`.

## 6. Pagos y tiendas

Revisado el 22 de octubre de 2026 con las [App Store Review Guidelines](https://developer.apple.com/app-store/review/guidelines/) (3.1.1, 3.1.3) y la [política de Pagos de Google Play](https://support.google.com/googleplay/android-developer/answer/9858738).

| Qué se cobra | Regla | Qué hace BARBERAPP |
|---|---|---|
| La cita del cliente en la barbería | Servicio físico consumido fuera de la app: **no** usa la compra integrada (Apple 3.1.3(e), Google Pagos §3). | Las citas no se cobran en la app; si algún día se cobran adelantos se puede usar Mercado Pago dentro de la app. |
| El plan SaaS de la barbería | Es "software y servicios en la nube": si se vende **dentro** de la app, Apple y Google exigen su sistema de compras. Tampoco se puede dirigir a pagar fuera (botones, enlaces, textos). La excepción 3.1.3(f) de Apple ("app gratuita complementaria de una herramienta web") la aplican los revisores solo a VoIP, almacenamiento, correo y hosting. | La app **no vende** el plan: no muestra precios, botones de pago, prueba ni cambio de plan, ni textos como "paga desde la web". Solo muestra el estado, el uso y el historial. La contratación y el pago siguen en la web (Fase 10). |

Riesgo que queda: Apple puede pedir que la gestión del plan esté disponible por compra integrada aunque no se venda en la app. Si ocurre, las salidas son (a) ofrecer el plan también con compra integrada (comisión de Apple/Google), o (b) dejar la app nativa para clientes y barberos y que los propietarios gestionen el plan solo en la web/PWA. Es una decisión de negocio; no se implementó compra integrada.

### Antes de enviar a las tiendas (bloqueante)

- ✅ **Eliminar la cuenta desde la app** (Apple 5.1.1(v)): *Mi cuenta → Eliminar mi cuenta* (Fase 13). Enlace web para Google Play: `https://<tu-dominio>/pages/legal/eliminar-cuenta.html`.
- ✅ **Política de privacidad y términos** en `pages/legal/` (Fase 13). Falta: completar los datos del titular en el panel, revisión legal y enlazar `pages/legal/privacidad.html` en la ficha de cada tienda. Ver [SEGURIDAD.md](SEGURIDAD.md).
- Formulario **Seguridad de los datos** de Google Play y **Privacidad de la app** de Apple con los datos de la tabla anterior.
- Cuenta de demostración para los revisores (propietario y cliente) con datos de prueba.

## 7. Pruebas

- Backend: `PdfWriterTest` (estructura con tabla xref exacta, texto en español con WinAnsi, números, cortes con "…", paginación con encabezado repetido, horizontal para tablas anchas) y `PanelAnalyticsTest::testPdfExportForTheNativeApp` (permisos, auditoría, formato horizontal del detalle).
- `npm run verificar`: núcleo del service worker, iconos del manifiesto, manifiesto y `shell.js` en las 46 pantallas, imports y versiones fijadas.
- E2E en Chromium (390×844 y escritorio), 46 comprobaciones: service worker y caché sin datos de la API, fuente local sin peticiones a terceros, tarjeta e instalación, pantalla sin conexión con el servidor apagado, PDF en web y, **con Capacitor simulado** (plugins falsos inyectados en la página): login móvil con tokens en el llavero, renovación única con rotación, reintento tras `401 invalid_token`, permiso y registro push, canal de Android, descargas PDF/Excel/QR por Compartir, suscripción sin compras, toque en avisos (incluida URL externa rechazada), enlace directo `/b/{slug}`, cierre de sesión que da de baja el teléfono, y splash sin URL configurada.

**No verificado aquí:** compilar el APK/AAB (el entorno de pruebas no puede descargar el Android SDK) ni el proyecto de iOS (requiere macOS y Xcode). `npx cap sync` y `npx cap doctor` pasan para Android; el proyecto de Xcode se validó leyendo el `project.pbxproj`. La primera compilación real y la prueba de push en teléfonos quedan como paso de verificación.
