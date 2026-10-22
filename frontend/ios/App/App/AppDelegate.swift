import UIKit
import UserNotifications
import Capacitor
#if canImport(FirebaseCore) && canImport(FirebaseMessaging)
import FirebaseCore
import FirebaseMessaging
#endif

/**
 BARBERAPP (Fase 12).

 Avisos push: el backend envía por Firebase Cloud Messaging (HTTP v1) a Android e iOS. En iOS,
 Firebase Messaging convierte el token de APNs en un token de FCM y se lo entrega al plugin
 @capacitor/push-notifications. Sin el paquete de Firebase (firebase-ios-sdk, producto
 FirebaseMessaging) ni GoogleService-Info.plist la app compila igual, pero no registra avisos
 push (ver docs/APPS_MOVILES.md, "Push en iOS").
 */
@UIApplicationMain
class AppDelegate: UIResponder, UIApplicationDelegate {

    var window: UIWindow?

    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {
        #if canImport(FirebaseCore) && canImport(FirebaseMessaging)
        if Bundle.main.path(forResource: "GoogleService-Info", ofType: "plist") != nil {
            FirebaseApp.configure()
        }
        #endif
        // Botón "Confirmar asistencia" en los recordatorios (categoría que envía FcmTransport).
        let confirmar = UNNotificationAction(identifier: "CONFIRMAR", title: "Confirmar asistencia", options: [.foreground])
        let categoria = UNNotificationCategory(identifier: "CITA_CONFIRMAR", actions: [confirmar], intentIdentifiers: [], options: [])
        UNUserNotificationCenter.current().setNotificationCategories([categoria])
        return true
    }

    // MARK: - Avisos push

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        #if canImport(FirebaseCore) && canImport(FirebaseMessaging)
        if FirebaseApp.app() != nil {
            Messaging.messaging().apnsToken = deviceToken
            Messaging.messaging().token { token, error in
                if let error = error {
                    NotificationCenter.default.post(name: .capacitorDidFailToRegisterForRemoteNotifications, object: error)
                } else if let token = token {
                    NotificationCenter.default.post(name: .capacitorDidRegisterForRemoteNotifications, object: token)
                }
            }
            return
        }
        #endif
        // Sin Firebase llega el token de APNs (64 caracteres hex); js/push.js no lo registra.
        NotificationCenter.default.post(name: .capacitorDidRegisterForRemoteNotifications, object: deviceToken)
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        NotificationCenter.default.post(name: .capacitorDidFailToRegisterForRemoteNotifications, object: error)
    }

    // MARK: - Escenas

    func application(_ application: UIApplication,
                     configurationForConnecting connectingSceneSession: UISceneSession,
                     options: UIScene.ConnectionOptions) -> UISceneConfiguration {
        let config = UISceneConfiguration(name: "Default Configuration",
                                          sessionRole: connectingSceneSession.role)
        config.delegateClass = SceneDelegate.self
        return config
    }
}
