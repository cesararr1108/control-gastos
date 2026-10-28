/**
 * Diagnóstico de notificaciones push (administradores): lista de revisiones y envío de prueba.
 */
(function () {
  const esc = Gastos.esc;
  const C = Gastos.CLS;
  const $d = document.getElementById('diag');
  const ESTILO = {
    ok: ['✓', 'border-emerald-300 bg-emerald-50 text-emerald-900'],
    aviso: ['!', 'border-amber-300 bg-amber-50 text-amber-900'],
    error: ['✕', 'border-rose-300 bg-rose-50 text-rose-900'],
  };

  function fila(titulo, estado, detalle) {
    const e = ESTILO[estado] || ESTILO.aviso;
    return '<li class="flex gap-3 rounded-lg border p-3 text-sm ' + e[1] + '"><span class="font-bold" aria-hidden="true">' + e[0] + '</span>' +
      '<div class="min-w-0"><div class="font-semibold">' + esc(titulo) + '</div><div class="break-words">' + esc(detalle) + '</div></div></li>';
  }

  function pintar(lista) {
    $d.innerHTML = '<section class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="mb-3 text-sm font-semibold text-slate-700">Configuración</h2>' +
      '<ul class="space-y-2">' + lista.map(function (r) { return fila(r[0], r[1], r[2]); }).join('') + '</ul></section>' +
      '<section class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="mb-1 text-sm font-semibold text-slate-700">Notificación de prueba</h2>' +
      '<p class="mb-3 text-sm text-slate-500">Se envía a tu correo. La respuesta dice a cuántos dispositivos registrados llegó.</p>' +
      '<button id="btnProbar" class="' + C.btn + ' ' + C.primary + '">Enviarme una notificación de prueba</button><div id="resProbar" class="mt-4"></div></section>';
    const btn = document.getElementById('btnProbar');
    btn.addEventListener('click', function () {
      Gastos.conBoton(btn, Gastos.api('notificaciones', 'probar')).then(function (r) {
        document.getElementById('resProbar').innerHTML = '<ul>' + fila('Resultado (HTTP ' + r.http + ', ' + r.email + ')', r.estado, r.mensaje) + '</ul>' +
          '<details class="mt-2 text-xs text-slate-600"><summary class="cursor-pointer">Respuesta completa de la API</summary><pre class="mt-2 overflow-x-auto rounded bg-slate-100 p-2">' +
          esc(typeof r.respuesta === 'string' ? r.respuesta : JSON.stringify(r.respuesta, null, 2)) + '</pre></details>';
      }).catch(Gastos.error);
    });
  }

  Gastos.api('notificaciones', 'diagnostico').then(pintar).catch(function (e) {
    $d.innerHTML = '<div class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">' + esc(Gastos.mensajeError(e)) + '</div>';
  });
})();
