# Guía de compilación iOS

Cómo compilar, firmar y publicar la app de BARBERAPP para iPhone y iPad. La app empaqueta con **Capacitor 8** el mismo frontend de la web (`frontend/www`). Qué hace la app, cómo guarda la sesión y las reglas de pago de las tiendas: [APPS_MOVILES.md](../APPS_MOVILES.md).

> **Estado de la verificación:** iOS solo se compila en macOS con Xcode. En el entorno Linux donde se escribió esta guía (22-23 de octubre de 2026) pasaron `npm run configurar -- --produccion` y `npx cap sync` (el paquete Swift con los 6 plugins se generó bien). `npx cap doctor` informa *Xcode is not installed*, como es de esperar. El proyecto de Xcode se revisó leyendo `project.pbxproj`, `Info.plist` y `App.entitlements`. **No se compiló ni se probó en un iPhone**: la primera compilación real, TestFlight y la prueba de push quedan pendientes y se hacen con estos pasos.

## Contenido

1. [Requisitos](#1-requisitos)
2. [Preparar el proyecto](#2-preparar-el-proyecto)
3. [Compilar y probar en el simulador o en un iPhone](#3-compilar-y-probar-en-el-simulador-o-en-un-iphone)
4. [Firma y capacidades](#4-firma-y-capacidades)
5. [Notificaciones push (APNs + Firebase)](#5-notificaciones-push-apns--firebase)
6. [Enlaces universales](#6-enlaces-universales)
7. [Versión](#7-versión)
8. [TestFlight](#8-testflight)
9. [Publicar en App Store](#9-publicar-en-app-store)
10. [Problemas frecuentes](#10-problemas-frecuentes)

## 1. Requisitos

| Herramienta | Versión |
|---|---|
| macOS | el que exija tu versión de Xcode |
| Xcode | **26 o superior** (lo exige App Store Connect para subir builds) |
| Node.js | 22 o superior |
| Cuenta de Apple Developer | de pago, para firmar, usar push y publicar |
| iPhone o iPad | **iOS 15** o superior |

Datos del proyecto:

| Dato | Valor | Dónde |
|---|---|---|
| Bundle Identifier | `pe.barberapp.app` | `frontend/capacitor.config.json` y el target *App* |
| Dependencias nativas | Swift Package Manager (sin CocoaPods) | `ios/App/CapApp-SPM/Package.swift`, lo escribe `npx cap sync` |
| Capacidades | Push Notifications, Associated Domains | `ios/App/App/App.entitlements` |
| Permisos | Ubicación (al usar), cámara y fotos | `ios/App/App/Info.plist` |
| Privacidad declarada | `PrivacyInfo.xcprivacy` | `ios/App/App/` |

## 2. Preparar el proyecto

```bash
cd frontend
npm ci
BARBERAPP_API_URL=https://api.tu-dominio.pe/api/v1 \
BARBERAPP_WEB_URL=https://app.tu-dominio.pe \
npm run configurar -- --produccion
npx cap sync ios
```

`npm run configurar`:

- Escribe la URL de la API en `www/js/runtime-config.js`. La app sirve sus archivos desde `capacitor://localhost`, así que no puede calcularla como la web.
- Pone el dominio de los enlaces universales en `App.entitlements` (`applinks:…`).
- Con `--produccion`, exige HTTPS y rechaza `localhost`.

`runtime-config.js` se guarda **vacío** en el repositorio: no confirmes la versión configurada.

En el servidor, la API debe aceptar el origen de la app: agrega `capacitor://localhost` a `CORS_ALLOWED_ORIGINS` en `backend/.env`.

## 3. Compilar y probar en el simulador o en un iPhone

```bash
npm run ios      # configura, sincroniza y abre ios/App/App.xcodeproj en Xcode
```

En Xcode:

1. Elige el esquema **App** y un simulador o un iPhone conectado.
2. Pulsa **Run** (⌘R). La primera vez Xcode resuelve los paquetes Swift; tarda unos minutos.
3. Para ver la consola de la WebView: Safari en la Mac → *Desarrollo* → tu dispositivo → la página de la app. Hay que activar *Mostrar el menú Desarrollo* en las preferencias de Safari y, en el iPhone, *Ajustes → Safari → Avanzado → Inspector web*.

El simulador no recibe push reales de APNs. Pruébalos en un iPhone.

Con XAMPP en tu Mac, el simulador llega a `http://localhost`. Configura `BARBERAPP_API_URL=http://localhost/barberapp/backend/public/api/v1` **sin** `--produccion`. El proyecto solo permite HTTPS (App Transport Security); para probar con `http` en el simulador agrega temporalmente una excepción para `localhost` en `Info.plist` y no la confirmes.

## 4. Firma y capacidades

En Xcode → target **App** → **Signing & Capabilities**:

1. Marca *Automatically manage signing* y elige tu **Team**.
2. Comprueba que el Bundle Identifier sea `pe.barberapp.app`. Si usas otro, cámbialo también en `capacitor.config.json` y en Firebase.
3. Las capacidades **Push Notifications** y **Associated Domains** ya están declaradas en `App.entitlements`. Xcode las registra en tu cuenta de Apple Developer al firmar.

## 5. Notificaciones push (APNs + Firebase)

El backend envía todos los push por **Firebase Cloud Messaging**, también a iPhone (ver [RECORDATORIOS.md](../RECORDATORIOS.md#2-push-con-firebase)). Firebase los entrega por APNs.

1. **Apple Developer → Keys:** crea una clave con **Apple Push Notifications service (APNs)** y descarga el archivo `.p8`. Anota el *Key ID* y tu *Team ID*.
2. **Firebase** (el mismo proyecto que el backend usa en `FCM_CREDENTIALS`) → *Configuración del proyecto* → *Cloud Messaging* → *Configuración de apps de Apple*: sube la clave `.p8` con su Key ID y Team ID.
3. En Firebase agrega una app iOS con el Bundle ID `pe.barberapp.app`. Descarga `GoogleService-Info.plist` a `frontend/ios/App/App/` (git lo ignora) y, en Xcode, agrégalo al target *App* (arrastrar al proyecto, *Copy items if needed*, target *App* marcado).
4. En Xcode: *File → Add Package Dependencies…* → `https://github.com/firebase/firebase-ios-sdk` → agrega solo el producto **FirebaseMessaging** al target *App*.
5. Compila. `AppDelegate.swift` detecta Firebase (`#if canImport(FirebaseMessaging)`) y convierte el token de APNs en un token de FCM que la app registra en el backend.

Sin los pasos 3 y 4 la app compila y funciona, pero no registra el teléfono para push.

El permiso se pide al iniciar sesión o con **Activar avisos en este teléfono** en *Mi cuenta*, nunca al abrir la app. En iPhone, el recordatorio trae el botón **Confirmar asistencia**.

Prueba desde el servidor: `php bin/console push:probar cliente1@barberapp.test` (con la cuenta iniciada en el iPhone).

## 6. Enlaces universales

Los enlaces `https://app.tu-dominio.pe/b/{slug}` abren la app si está instalada; si no, la web.

1. Copia `infrastructure/well-known/apple-app-site-association.example` y reemplaza `REEMPLAZAR_TEAM_ID` por tu Team ID.
2. Publícalo en `https://app.tu-dominio.pe/.well-known/apple-app-site-association`: **sin extensión**, con `Content-Type: application/json` y **sin redirecciones**. La configuración de Nginx que genera `despliegue:config` ya sirve esa ruta.
3. Comprueba que `App.entitlements` tenga `applinks:app.tu-dominio.pe` (lo pone `npm run configurar`).
4. Apple guarda el archivo en su CDN: después de publicarlo, reinstala la app para probar.

## 7. Versión

En el target *App* → *General*:

- **Version** (`MARKETING_VERSION`): la que ve la persona, por ejemplo `1.0.1`.
- **Build** (`CURRENT_PROJECT_VERSION`): súbelo en cada envío a App Store Connect.

## 8. TestFlight

1. Elige el destino **Any iOS Device (arm64)**.
2. *Product → Archive*.
3. En el *Organizer*: **Distribute App → App Store Connect → Upload**.
4. En App Store Connect → *TestFlight*: agrega testers internos (hasta 100, sin revisión). Para testers externos, envía la build a revisión beta.
5. Prueba en iPhones reales: login, reserva, push, enlaces universales, descargas por la hoja **Compartir** y eliminar la cuenta.

## 9. Publicar en App Store

1. **Ficha:**
   - Capturas reales. Pueden ser de la demo: `node scripts/capturas-manuales.mjs` genera capturas de 390×844 (Apple pide además tamaños específicos por dispositivo; tómalas en el simulador).
   - Descripción, palabras clave y URL de soporte.
   - **Política de privacidad:** `https://app.tu-dominio.pe/pages/legal/privacidad.html`.
2. **Privacidad de la app** (App Store Connect), de acuerdo con `PrivacyInfo.xcprivacy`: nombre, correo, teléfono, ID de usuario, token del dispositivo, fotos y otro contenido del usuario. Todo vinculado a la persona, solo para el funcionamiento de la app y **sin rastreo**. La ubicación de **Cerca** se envía solo para ordenar esa búsqueda y no se guarda, por eso no figura en el manifiesto; si cambia ese uso, actualiza el manifiesto y esta sección.
3. **Eliminar la cuenta desde la app** (guía 5.1.1(v)): *Mi cuenta → Eliminar mi cuenta*. Indícalo en las notas para el revisor.
4. **Cuenta de demostración** para el revisor (cliente y propietario) con datos de prueba, en *Información de revisión de la app*.
5. **Pagos:** la app no vende el plan de la barbería ni muestra precios, prueba, cambios de plan ni textos que lleven a pagar fuera (guías 3.1.1 y 3.1.3). Las citas se pagan en el local, que es un servicio físico. Si Apple pide la compra integrada, las opciones están en [APPS_MOVILES.md](../APPS_MOVILES.md#6-pagos-y-tiendas).
6. **Cifrado:** `ITSAppUsesNonExemptEncryption = false` (solo HTTPS), así que no hace falta documentación de exportación.
7. Envía a revisión.

## 10. Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| La app se queda en la pantalla de inicio con un error de conexión | Falta la URL en `runtime-config.js`: ejecuta `npm run configurar` con `BARBERAPP_API_URL` y `npx cap sync ios`. |
| Error de CORS en el inspector de Safari | Falta `capacitor://localhost` en `CORS_ALLOWED_ORIGINS` del backend. |
| `No such module 'Capacitor'` o paquetes sin resolver | Ejecuta `npx cap sync ios` y luego *File → Packages → Reset Package Caches* en Xcode. |
| *Signing requires a development team* | Elige tu Team en *Signing & Capabilities*. |
| No llegan los push | Falta `GoogleService-Info.plist` o el paquete FirebaseMessaging, la clave `.p8` no está en Firebase, el permiso está denegado, se probó en el simulador o el backend tiene `PUSH_DRIVER=log`. |
| `/b/{slug}` abre Safari y no la app | El archivo de Apple no se publicó, tiene otro Team ID, redirige o tiene extensión. Reinstala la app después de corregirlo. |
| App Store Connect rechaza la build | Sube el número de **Build** y usa la versión de Xcode que exige Apple. |
