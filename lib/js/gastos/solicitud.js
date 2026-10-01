/**
 * Detalle de una solicitud: resumen, línea de tiempo del flujo, archivos y
 * formulario del paso en curso (si le corresponde al usuario).
 */
(function () {
  const $app = document.getElementById('app');
  const id = Number($app.dataset.id);
  const esc = Gastos.esc;
  const C = Gastos.CLS;

  const TIPO_TXT = { COTIZACION: 'Cotización', ANTICIPO: 'Anticipo', FACTURA: 'Factura' };
  const CAT_TXT = { COTIZACION: 'Cotización', FACTURA: 'Factura', LEGALIZACION: 'Soportes de la legalización', SOPORTE_PAGO: 'Soporte de pago', PRELIMINAR: 'Preliminar', COMPROBANTE: 'Comprobante de pago' };
  const ANT_TXT = { COTIZACION: 'Por cotización', FACTURA: 'Por factura', VIATICOS: 'Por viáticos' };
  const DEC_TXT = { COMPLETADO: 'Completado', APROBADO: 'Aprobado', RECHAZADO: 'Rechazado' };

  /* ------------------------------------------------------------------ resumen */

  function resumen(S, opc) {
    const filas = [
      ['Solicitante', S.SOLICITANTE],
      ['Oficina', S.OFICINA_NOMBRE ? S.OFICINA_NOMBRE + ' (' + S.OFICINA_VENTAS + ')' : S.OFICINA_VENTAS],
      ['Departamento / proceso', S.PROCESO],
      ['Descripción', S.DESCRIPCION],
      ['Soporte de pago', S.REQUIERE_SOPORTE_PAGO === null ? '' : (Number(S.REQUIERE_SOPORTE_PAGO) ? 'Requerido' : 'No requerido')],
      ['Anticipo', Number(S.REQUIERE_ANTICIPO) ? (ANT_TXT[S.TIPO_ANTICIPO] || 'Sí') + (S.VALOR_ANTICIPO ? ' · ' + Gastos.moneda(S.VALOR_ANTICIPO) : '') : (S.REQUIERE_ANTICIPO === null ? '' : 'No')],
      ['Valor del preliminar', S.VALOR_TOTAL && S.VALOR_LEGALIZADO === null ? Gastos.moneda(S.VALOR_TOTAL) : ''],
      ['Total legalizado', S.VALOR_LEGALIZADO !== null && S.VALOR_LEGALIZADO !== undefined ? Gastos.moneda(S.VALOR_LEGALIZADO) : ''],
      ['Tercero', S.TERCERO_NOMBRE ? S.TERCERO_NOMBRE + ' · NIT ' + S.TERCERO_NIT : ''],
      ['Contacto tercero', [S.TERCERO_CELULAR, S.TERCERO_EMAIL].filter(Boolean).join(' · ')],
      ['Cargo / centro de costos', [S.CARGO, S.CENTRO_COSTOS].filter(Boolean).join(' · ')],
      ['Tel. fijo', S.VIATICOS_TEL_FIJO],
      ['Viaje', S.VIATICOS_DESTINO ? S.VIATICOS_DESTINO + ' (' + Gastos.fecha(S.VIATICOS_FECHA_INICIO) + ' al ' + Gastos.fecha(S.VIATICOS_FECHA_FIN) + ') — ' + (S.VIATICOS_MOTIVO || '') : ''],
      ['N.º de preliminar', S.NUM_PRELIMINAR],
      ['Sale de un fondo', S.PAGO_FONDO === null || S.PAGO_FONDO === undefined ? '' : (Number(S.PAGO_FONDO) ? 'Sí · ' + ((opc.fondos && opc.fondos[S.FONDO]) || S.FONDO || '') : 'No')],
      ['Fecha de la factura', S.FECHA_FACTURA ? Gastos.fecha(S.FECHA_FACTURA) : ''],
      ['N.º de contabilización', S.NUM_CONTABILIZACION],
      ['Causación de compensación', S.NUM_COMPENSACION],
      ['Comprobante ZP', S.NUM_COMPROBANTE_ZP ? S.NUM_COMPROBANTE_ZP + (S.FECHA_PAGO ? ' · pagado el ' + Gastos.fecha(S.FECHA_PAGO) : '') : ''],
      ['Motivo de cierre', S.MOTIVO_CIERRE],
    ].filter(function (f) { return f[1]; });
    return '<dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">' + filas.map(function (f) {
      return '<div><dt class="' + C.label + '">' + f[0] + '</dt><dd class="text-sm text-slate-800">' + esc(f[1]) + '</dd></div>';
    }).join('') + '</dl>';
  }

  function tablaCotizaciones(S) {
    if (!S.cotizaciones.length) return '';
    return '<h3 class="mb-2 mt-6 text-sm font-semibold text-slate-700">Cotizaciones</h3>' +
      '<div class="overflow-x-auto"><table class="min-w-full text-sm"><tbody class="divide-y divide-slate-100">' +
      S.cotizaciones.map(function (c) {
        const el = Number(c.NUMERO) === Number(S.COTIZACION_ELEGIDA);
        return '<tr class="' + (el ? 'bg-emerald-50' : '') + '"><td class="py-2 pr-3 font-semibold">Cotización ' + c.NUMERO + (el ? ' ✓ autorizada' : '') + '</td>' +
          '<td class="py-2 pr-3">' + esc(c.PROVEEDOR || '—') + '</td><td class="py-2 pr-3 text-right tabular-nums">' + Gastos.moneda(c.VALOR) + '</td>' +
          '<td class="py-2 text-right"><a target="_blank" rel="noopener" class="font-semibold text-indigo-600 hover:underline" href="' + Gastos.urlPdf(c.ARCHIVO_ID) + '">Ver PDF</a></td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  /** Resumen de los formatos de viáticos + botón para verlos completos (solo lectura). */
  function formatosViaticos(S) {
    let h = '';
    if (S.viaticos && S.viaticos.length) {
      const total = S.viaticos.reduce(function (a, v) { return a + Number(v.VALOR_TOTAL); }, 0);
      h += '<div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 p-4">' +
        '<div><div class="text-sm font-semibold text-slate-700">Solicitud de viáticos (F-FR-023)</div>' +
        '<div class="text-sm text-slate-600">Total solicitado <b>' + Gastos.moneda(total) + '</b> · ' + Gastos.fecha(S.VIATICOS_FECHA_INICIO) + ' a ' + Gastos.fecha(S.VIATICOS_FECHA_FIN) + '</div></div>' +
        '<button type="button" data-ver="F023" class="' + C.btn + ' ' + C.ghost + '">Ver formato</button></div>';
    }
    if (S.legalizacion && S.legalizacion.length) {
      const info = Gastos.saldo(S.SALDO_LEGALIZACION);
      h += '<div class="mt-3 rounded-xl border border-slate-200 p-4"><div class="flex flex-wrap items-center justify-between gap-3">' +
        '<div><div class="text-sm font-semibold text-slate-700">Legalización de viáticos (F-FR-024)</div>' +
        '<div class="text-sm text-slate-600">Total cuenta de gastos <b>' + Gastos.moneda(S.VALOR_LEGALIZADO) + '</b> · retefuente ' + Gastos.moneda(S.RETEFUENTE_LEGALIZACION || 0) + '</div></div>' +
        '<button type="button" data-ver="F024" class="' + C.btn + ' ' + C.ghost + '">Ver formato</button></div>' +
        '<div class="mt-3 rounded-lg border p-3 text-sm ' + info.cls + '"><b>' + esc(info.titulo) + '</b><br>' + esc(info.detalle) + '</div></div>';
    }
    return h;
  }

  function listaArchivos(S) {
    // Las cotizaciones ya tienen su propia tabla.
    const otros = S.archivos.filter(function (a) { return a.CATEGORIA !== 'COTIZACION'; });
    if (!otros.length) return '';
    return '<h3 class="mb-2 mt-6 text-sm font-semibold text-slate-700">Archivos</h3><ul class="space-y-1 text-sm">' + otros.map(function (a) {
      return '<li><a target="_blank" rel="noopener" class="text-indigo-600 hover:underline" href="' + Gastos.urlPdf(a.ID) + '">📄 ' + esc(CAT_TXT[a.CATEGORIA] || a.CATEGORIA) + ' — ' + esc(a.NOMBRE_ORIGINAL) + '</a> <span class="text-slate-400">' + Gastos.fecha(a.FECHA) + '</span></li>';
    }).join('') + '</ul>';
  }

  /* --------------------------------------------------------------- línea de tiempo */

  function lineaTiempo(S) {
    const ICONO = {
      COMPLETADO: ['✓', 'bg-emerald-500 text-white'], ACTUAL: ['●', 'bg-amber-400 text-white'],
      PENDIENTE: ['', 'bg-slate-200 text-slate-400'], OMITIDO: ['–', 'bg-slate-100 text-slate-400'], RECHAZADO: ['✕', 'bg-rose-500 text-white'],
    };
    return '<ol class="space-y-4">' + S.pasos.map(function (p) {
      const ic = ICONO[p.ESTADO] || ICONO.PENDIENTE;
      const omit = p.ESTADO === 'OMITIDO';
      let detalle = '';
      if (p.EJECUTOR_NOMBRE) {
        detalle = '<div class="text-xs text-slate-500">' + esc(DEC_TXT[p.DECISION] || p.DECISION || '') + ' por ' + esc(p.EJECUTOR_NOMBRE) + ' · ' + Gastos.fecha(p.FECHA_FIN) + '</div>';
      } else if (p.ESTADO === 'ACTUAL') {
        detalle = '<div class="text-xs font-semibold text-amber-600">En curso desde ' + Gastos.fecha(p.FECHA_INICIO) + '</div>';
      } else if (omit) {
        detalle = '<div class="text-xs text-slate-400">No aplica</div>';
      }
      return '<li class="flex gap-3"><span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ' + ic[1] + '">' + ic[0] + '</span>' +
        '<div class="min-w-0"><div class="text-sm font-semibold ' + (omit ? 'text-slate-400 line-through' : 'text-slate-800') + '">' + esc(p.NOMBRE) + '</div>' +
        '<div class="text-xs text-slate-500">' + esc(p.RESPONSABLE) + '</div>' + detalle +
        (p.COMENTARIO ? '<div class="mt-1 rounded-lg bg-slate-50 p-2 text-xs text-slate-600">“' + esc(p.COMENTARIO) + '”</div>' : '') + '</div></li>';
    }).join('') + '</ol>';
  }

  /* ------------------------------------------------------------ tarjeta de acción */

  function tarjetaAccion(S, opc) {
    const paso = S.pasoActual;
    if (S.ESTADO !== 'EN_CURSO') {
      const msg = { FINALIZADA: '✅ Solicitud finalizada. Todos los pasos se completaron.', RECHAZADA: '⛔ Solicitud rechazada.', CANCELADA: 'Solicitud cancelada.' }[S.ESTADO];
      return '<div class="rounded-xl border border-slate-200 bg-white p-5 text-sm font-medium text-slate-700">' + msg + '</div>';
    }
    if (!paso) return '';
    if (!S.puedeActuar) {
      return '<div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">Esperando: <b>' + esc(paso.NOMBRE) + '</b> — ' + esc(paso.RESPONSABLE) + '.</div>';
    }
    const form = Pasos.FORMS[paso.ACCION];
    if (!form) return '<div class="rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700">La acción «' + esc(paso.ACCION) + '» no tiene formulario.</div>';
    const info = opc.acciones[paso.ACCION] ? opc.acciones[paso.ACCION].ayuda : '';
    return '<form id="formPaso" novalidate class="space-y-4 rounded-xl border-2 border-indigo-200 bg-white p-5">' +
      '<div><div class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Te toca actuar</div>' +
      '<h2 class="text-lg font-bold text-slate-900">' + esc(paso.NOMBRE) + '</h2><p class="text-sm text-slate-500">' + esc(info) + '</p></div>' +
      form.html(S, paso, opc) +
      '<div class="flex justify-end pt-2"><button type="submit" class="' + C.btn + ' ' + C.primary + '">Enviar</button></div></form>';
  }

  /** Reúne los campos del formulario en un FormData listo para la API. */
  function recolectar(form) {
    const fd = new FormData(form);
    // Los importes se escriben con separadores locales: se normalizan a "1234.56".
    form.querySelectorAll('[data-money],[data-cant]').forEach(function (el) {
      if (!el.disabled && el.name) fd.set(el.name, String(Gastos.num(el.value)));
    });
    fd.set('id', String(id));
    // No enviar campos de archivo vacíos.
    form.querySelectorAll('input[type=file]').forEach(function (el) {
      if (!el.files.length) fd.delete(el.name);
    });
    return fd;
  }

  function enlazarFormulario(S, opc) {
    const form = document.getElementById('formPaso');
    if (!form) return;
    const accion = S.pasoActual.ACCION;
    Pasos.enlazar(form, accion, S, opc);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      // Primer campo inválido; si está dentro de un formato (modal), se abre el modal para señalarlo.
      const inv = Array.prototype.filter.call(form.querySelectorAll('input,select,textarea'), function (i) { return !i.disabled && !i.checkValidity(); })[0];
      if (inv) {
        const m = inv.closest('[data-modal]');
        if (m) Pasos.abrirModal(m);
        inv.reportValidity();
        return;
      }
      const extra = Pasos.FORMS[accion].validar ? Pasos.FORMS[accion].validar(form, S) : null;
      if (extra) { Gastos.error({ gastos: true, mensaje: extra }); return; }

      const rechaza = form.elements.decision && form.elements.decision.value === 'RECHAZAR';
      // Algunos pasos definen su propia advertencia (p. ej. anticipo vs. preliminar).
      const propia = Pasos.FORMS[accion].confirmar ? Pasos.FORMS[accion].confirmar(form, S) : null;
      const c = propia || {
        titulo: rechaza ? '¿Rechazar la solicitud?' : '¿Enviar este paso?',
        texto: rechaza ? 'La solicitud se cerrará como rechazada.' : 'Podrás ver el avance en la línea de tiempo.',
        boton: rechaza ? 'Sí, rechazar' : 'Sí, enviar',
      };
      Gastos.confirmar(c.titulo, c.texto, c.boton).then(function (si) {
        if (!si) return;
        const btn = form.querySelector('button[type=submit]');
        Gastos.conBoton(btn, Gastos.enviarForm('solicitudes', 'ejecutar', recolectar(form)))
          .then(function () { return Gastos.ok('Paso registrado'); })
          .then(function () { location.reload(); })
          .catch(Gastos.error);
      });
    });
  }

  function cancelar() {
    Swal.fire({
      icon: 'warning', title: 'Cancelar solicitud', input: 'textarea', inputLabel: 'Motivo de la cancelación',
      showCancelButton: true, confirmButtonText: 'Cancelar solicitud', cancelButtonText: 'Volver', confirmButtonColor: '#e11d48',
      inputValidator: function (v) { if (!v.trim()) return 'Escribe el motivo.'; },
    }).then(function (r) {
      if (!r.isConfirmed) return;
      Gastos.api('solicitudes', 'cancelar', { id: id, motivo: r.value })
        .then(function () { location.reload(); }).catch(Gastos.error);
    });
  }

  /* --------------------------------------------------------------------- pintar */

  function pintar(S, opc) {
    document.title = S.CODIGO + ' · Control de gastos';
    $app.innerHTML =
      '<div class="mb-5 flex flex-wrap items-center justify-between gap-3">' +
        '<div><div class="text-sm font-semibold text-indigo-700">' + esc(TIPO_TXT[S.TIPO] || S.TIPO) + (S.FLUJO_NOMBRE ? ' · ' + esc(S.FLUJO_NOMBRE) : '') + '</div>' +
        '<h1 class="text-2xl font-bold text-slate-900">' + esc(S.CODIGO) + '</h1>' +
        '<div class="mt-1 text-xs text-slate-500">Creada el ' + Gastos.fecha(S.FECHA_CREACION) + '</div></div>' +
        '<div class="flex items-center gap-3">' + Gastos.badgeEstado(S.ESTADO) +
        (S.puedeCancelar ? '<button id="btnCancelar" class="' + C.btn + ' ' + C.ghost + '">Cancelar solicitud</button>' : '') + '</div></div>' +
      '<div class="grid gap-6 lg:grid-cols-3">' +
        '<div class="space-y-6 lg:col-span-2">' + tarjetaAccion(S, opc) +
          '<section class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="mb-4 text-sm font-semibold text-slate-700">Detalle</h2>' +
          resumen(S, opc) + tablaCotizaciones(S) + formatosViaticos(S) + listaArchivos(S) + '</section></div>' +
        '<aside class="rounded-xl border border-slate-200 bg-white p-5 lg:self-start"><h2 class="mb-4 text-sm font-semibold text-slate-700">Flujo de autorización</h2>' + lineaTiempo(S) + '</aside>' +
      '</div>';
    $app.querySelectorAll('[data-ver]').forEach(function (b) {
      b.addEventListener('click', function () { Pasos.verFormato(b.dataset.ver, S, opc); });
    });
    const bc = document.getElementById('btnCancelar');
    if (bc) bc.addEventListener('click', cancelar);
    enlazarFormulario(S, opc);
  }

  Promise.all([Gastos.api('solicitudes', 'obtener', { id: id }), Gastos.opciones()])
    .then(function (r) { pintar(r[0], r[1]); })
    .catch(function (e) {
      $app.innerHTML = '<div class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">' + esc(Gastos.mensajeError(e)) + '</div>';
    });
})();
