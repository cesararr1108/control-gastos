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

  /* ------------------------------------------------------------ piezas reutilizables */

  let opcGlobal = {};

  /** Iconos de trazo (24x24) para las cards. */
  const ICONOS = {
    resumen: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
    subir: 'M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    aprobar: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
    dinero: 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
    doc: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    calc: 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z',
    clip: 'M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13',
  };
  const ICONO_ACCION = {
    SUBIR_COTIZACIONES: 'subir', AUTORIZAR_COTIZACION: 'aprobar', DECISION_ANTICIPO: 'dinero', SOLICITAR_ANTICIPO: 'dinero',
    MONTAR_PRELIMINAR: 'doc', APROBAR: 'aprobar', CONTABILIZAR: 'calc', PAGAR: 'dinero',
  };

  function icono(clave, cls) {
    return '<svg class="' + (cls || 'h-5 w-5') + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="' + ICONOS[clave] + '"/></svg>';
  }

  /** Pares etiqueta / valor (se omiten los vacíos). */
  function kv(pares, cols) {
    const f = pares.filter(function (p) { return p[1] !== null && p[1] !== undefined && p[1] !== ''; });
    if (!f.length) return '';
    return '<dl class="grid gap-x-6 gap-y-3 ' + (cols === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-2') + '">' + f.map(function (p) {
      return '<div><dt class="' + C.label + '">' + p[0] + '</dt><dd class="text-sm text-slate-800">' + (p[2] ? p[1] : esc(p[1])) + '</dd></div>';
    }).join('') + '</dl>';
  }

  function enlacePdf(a) {
    return '<a target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-indigo-600 hover:underline" href="' + Gastos.urlPdf(a.ID) + '">' +
      '<span class="text-slate-400">' + icono('clip', 'h-4 w-4') + '</span>' + esc(CAT_TXT[a.CATEGORIA] || a.CATEGORIA) + ' — ' + esc(a.NOMBRE_ORIGINAL) + '</a>';
  }

  /** Adjuntos de un paso (las cotizaciones se muestran en su tabla). */
  function adjuntosPaso(S, orden) {
    const l = (S.archivos || []).filter(function (a) { return Number(a.PASO_ORDEN) === Number(orden) && a.CATEGORIA !== 'COTIZACION'; });
    if (!l.length) return '';
    return '<div><div class="' + C.label + '">Adjuntos</div><ul class="space-y-1 text-sm">' + l.map(function (a) { return '<li>' + enlacePdf(a) + '</li>'; }).join('') + '</ul></div>';
  }

  function tercero(S) {
    return S.TERCERO_NOMBRE ? S.TERCERO_NOMBRE + (S.TERCERO_NIT ? ' · NIT ' + S.TERCERO_NIT : '') : '';
  }

  function valorPrincipal(S) {
    // El valor que está en juego: el de la factura/legalización si ya existe, si no el del anticipo.
    return S.NUM_PRELIMINAR || S.VALOR_LEGALIZADO !== null && S.VALOR_LEGALIZADO !== undefined ? S.VALOR_TOTAL : S.VALOR_ANTICIPO;
  }

  function fondoTxt(S) {
    if (S.PAGO_FONDO === null || S.PAGO_FONDO === undefined) return '';
    return Number(S.PAGO_FONDO) ? 'Sí · ' + (S.FONDO || '') : 'No';
  }

  function tablaCotizaciones(S) {
    if (!S.cotizaciones.length) return '';
    return '<div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="text-left text-xs uppercase text-slate-500"><tr>' +
      '<th class="py-1 pr-3">Cotización</th><th class="py-1 pr-3">Proveedor</th><th class="py-1 pr-3 text-right">Valor</th><th></th></tr></thead><tbody class="divide-y divide-slate-100">' +
      S.cotizaciones.map(function (c) {
        const el = Number(c.NUMERO) === Number(S.COTIZACION_ELEGIDA);
        return '<tr class="' + (el ? 'bg-emerald-50' : '') + '"><td class="py-2 pr-3 font-semibold">N.º ' + c.NUMERO + (el ? ' ✓ autorizada' : '') + '</td>' +
          '<td class="py-2 pr-3">' + esc(c.PROVEEDOR || '—') + '</td><td class="py-2 pr-3 text-right tabular-nums">' + (Number(c.VALOR) > 0 ? Gastos.moneda(c.VALOR) : '—') + '</td>' +
          '<td class="py-2 text-right"><a target="_blank" rel="noopener" class="font-semibold text-indigo-600 hover:underline" href="' + Gastos.urlPdf(c.ARCHIVO_ID) + '">Ver PDF</a></td></tr>';
      }).join('') + '</tbody></table></div>';
  }

  /** Solicitud de viáticos (F-FR-023): motivo, fechas, presupuesto y avisos de topes; botón para ver el formato. */
  function tarjetaF023(S) {
    if (!S.viaticos || !S.viaticos.length) return '';
    const total = S.viaticos.reduce(function (a, v) { return a + Number(v.VALOR_TOTAL); }, 0);
    const suma = function (c) { return S.viaticos.filter(function (v) { return v.CONCEPTO === c; }).reduce(function (a, v) { return a + Number(v.VALOR_TOTAL); }, 0); };
    return '<div class="rounded-lg border border-slate-200 p-3">' +
      '<div class="flex flex-wrap items-center justify-between gap-3"><div class="text-sm font-semibold text-slate-700">Solicitud de viáticos (F-FR-023)</div>' +
      '<button type="button" data-ver="F023" class="' + C.btn + ' ' + C.ghost + '">Ver formato</button></div>' +
      '<div class="mt-3">' + kv([['Motivo de la solicitud', S.VIATICOS_MOTIVO], ['Fecha de salida', S.VIATICOS_FECHA_INICIO ? Gastos.fecha(S.VIATICOS_FECHA_INICIO) : ''],
        ['Fecha de regreso', S.VIATICOS_FECHA_FIN ? Gastos.fecha(S.VIATICOS_FECHA_FIN) : ''], ['Total solicitado', '<b>' + Gastos.moneda(total) + '</b>', true]], 3) + '</div>' +
      '<table class="mt-3 w-full max-w-lg text-sm"><tbody class="divide-y divide-slate-100">' + S.viaticos.map(function (v) {
        const nombre = (opcGlobal.conceptos && opcGlobal.conceptos[v.CONCEPTO]) || v.CONCEPTO;
        return '<tr><td class="py-1.5 pr-3">' + esc(nombre) + (v.DESCRIPCION ? ' <span class="text-slate-500">(' + esc(v.DESCRIPCION) + ')</span>' : '') + '</td>' +
          '<td class="py-1.5 text-right tabular-nums">' + Gastos.moneda(v.VALOR_TOTAL) + '</td></tr>';
      }).join('') + '</tbody></table>' +
      (S.topes ? '<div class="mt-3">' + Pasos.cajaAvisos(Pasos.avisosF023(S.topes, S.VIATICOS_FECHA_INICIO, S.VIATICOS_FECHA_FIN, { ALIMENTACION: suma('ALIMENTACION'), HOSPEDAJE: suma('HOSPEDAJE') }), S.topes.nivelNombre) + '</div>' : '') +
      '</div>';
  }

  /** Legalización (F-FR-024): totales, saldo y avisos; botón para ver el formato. */
  function tarjetaF024(S) {
    if (!S.legalizacion || !S.legalizacion.length) return '';
    const info = Gastos.saldo(S.SALDO_LEGALIZACION);
    return '<div class="rounded-lg border border-slate-200 p-3"><div class="flex flex-wrap items-center justify-between gap-3">' +
      '<div class="text-sm font-semibold text-slate-700">Legalización de viáticos (F-FR-024)</div>' +
      '<button type="button" data-ver="F024" class="' + C.btn + ' ' + C.ghost + '">Ver formato</button></div>' +
      '<div class="mt-3">' + kv([['Anticipo entregado', Gastos.moneda(S.VALOR_ANTICIPO)], ['Retefuente', Gastos.moneda(S.RETEFUENTE_LEGALIZACION || 0)],
        ['Total cuenta de gastos', '<b>' + Gastos.moneda(S.VALOR_LEGALIZADO) + '</b>', true]], 3) + '</div>' +
      '<div class="mt-3 rounded-lg border p-3 text-sm ' + info.cls + '"><b>' + esc(info.titulo) + '</b><br>' + esc(info.detalle) + '</div>' +
      (S.topes ? '<div class="mt-3">' + Pasos.cajaAvisos(Pasos.avisosF024(S.topes, Pasos.filasLegalizacion(S.legalizacion)), S.topes.nivelNombre) + '</div>' : '') + '</div>';
  }

  /* ------------------------------------------------------- una card por paso realizado */

  /** Lo que se registró en cada paso, según su acción. */
  function cuerpoPaso(S, p) {
    const hayAnt = Number(S.REQUIERE_ANTICIPO);
    switch (p.ACCION) {
      case 'SUBIR_COTIZACIONES':
        return kv([['Departamento / proceso', S.PROCESO], ['Soporte de pago', S.REQUIERE_SOPORTE_PAGO === null || S.REQUIERE_SOPORTE_PAGO === undefined ? '' : (Number(S.REQUIERE_SOPORTE_PAGO) ? 'Requerido' : 'No requerido')],
          ['Descripción', S.DESCRIPCION]]) + tablaCotizaciones(S);
      case 'AUTORIZAR_COTIZACION': {
        const el = (S.cotizaciones || []).filter(function (c) { return Number(c.NUMERO) === Number(S.COTIZACION_ELEGIDA); })[0];
        return el ? kv([['Cotización elegida', 'N.º ' + el.NUMERO + ' · ' + (el.PROVEEDOR || 'sin proveedor') + (Number(el.VALOR) > 0 ? ' · ' + Gastos.moneda(el.VALOR) : '')],
          ]) : '';
      }
      case 'DECISION_ANTICIPO':
        return kv([['¿Anticipo?', hayAnt ? 'Sí · ' + (ANT_TXT[S.TIPO_ANTICIPO] || '') + (S.VALOR_ANTICIPO ? ' · ' + Gastos.moneda(S.VALOR_ANTICIPO) : '') : 'No: ya tenía el preliminar'],
          ['Tercero', tercero(S)], ['Contacto', [S.TERCERO_CELULAR, S.TERCERO_EMAIL].filter(Boolean).join(' · ')], ['Centro de costos', S.CENTRO_COSTOS],
          ['N.º de preliminar', hayAnt ? '' : S.NUM_PRELIMINAR], ['Fecha de la factura', hayAnt || !S.FECHA_FACTURA ? '' : Gastos.fecha(S.FECHA_FACTURA)],
          ['Valor del preliminar', hayAnt || !S.VALOR_TOTAL ? '' : Gastos.moneda(S.VALOR_TOTAL)], ['¿Sale de un fondo?', hayAnt ? '' : fondoTxt(S)]]);
      case 'SOLICITAR_ANTICIPO':
        return kv([['Tipo de anticipo', ANT_TXT[S.TIPO_ANTICIPO]], ['Valor del anticipo', S.VALOR_ANTICIPO ? Gastos.moneda(S.VALOR_ANTICIPO) : ''], ['Departamento / proceso', S.PROCESO],
          ['Solicita', tercero(S)], ['Contacto', [S.TERCERO_CELULAR, S.TERCERO_EMAIL].filter(Boolean).join(' · ')], ['Centro de costos', S.CENTRO_COSTOS],
          ['Descripción', S.TIPO_ANTICIPO === 'VIATICOS' ? '' : S.DESCRIPCION]]) + (S.TIPO_ANTICIPO === 'VIATICOS' ? tarjetaF023(S) : '');
      case 'MONTAR_PRELIMINAR':
        if (S.TIPO_ANTICIPO === 'VIATICOS') return tarjetaF024(S);
        return kv([['N.º de preliminar', S.NUM_PRELIMINAR], ['Fecha de la factura', S.FECHA_FACTURA ? Gastos.fecha(S.FECHA_FACTURA) : ''],
          ['Valor de la factura', S.VALOR_TOTAL ? Gastos.moneda(S.VALOR_TOTAL) : ''], ['¿Sale de un fondo?', fondoTxt(S)], ['Tercero', tercero(S)]]);
      case 'CONTABILIZAR':
        return kv([['N.º de contabilización', S.NUM_CONTABILIZACION], ['Causación de compensación', S.NUM_COMPENSACION]]);
      case 'PAGAR':
        return kv([['Comprobante ZP', S.NUM_COMPROBANTE_ZP], ['Fecha de pago', S.FECHA_PAGO ? Gastos.fecha(S.FECHA_PAGO) : '']]);
      default:
        return '';
    }
  }

  const DEC_BADGE = {
    APROBADO: ['Aprobado', 'bg-emerald-100 text-emerald-800'], COMPLETADO: ['Realizado', 'bg-slate-100 text-slate-700'],
    RECHAZADO: ['Rechazado', 'bg-rose-100 text-rose-800'], DEVUELTO: ['Devuelto', 'bg-amber-100 text-amber-800'],
  };

  function cardPaso(S, p) {
    const b = DEC_BADGE[p.DECISION] || DEC_BADGE.COMPLETADO;
    const cuerpo = cuerpoPaso(S, p);
    const adj = adjuntosPaso(S, p.ORDEN);
    return '<article class="rounded-xl border border-slate-200 bg-white">' +
      '<header class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">' +
        '<div class="flex items-center gap-3"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-50 text-indigo-700">' + icono(ICONOS_ACC(p.ACCION)) + '</span>' +
        '<div><h3 class="text-sm font-semibold text-slate-900">' + esc(p.NOMBRE) + '</h3>' +
        '<p class="text-xs text-slate-500">' + esc(p.EJECUTOR_NOMBRE || p.RESPONSABLE) + ' · ' + Gastos.fecha(p.FECHA_FIN) + '</p></div></div>' +
        '<span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ' + b[1] + '">' + b[0] + '</span></header>' +
      '<div class="space-y-3 p-4">' + cuerpo + adj +
        (p.COMENTARIO ? '<div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-700"><span class="' + C.label + '">Comentario</span><br>“' + esc(p.COMENTARIO) + '”</div>' : '') +
        (!cuerpo && !adj && !p.COMENTARIO ? '<p class="text-sm text-slate-500">Paso realizado.</p>' : '') + '</div></article>';
  }

  function ICONOS_ACC(accion) { return ICONO_ACCION[accion] || 'doc'; }

  /** Resumen general (quién, dónde y de qué trata) + una card por cada paso ya realizado (los omitidos no se muestran). */
  function cardsDetalle(S) {
    const hechos = S.pasos.filter(function (p) { return p.ESTADO === 'COMPLETADO' || p.ESTADO === 'RECHAZADO'; });
    const resumen = '<article class="rounded-xl border border-slate-200 bg-white"><header class="flex items-center gap-3 border-b border-slate-100 px-4 py-3">' +
      '<span class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-50 text-indigo-700">' + icono('resumen') + '</span>' +
      '<h3 class="text-sm font-semibold text-slate-900">Datos de la solicitud</h3></header><div class="p-4">' +
      kv([['Solicitante', S.SOLICITANTE], ['Oficina', S.OFICINA_NOMBRE ? S.OFICINA_NOMBRE + ' (' + S.OFICINA_VENTAS + ')' : S.OFICINA_VENTAS],
        ['Tipo', (TIPO_TXT[S.TIPO] || S.TIPO) + (S.FLUJO_NOMBRE ? ' · ' + S.FLUJO_NOMBRE : '')], ['Creada', Gastos.fecha(S.FECHA_CREACION)],
        ['Motivo de cierre', S.MOTIVO_CIERRE]]) + '</div></article>';
    return '<section class="space-y-4"><h2 class="text-sm font-semibold text-slate-700">Qué se ha hecho</h2>' + resumen + hechos.map(function (p) { return cardPaso(S, p); }).join('') + '</section>';
  }

  /* ------------------------------------- "Para tu decisión": lo relevante del paso anterior */

  /**
   * Cuadro al inicio del formulario con lo que quien actúa necesita ver sin bajar: a quién, qué
   * valor y los documentos. Depende de la acción del paso en curso y de en qué punto va la solicitud.
   */
  function paraTuDecision(S, paso) {
    const preliminar = !!S.NUM_PRELIMINAR || (S.VALOR_LEGALIZADO !== null && S.VALOR_LEGALIZADO !== undefined);
    const docs = (S.archivos || []).filter(function (a) { return a.CATEGORIA !== 'COTIZACION' && a.CATEGORIA !== 'COMPROBANTE'; });
    const lista = docs.length ? '<div><div class="' + C.label + '">Documentos</div><ul class="space-y-1 text-sm">' + docs.map(function (a) { return '<li>' + enlacePdf(a) + '</li>'; }).join('') + '</ul></div>' : '';
    const ult = S.pasos.filter(function (p) { return p.ESTADO === 'COMPLETADO' && Number(p.ORDEN) < Number(paso.ORDEN) && p.COMENTARIO; }).pop();
    const comentario = ult ? '<div class="rounded-lg bg-white/70 p-2 text-sm text-slate-700"><span class="' + C.label + '">Comentario de «' + esc(ult.NOMBRE) + '»</span><br>“' + esc(ult.COMENTARIO) + '”</div>' : '';
    const valorGrande = function (etq, v) { return '<div><div class="' + C.label + '">' + etq + '</div><div class="text-2xl font-bold tabular-nums text-slate-900">' + Gastos.moneda(v) + '</div></div>'; };
    let h = '';
    switch (paso.ACCION) {
      case 'APROBAR':
        if (!preliminar) {
          // Aprobación del anticipo: a quién y cuánto.
          h = '<div class="grid gap-4 sm:grid-cols-2">' + valorGrande('Valor del anticipo', S.VALOR_ANTICIPO) +
            kv([['A quién', tercero(S)], ['Tipo', ANT_TXT[S.TIPO_ANTICIPO]], ['Departamento / proceso', S.PROCESO]]) + '</div>' +
            (S.TIPO_ANTICIPO === 'VIATICOS' ? tarjetaF023(S) : kv([['Descripción', S.DESCRIPCION]]) +
              (S.cotizaciones.length ? tablaCotizaciones(S) : ''));
        } else {
          // Aprobación del preliminar / legalización.
          h = '<div class="grid gap-4 sm:grid-cols-2">' + valorGrande(S.VALOR_LEGALIZADO !== null && S.VALOR_LEGALIZADO !== undefined ? 'Total legalizado' : 'Valor del preliminar', S.VALOR_TOTAL) +
            kv([['A quién', tercero(S)], ['N.º de preliminar', S.NUM_PRELIMINAR], ['Fecha de la factura', S.FECHA_FACTURA ? Gastos.fecha(S.FECHA_FACTURA) : ''],
              ['Anticipo entregado', Number(S.REQUIERE_ANTICIPO) ? Gastos.moneda(S.VALOR_ANTICIPO) : ''], ['¿Sale de un fondo?', fondoTxt(S)]]) + '</div>' +
            (S.TIPO_ANTICIPO === 'VIATICOS' ? tarjetaF024(S) : '') + lista;
        }
        break;
      case 'CONTABILIZAR':
        h = '<div class="grid gap-4 sm:grid-cols-2">' + valorGrande('Valor a contabilizar', valorPrincipal(S)) +
          kv([['Tercero', tercero(S)], ['N.º de preliminar', S.NUM_PRELIMINAR], ['¿Sale de un fondo?', fondoTxt(S)],
            ['Anticipo entregado', Number(S.REQUIERE_ANTICIPO) ? Gastos.moneda(S.VALOR_ANTICIPO) : '']]) + '</div>' + lista;
        break;
      case 'PAGAR':
        h = '<div class="grid gap-4 sm:grid-cols-2">' + valorGrande('Valor a pagar', valorPrincipal(S)) +
          kv([['A quién', tercero(S)], ['Contacto', [S.TERCERO_CELULAR, S.TERCERO_EMAIL].filter(Boolean).join(' · ')], ['¿Sale de un fondo?', fondoTxt(S)],
            ['N.º de contabilización', S.NUM_CONTABILIZACION], ['N.º de preliminar', S.NUM_PRELIMINAR]]) + '</div>' + lista;
        break;
      case 'AUTORIZAR_COTIZACION':
        h = kv([['Departamento / proceso', S.PROCESO], ['Soporte de pago', Number(S.REQUIERE_SOPORTE_PAGO) ? 'Requerido' : 'No requerido'], ['Descripción', S.DESCRIPCION]]);
        break;
      default:
        break;
    }
    if (!h && !comentario) return '';
    return '<div class="space-y-3 rounded-xl border border-indigo-200 bg-indigo-50/50 p-4"><div class="text-xs font-bold uppercase tracking-wide text-indigo-700">Para tu decisión</div>' + h + comentario + '</div>';
  }

  /* --------------------------------------------------------------- línea de tiempo */

  function lineaTiempo(S) {
    const ICONO = {
      COMPLETADO: ['✓', 'bg-emerald-500 text-white'], ACTUAL: ['●', 'bg-amber-400 text-white'],
      PENDIENTE: ['', 'bg-slate-200 text-slate-400'], OMITIDO: ['–', 'bg-slate-100 text-slate-400'], RECHAZADO: ['✕', 'bg-rose-500 text-white'],
    };
    // Los pasos omitidos (no participan en esta solicitud) no se muestran.
    return '<ol class="space-y-4">' + S.pasos.filter(function (p) { return p.ESTADO !== 'OMITIDO'; }).map(function (p) {
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

  /** Devoluciones para corrección (más reciente primero). */
  function historialDevoluciones(S) {
    const ev = (S.eventos || []).filter(function (e) { return e.TIPO === 'DEVUELTO'; });
    if (!ev.length) return '';
    return '<h3 class="mb-2 mt-6 text-xs font-semibold uppercase tracking-wide text-slate-500">Devoluciones para corrección</h3><ul class="space-y-2">' +
      ev.map(function (e) {
        return '<li class="rounded-lg bg-amber-50 p-2 text-xs text-amber-900"><b>' + esc(e.USUARIO || '') + '</b> · ' + Gastos.fecha(e.FECHA) +
          '<div class="text-amber-800">De «' + esc(e.PASO_NOMBRE || '') + '» a «' + esc(e.DESTINO_NOMBRE || '') + '»</div>' +
          (e.COMENTARIO ? '<div class="mt-1">“' + esc(e.COMENTARIO) + '”</div>' : '') + '</li>';
      }).join('') + '</ul>';
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
    // Si este paso volvió por una devolución, se muestra quién y por qué.
    const dev = (S.eventos || []).filter(function (e) { return e.TIPO === 'DEVUELTO'; })[0];
    const aviso = dev && Number(dev.DESTINO_ORDEN) === Number(paso.ORDEN)
      ? '<div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"><b>Devuelta para corrección</b> por ' + esc(dev.USUARIO || '') +
        ' (' + esc(dev.PASO_NOMBRE || '') + ') el ' + Gastos.fecha(dev.FECHA) + ':<br>«' + esc(dev.COMENTARIO || '') + '»<br>' +
        '<span class="text-xs">Tus datos anteriores están prellenados: corrige lo necesario y vuelve a enviar.' +
        (paso.ACCION === 'SUBIR_COTIZACIONES' || paso.ACCION === 'MONTAR_PRELIMINAR' ? ' Los PDF se deben adjuntar de nuevo.' : '') + '</span></div>'
      : '';
    return '<form id="formPaso" novalidate class="space-y-4 rounded-xl border-2 border-indigo-200 bg-white p-5">' +
      '<div><div class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Te toca actuar</div>' +
      '<h2 class="text-lg font-bold text-slate-900">' + esc(paso.NOMBRE) + '</h2><p class="text-sm text-slate-500">' + esc(info) + '</p></div>' + aviso +
      paraTuDecision(S, paso) +
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

      const dec = form.elements.decision ? form.elements.decision.value : '';
      const rechaza = dec === 'RECHAZAR';
      if (dec === 'DEVOLVER' && form.elements.comentario && !form.elements.comentario.value.trim()) {
        Gastos.error({ gastos: true, mensaje: 'Escribe en el comentario qué debe corregir el solicitante.' });
        return;
      }
      // Algunos pasos definen su propia advertencia (p. ej. anticipo vs. preliminar).
      const propia = Pasos.FORMS[accion].confirmar ? Pasos.FORMS[accion].confirmar(form, S) : null;
      const c = propia || (dec === 'DEVOLVER' ? {
        titulo: '¿Devolver para corrección?', texto: 'La solicitud vuelve al solicitante con tu comentario para que la corrija y la reenvíe.', boton: 'Sí, devolver',
      } : null) || {
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
    opcGlobal = opc;
    document.title = S.CODIGO + ' · Control de gastos';
    $app.innerHTML =
      '<div class="mb-5 flex flex-wrap items-center justify-between gap-3">' +
        '<div><div class="text-sm font-semibold text-indigo-700">' + esc(TIPO_TXT[S.TIPO] || S.TIPO) + (S.FLUJO_NOMBRE ? ' · ' + esc(S.FLUJO_NOMBRE) : '') + '</div>' +
        '<h1 class="text-2xl font-bold text-slate-900">' + esc(S.CODIGO) + '</h1>' +
        '<div class="mt-1 text-xs text-slate-500">Creada el ' + Gastos.fecha(S.FECHA_CREACION) + '</div></div>' +
        '<div class="flex items-center gap-3">' + Gastos.badgeEstado(S.ESTADO) +
        (S.puedeCancelar ? '<button id="btnCancelar" class="' + C.btn + ' ' + C.ghost + '">Cancelar solicitud</button>' : '') + '</div></div>' +
      '<div class="grid gap-6 lg:grid-cols-3">' +
        '<div class="space-y-6 lg:col-span-2">' + tarjetaAccion(S, opc) + cardsDetalle(S) + '</div>' +
        '<aside class="rounded-xl border border-slate-200 bg-white p-5 lg:self-start"><h2 class="mb-4 text-sm font-semibold text-slate-700">Flujo de autorización</h2>' + lineaTiempo(S) + historialDevoluciones(S) + '</aside>' +
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
