/**
 * Diagnóstico de notificaciones push (administradores): revisión de la configuración, envío de prueba
 * (a uno mismo o a otro correo / organización), a quién le llegaría el aviso de una solicitud y los
 * últimos avisos enviados con lo que respondió la API.
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

  function seccion(titulo, ayuda, cuerpo) {
    return '<section class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="mb-1 text-sm font-semibold text-slate-700">' + esc(titulo) + '</h2>' +
      (ayuda ? '<p class="mb-3 text-sm text-slate-500">' + esc(ayuda) + '</p>' : '') + cuerpo + '</section>';
  }

  function tablaUltimos(rows) {
    if (rows === null) return '<p class="text-sm text-amber-800">Falta la tabla T_GAS_NOTIFICACIONES: ejecuta database/migracion_notificaciones_log.sql para ver el registro de envíos.</p>';
    if (!rows.length) return '<p class="text-sm text-slate-500">Aún no hay avisos registrados.</p>';
    return '<div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="text-left text-xs uppercase tracking-wide text-slate-500"><tr>' +
      '<th class="py-2 pr-3">Fecha</th><th class="py-2 pr-3">Solicitud</th><th class="py-2 pr-3">Paso</th><th class="py-2 pr-3">Org.</th><th class="py-2 pr-3">Destinatarios</th><th class="py-2 pr-3">HTTP</th><th class="py-2">Resultado</th></tr></thead>' +
      '<tbody class="divide-y divide-slate-100">' + rows.map(function (r) {
        const mal = Number(r.HTTP) === 0 || Number(r.HTTP) >= 300 || Number(r.EXITOSOS) === 0;
        return '<tr class="align-top ' + (mal ? 'bg-rose-50/60' : '') + '"><td class="whitespace-nowrap py-2 pr-3">' + Gastos.fecha(r.FECHA) + '</td>' +
          '<td class="whitespace-nowrap py-2 pr-3 font-semibold">' + esc(r.CODIGO || '') + '</td><td class="py-2 pr-3">' + esc(r.PASO_NOMBRE || '') + '</td>' +
          '<td class="py-2 pr-3">' + esc(r.ORGANIZACION || '') + '</td><td class="max-w-xs break-words py-2 pr-3 text-xs">' + esc(r.DESTINATARIOS || '—') + '</td>' +
          '<td class="py-2 pr-3">' + (Number(r.HTTP) ? esc(r.HTTP) : '—') + '</td><td class="py-2 text-xs">' + esc(r.RESULTADO || '') + '</td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  function pintar(lista, ultimos) {
    $d.innerHTML =
      seccion('Configuración', '', '<ul class="space-y-2">' + lista.map(function (r) { return fila(r[0], r[1], r[2]); }).join('') + '</ul>') +
      seccion('Notificación de prueba', 'Déjalo vacío para enviarla a tu correo. Para revisar por qué no le llega a alguien, escribe su correo y la organización (ses_NumOrg) con la que su dispositivo quedó registrado.',
        '<form id="fProbar" class="flex flex-wrap items-end gap-3">' +
          '<div class="min-w-[16rem] flex-1"><label class="' + C.label + '">Correo (opcional)</label><input name="email" type="email" class="' + C.input + '" placeholder="gerente@empresa.com"></div>' +
          '<div class="w-32"><label class="' + C.label + '">Organización</label><input name="org" maxlength="4" class="' + C.input + '" placeholder="La tuya"></div>' +
          '<button class="' + C.btn + ' ' + C.primary + '">Enviar prueba</button></form><div id="resProbar" class="mt-4"></div>') +
      seccion('¿A quién le llega el aviso de una solicitud?', 'Escribe el código (p. ej. COT-000012): muestra el paso en curso y los correos a los que se avisa.',
        '<form id="fDest" class="flex flex-wrap items-end gap-3"><div class="w-48"><label class="' + C.label + '">Código</label><input name="codigo" required class="' + C.input + '"></div>' +
          '<button class="' + C.btn + ' ' + C.ghost + '">Consultar</button></form><div id="resDest" class="mt-4"></div>') +
      seccion('Últimos avisos enviados', 'Cada vez que una solicitud avanza queda aquí lo que respondió la API. «0 dispositivos» = esos correos no tienen navegador ni celular registrado en esa organización.',
        '<div id="ultimos">' + tablaUltimos(ultimos) + '</div>');

    const fp = document.getElementById('fProbar');
    fp.addEventListener('submit', function (e) {
      e.preventDefault();
      Gastos.conBoton(fp.querySelector('button'), Gastos.api('notificaciones', 'probar', { email: fp.elements.email.value, org: fp.elements.org.value })).then(function (r) {
        document.getElementById('resProbar').innerHTML = '<ul>' + fila('Resultado (HTTP ' + r.http + ' · ' + r.email + ' · org. ' + (r.organizacion || '—') + ')', r.estado, r.mensaje) + '</ul>' +
          '<details class="mt-2 text-xs text-slate-600"><summary class="cursor-pointer">Respuesta completa de la API</summary><pre class="mt-2 overflow-x-auto rounded bg-slate-100 p-2">' +
          esc(typeof r.respuesta === 'string' ? r.respuesta : JSON.stringify(r.respuesta, null, 2)) + '</pre></details>';
      }).catch(Gastos.error);
    });

    const fd = document.getElementById('fDest');
    fd.addEventListener('submit', function (e) {
      e.preventDefault();
      Gastos.conBoton(fd.querySelector('button'), Gastos.api('notificaciones', 'destinatarios', { codigo: fd.elements.codigo.value })).then(function (r) {
        document.getElementById('resDest').innerHTML = !r.paso
          ? '<ul>' + fila(r.codigo, 'aviso', 'La solicitud no tiene un paso en curso (estado ' + r.estado + ').') + '</ul>'
          : '<ul>' + fila(r.codigo + ' · «' + r.paso + '» · ' + r.responsable + ' · org. ' + r.organizacion, r.emails.length ? 'ok' : 'error',
              r.emails.length ? 'Se avisa a: ' + r.emails.join(', ') : 'Nadie: no hay usuarios activos (ESTADO = A) con correo válido para este paso.') + '</ul>';
      }).catch(Gastos.error);
    });
  }

  Promise.all([Gastos.api('notificaciones', 'diagnostico'), Gastos.api('notificaciones', 'ultimos')])
    .then(function (r) { pintar(r[0], r[1]); })
    .catch(function (e) {
      $d.innerHTML = '<div class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">' + esc(Gastos.mensajeError(e)) + '</div>';
    });
})();
