/**
 * Service worker de las notificaciones push del módulo de gastos (Firebase Cloud Messaging).
 * Vive en views/gastos/ para que su alcance cubra las páginas del módulo. La configuración web
 * de Firebase llega en la URL (?c=…), la misma que imprime _pie.php desde GasConfig::$FIREBASE_WEB.
 * Con la página cerrada, el SDK muestra la notificación y al tocarla abre la solicitud
 * (la API la envía en webpush.fcm_options.link).
 */
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');

let cfg = null;
try {
  cfg = JSON.parse(new URL(self.location.href).searchParams.get('c') || 'null');
} catch (e) {
  cfg = null;
}
if (cfg && cfg.projectId) {
  firebase.initializeApp(cfg);
  firebase.messaging();
}

