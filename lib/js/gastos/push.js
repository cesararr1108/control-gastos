/**
 * Notificaciones push: registra este navegador o teléfono en la API de notificaciones
 * (a través de controllers/gastos/api.php, que guarda la llave de API en el servidor).
 *
 * Solo se carga si GasConfig::$FIREBASE_WEB está configurado. La primera vez muestra un aviso
 * con el botón «Activar notificaciones» (los navegadores exigen un clic para pedir permiso);
 * con el permiso ya concedido, renueva el registro una vez al día sin preguntar.
 */
(function () {
  const cfg = window.GASTOS_FIREBASE;
  if (!cfg || !('serviceWorker' in navigator) || !('Notification' in window) || !window.isSecureContext) return;
  if (Notification.permission === 'denied') return;

  const SDK = 'https://www.gstatic.com/firebasejs/10.14.1/';
  const CLAVE = 'gastos_push_registro';

  function leer() { try { return JSON.parse(localStorage.getItem(CLAVE) || 'null'); } catch (e) { return null; } }
  function guardar(v) { try { localStorage.setItem(CLAVE, JSON.stringify(v)); } catch (e) { /* sin almacenamiento */ } }

  function dispositivo() {
    const ua = navigator.userAgent;
    return /Android/i.test(ua) ? 'android' : /iPhone|iPad|iPod/i.test(ua) ? 'ios' : 'web';
  }
  function navegador() {
    const m = navigator.userAgent.match(/(Edg|OPR|Chrome|Firefox|Safari)\/(\d+)/);
    return m ? m[1].replace('Edg', 'Edge').replace('OPR', 'Opera') + ' ' + m[2] : 'Navegador';
  }

  /** Carga Firebase, registra el service worker, obtiene el token y lo envía al servidor. */
  function registrar() {
    return Gastos.cargarScript(SDK + 'firebase-app-compat.js')
      .then(function () { return Gastos.cargarScript(SDK + 'firebase-messaging-compat.js'); })
      .then(function () {
        const app = firebase.apps.length ? firebase.app() : firebase.initializeApp(cfg);
        const sw = 'firebase-messaging-sw.js?c=' + encodeURIComponent(JSON.stringify(cfg));
        return navigator.serviceWorker.register(sw, { scope: './' }).then(function (reg) {
          const messaging = firebase.messaging(app);
          // Con la página abierta el sistema no muestra la notificación: se avisa aquí.
          messaging.onMessage(function (p) {
            const n = p.notification || {};
            const url = (p.fcmOptions && p.fcmOptions.link) || (p.data && p.data.url) || '';
            Swal.fire({
              toast: true, position: 'top-end', icon: 'info', title: n.title || 'Control de gastos', text: n.body || '',
              showConfirmButton: !!url, confirmButtonText: 'Abrir', timer: url ? 12000 : 6000,
            }).then(function (r) { if (r.isConfirmed && url) location.href = url; });
          });
          return messaging.getToken({ vapidKey: cfg.vapidKey, serviceWorkerRegistration: reg });
        });
      })
      .then(function (token) {
        if (!token) throw { gastos: true, mensaje: 'El navegador no entregó un token de notificaciones.' };
        const previo = leer();
        const hoy = Gastos.isoFecha(new Date());
        if (previo && previo.token === token && previo.fecha === hoy) return;
        return Gastos.api('notificaciones', 'registrar', { token: token, navegador: navegador(), dispositivo: dispositivo() })
          .then(function () { guardar({ token: token, fecha: hoy }); });
      });
  }

  /** Aviso discreto para pedir el permiso con un clic. */
  function ofrecer() {
    if (leer() && leer().omitido === Gastos.isoFecha(new Date())) return;
    const div = document.createElement('div');
    div.className = 'fixed inset-x-3 bottom-3 z-30 mx-auto flex max-w-md flex-wrap items-center gap-3 rounded-xl border border-indigo-200 bg-white p-3 text-sm shadow-lg';
    div.innerHTML = '<span class="flex-1 text-slate-700">¿Quieres que te avisemos cuando una solicitud espere tu acción?</span>' +
      '<button type="button" data-si class="rounded-lg bg-indigo-600 px-3 py-1.5 font-semibold text-white hover:bg-indigo-700">Activar notificaciones</button>' +
      '<button type="button" data-no class="rounded-lg px-2 py-1.5 text-slate-500 hover:bg-slate-100">Ahora no</button>';
    document.body.appendChild(div);
    div.querySelector('[data-no]').addEventListener('click', function () { guardar({ omitido: Gastos.isoFecha(new Date()) }); div.remove(); });
    div.querySelector('[data-si]').addEventListener('click', function () {
      Notification.requestPermission().then(function (p) {
        div.remove();
        if (p !== 'granted') return;
        return registrar().then(function () { Gastos.ok('Notificaciones activadas'); });
      }).catch(Gastos.error);
    });
  }

  if (Notification.permission === 'granted') {
    registrar().catch(function (e) { if (window.console) console.warn('[gastos] notificaciones:', Gastos.mensajeError(e)); });
  } else {
    ofrecer();
  }
})();
