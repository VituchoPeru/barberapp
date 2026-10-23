# Guía de compilación Android

Cómo compilar, firmar y publicar la app de BARBERAPP para Android. La app empaqueta con **Capacitor 8** el mismo frontend de la web (`frontend/www`). Qué hace la app, cómo guarda la sesión y las reglas de pago de las tiendas: [APPS_MOVILES.md](../APPS_MOVILES.md).

> **Estado de la verificación:** en el entorno donde se escribió esta guía (22-23 de octubre de 2026) pasaron `npm run configurar -- --produccion`, `npx cap sync` y `npx cap doctor` (*Android looking great*). **No se compiló el APK ni el AAB**: ese entorno no puede descargar el Android SDK. La primera compilación real y la prueba de push en un teléfono quedan pendientes y se hacen con estos pasos.

## Contenido

1. [Requisitos](#1-requisitos)
2. [Preparar el proyecto](#2-preparar-el-proyecto)
3. [APK de pruebas](#3-apk-de-pruebas)
4. [Probar contra XAMPP en un teléfono](#4-probar-contra-xampp-en-un-teléfono)
5. [Notificaciones push (Firebase)](#5-notificaciones-push-firebase)
6. [Versión y firma para Google Play](#6-versión-y-firma-para-google-play)
7. [Enlaces directos (App Links)](#7-enlaces-directos-app-links)
8. [Publicar en Google Play](#8-publicar-en-google-play)
9. [Actualizar la app](#9-actualizar-la-app)
10. [Problemas frecuentes](#10-problemas-frecuentes)

## 1. Requisitos

| Herramienta | Versión |
|---|---|
| Node.js | 22 o superior (lo exige Capacitor 8) |
| JDK | **21** (Android Studio trae uno) |
| Android Studio | actual, con Android SDK Platform **36** y Build-Tools |
| Gradle / Android Gradle Plugin | 8.14.3 / 8.13.0 (los descarga el *wrapper*, no hace falta instalarlos) |
| Teléfono o emulador | Android **7.0 (API 24)** o superior |

Datos del proyecto:

| Dato | Valor | Dónde |
|---|---|---|
| Identificador | `pe.barberapp.app` | `frontend/capacitor.config.json` y `android/app/build.gradle` |
| compileSdk / targetSdk / minSdk | 36 / 36 / 24 | `android/variables.gradle` |
| Versión | `barberappVersionCode`, `barberappVersionName` | `android/gradle.properties` |
| Dependencias de Capacitor y plugins | versiones fijas, sin `^` | `frontend/package.json` |

## 2. Preparar el proyecto

```bash
cd frontend
npm ci
BARBERAPP_API_URL=https://api.tu-dominio.pe/api/v1 \
BARBERAPP_WEB_URL=https://app.tu-dominio.pe \
npm run configurar -- --produccion
npx cap sync android
npm run verificar
```

`npm run configurar`:

- Escribe la URL de la API en `www/js/runtime-config.js`. La app sirve sus archivos desde `https://localhost`, así que no puede calcularla como la web.
- Pone el dominio de los enlaces directos en `android/gradle.properties` (`appLinkHost`).
- Con `--produccion`, exige HTTPS y rechaza `localhost`.

`runtime-config.js` se guarda **vacío** en el repositorio: no confirmes la versión configurada. Nada de lo que se configura aquí es secreto, porque viaja dentro de la app.

En el servidor, la API debe aceptar el origen de la app: agrega `https://localhost` a `CORS_ALLOWED_ORIGINS` en `backend/.env`.

## 3. APK de pruebas

Con Android Studio:

```bash
npm run android        # configura, sincroniza y abre el proyecto en Android Studio
```

Luego elige un teléfono o emulador y pulsa **Run**.

Sin Android Studio (con el SDK instalado y `ANDROID_HOME` definido):

```bash
npm run android:apk    # → android/app/build/outputs/apk/debug/app-debug.apk
adb install -r android/app/build/outputs/apk/debug/app-debug.apk
```

`capacitor.config.json` deja la depuración de la WebView apagada (`webContentsDebuggingEnabled: false`) en todas las compilaciones. Para ver la consola mientras desarrollas, cámbiala a `true` en tu copia, ejecuta `npx cap sync android`, compila en debug y abre `chrome://inspect` en Chrome de escritorio con el teléfono conectado por USB. No confirmes ese cambio.

## 4. Probar contra XAMPP en un teléfono

Solo las compilaciones **debug** permiten `http` hacia `localhost` (configuración en `android/app/src/debug`).

```bash
adb reverse tcp:8000 tcp:80        # el puerto 8000 del teléfono llega al Apache de tu PC
BARBERAPP_API_URL=http://localhost:8000/barberapp/backend/public/api/v1 npm run configurar
npx cap sync android
npm run android:apk && adb install -r android/app/build/outputs/apk/debug/app-debug.apk
```

En `backend/.env` agrega `https://localhost` a `CORS_ALLOWED_ORIGINS`. Inicia sesión con un usuario demo (ver [Manual de instalación](../MANUAL_INSTALACION.md#7-usuarios-de-demostración)).

Al terminar, vuelve a dejar `runtime-config.js` como estaba: `git checkout -- www/js/runtime-config.js`.

## 5. Notificaciones push (Firebase)

El backend envía todos los push por **Firebase Cloud Messaging** (ver [RECORDATORIOS.md](../RECORDATORIOS.md#2-push-con-firebase)).

1. En la consola de Firebase usa el **mismo proyecto** que tiene el backend en `FCM_CREDENTIALS`.
2. Agrega una app Android con el paquete `pe.barberapp.app`.
3. Descarga `google-services.json` a `frontend/android/app/`. Git lo ignora: no lo subas al repositorio.
4. Compila de nuevo. Sin ese archivo la app compila y funciona, pero no recibe push.

Comportamiento: la app crea el canal `citas` (importancia alta). El icono pequeño es `ic_stat_barberapp` y el color, el dorado de la marca. El permiso de notificaciones (Android 13+) se pide al iniciar sesión o con **Activar avisos en este teléfono** en *Mi cuenta*, nunca al abrir la app.

Prueba desde el servidor: `php bin/console push:probar cliente1@barberapp.test` (con la cuenta iniciada en el teléfono).

### Permisos que declara la app

| Permiso | Para qué | Cuándo se pide |
|---|---|---|
| `INTERNET` | API | No se pide (permiso normal). |
| `POST_NOTIFICATIONS` | Recordatorios (Android 13+) | Al iniciar sesión o en *Mi cuenta*. |
| `ACCESS_COARSE_LOCATION`, `ACCESS_FINE_LOCATION` | Botón **Cerca** del marketplace | Solo al tocar **Cerca**. El sistema deja elegir ubicación aproximada. |

`allowBackup=false` y las reglas de extracción vacías evitan que la sesión y los datos locales se copien a la nube o a otro teléfono.

## 6. Versión y firma para Google Play

**Versión.** En `android/gradle.properties`:

```properties
barberappVersionCode=2        # entero; súbelo SIEMPRE en cada envío a Play
barberappVersionName=1.0.1    # la versión que ve la persona
```

**Clave de subida.** Créala una sola vez y guárdala **fuera del repositorio**, con copia de seguridad. Si la pierdes, Google permite reemplazarla, pero el trámite toma días.

```bash
keytool -genkeypair -v -keystore barberapp-upload.jks -alias barberapp \
  -keyalg RSA -keysize 4096 -validity 10000
```

Indica la clave de una de estas dos formas:

- Archivo `frontend/android/keystore.properties` (git lo ignora):

  ```properties
  storeFile=/ruta/segura/barberapp-upload.jks
  storePassword=…
  keyAlias=barberapp
  keyPassword=…
  ```

- Variables de entorno (CI): `BARBERAPP_KEYSTORE`, `BARBERAPP_KEYSTORE_PASSWORD`, `BARBERAPP_KEY_ALIAS` y `BARBERAPP_KEY_PASSWORD`. Tienen prioridad sobre el archivo.

**Compilar el AAB firmado:**

```bash
npm run android:aab    # → android/app/build/outputs/bundle/release/app-release.aab
```

Sin clave configurada el AAB sale sin firmar y Play lo rechaza. Comprueba la firma con:

```bash
jarsigner -verify -verbose -certs android/app/build/outputs/bundle/release/app-release.aab | tail -3
```

## 7. Enlaces directos (App Links)

Los enlaces `https://app.tu-dominio.pe/b/{slug}` abren la app si está instalada; si no, la web.

1. En Play Console → *Integridad de la app* → *Firma de apps*, copia la huella **SHA-256 del certificado de firma de la app** (el de Google, no el de tu clave de subida).
2. Copia `infrastructure/well-known/assetlinks.json.example`, pon esa huella y publícalo en `https://app.tu-dominio.pe/.well-known/assetlinks.json`. La configuración de Nginx que genera `despliegue:config` ya sirve esa ruta.
3. Verifica en el teléfono: `adb shell pm get-app-links pe.barberapp.app` debe mostrar el dominio como `verified`.

## 8. Publicar en Google Play

1. Crea la app en Play Console con el paquete `pe.barberapp.app` y activa **Firma de apps de Play**.
2. Sube el AAB a **Prueba interna**, agrega testers y prueba en teléfonos reales: login, reserva, push, enlaces directos, descargas y eliminar cuenta.
3. Completa la ficha:
   - Capturas reales. Pueden ser de la demo: `node scripts/capturas-manuales.mjs` genera capturas de 390×844.
   - Descripción y categoría.
   - **Política de privacidad:** `https://app.tu-dominio.pe/pages/legal/privacidad.html`.
   - **Eliminación de cuenta:** `https://app.tu-dominio.pe/pages/legal/eliminar-cuenta.html`. Google exige un enlace web además de la opción dentro de la app (*Mi cuenta → Eliminar mi cuenta*).
   - Formulario **Seguridad de los datos:** nombre, correo, teléfono, ID de usuario, token del dispositivo y fotos subidas por la barbería. La ubicación de **Cerca** se envía solo para ordenar esa búsqueda y no se guarda (procesamiento efímero). Datos cifrados en tránsito, se pueden eliminar y no se comparten con terceros para publicidad.
   - Cuenta de demostración para los revisores (cliente y propietario) con datos de prueba.
4. **Pagos:** la app no vende el plan de la barbería ni muestra precios o botones para pagar fuera (ver [APPS_MOVILES.md](../APPS_MOVILES.md#6-pagos-y-tiendas)). No lo cambies sin revisar la política de Pagos de Google Play.
5. Pasa a **Producción** cuando la prueba interna esté bien.

## 9. Actualizar la app

Los cambios de pantallas y lógica viajan con el frontend. Cada versión nueva de la app es:

```bash
git pull
cd frontend && npm ci
# súbele barberappVersionCode (y barberappVersionName) en android/gradle.properties
BARBERAPP_API_URL=… BARBERAPP_WEB_URL=… npm run configurar -- --produccion
npm run android:aab
```

Cuando subas `VERSION` en `www/sw.js` para la web, la app no lo necesita: sus archivos van dentro del AAB.

Para actualizar Capacitor, cambia las versiones fijas en `package.json`, ejecuta `npm ci && npx cap sync android` y revisa la guía de migración oficial de Capacitor.

## 10. Problemas frecuentes

| Síntoma | Causa y solución |
|---|---|
| La app se queda en la pantalla de inicio con un error de conexión | `runtime-config.js` sin URL o con otra. Ejecuta `npm run configurar` con `BARBERAPP_API_URL` y luego `npx cap sync android`. |
| Error de CORS en `chrome://inspect` | Falta `https://localhost` en `CORS_ALLOWED_ORIGINS` del backend. |
| `http://` no funciona | Solo se permite en compilaciones debug hacia `localhost`. En release usa HTTPS. |
| No llegan los push | Falta `google-services.json`, el proyecto de Firebase no es el del backend, el permiso está denegado o el backend tiene `PUSH_DRIVER=log`. Prueba con `push:probar`. |
| Play rechaza el AAB por *versionCode* | Súbele `barberappVersionCode`. |
| El AAB sale sin firmar | No se encontró `keystore.properties` ni las variables `BARBERAPP_*`. |
| `/b/{slug}` abre el navegador y no la app | `assetlinks.json` no se publicó, tiene la huella de la clave de subida en lugar de la de Play, o redirige. |
| `SDK location not found` | Define `ANDROID_HOME` o crea `android/local.properties` con `sdk.dir=…` (Android Studio lo hace solo). |
