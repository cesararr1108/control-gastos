/**
 * Bandeja de solicitudes: pestañas por vista, filtros, paginación y creación de solicitudes.
 */
(function () {
  const estado = { vista: 'pendientes', pagina: 1, historialConsultado: false };
  const $filas = document.getElementById('filas');
  const $vacio = document.getElementById('vacio');
  const $form = document.getElementById('filtros');
  const $hist = document.getElementById('barraHistorial');
  const XLSX_URL = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';

  /* -------------------------------------------------------------- tarjetas */

  /** Iconos (trazo, 24x24) para las tarjetas; heredan el color del texto. */
  const ICONOS = {
    solicitudes: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
    enCurso: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    aprobar: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
    contabilidad: 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z',
    tesoreria: 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
    finalizadas: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    cerradas: 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z',
  };

  function icono(clave, cls) {
    return '<svg class="' + (cls || 'h-5 w-5') + '" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="' + ICONOS[clave] + '"/></svg>';
  }

  /**
   * Resumen: una tarjeta grande cuyo título es el total de solicitudes y, dentro, una tarjeta por
   * cada indicador con su icono. Las de trabajo pendiente se resaltan si hay algo por atender.
   */
  /** Color suave de cada tarjeta (clases completas para que Tailwind las detecte): fondo, borde, ícono. */
  const COLOR_TARJETA = {
    enCurso:      'bg-sky-50 border-sky-200 text-sky-700',
    aprobar:      'bg-violet-50 border-violet-200 text-violet-700',
    contabilidad: 'bg-amber-50 border-amber-200 text-amber-700',
    tesoreria:    'bg-emerald-50 border-emerald-200 text-emerald-700',
    finalizadas:  'bg-lime-50 border-lime-200 text-lime-700',
    cerradas:     'bg-rose-50 border-rose-200 text-rose-700',
  };

  function pintarTarjetas(t) {
    const items = [
      ['En curso (no finalizadas)', t.EN_CURSO, 'enCurso', false],
      ['Por aprobar', t.POR_APROBAR, 'aprobar', true],
      ['Para contabilidad', t.CONTABILIDAD, 'contabilidad', true],
      ['Para tesorería', t.TESORERIA, 'tesoreria', true],
      ['Finalizadas', t.FINALIZADAS, 'finalizadas', false],
      ['Rechazadas o canceladas', t.CERRADAS, 'cerradas', false],
    ];
    document.getElementById('tarjetas').innerHTML =
      '<div class="mb-4 flex items-center gap-3">' +
        '<span class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-50 text-indigo-700">' + icono('solicitudes', 'h-6 w-6') + '</span>' +
        '<div><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">' + (t.todas ? 'Solicitudes (todas)' : 'Tus solicitudes') + '</div>' +
        '<div class="text-3xl font-bold leading-none tabular-nums text-slate-900">' + Number(t.TOTAL).toLocaleString('es-CO') + '</div></div></div>' +
      '<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">' + items.map(function (i) {
        const resalta = i[3] && i[1] > 0; // las que esperan a un responsable llevan un anillo más marcado
        const c = COLOR_TARJETA[i[2]].split(' ');
        return '<div class="rounded-xl border ' + c[0] + ' ' + c[1] + (resalta ? ' ring-2 ring-offset-1 ring-slate-300' : '') + ' p-3">' +
          '<div class="flex items-center justify-between"><span class="' + c[2] + '">' + icono(i[2]) + '</span>' +
          '<span class="text-2xl font-bold tabular-nums text-slate-900">' + Number(i[1]).toLocaleString('es-CO') + '</span></div>' +
          '<div class="mt-2 text-xs font-medium leading-tight text-slate-600">' + Gastos.esc(i[0]) + '</div></div>';
      }).join('') + '</div>';
  }

  function cargarTarjetas() {
    return Gastos.api('solicitudes', 'estadisticas').then(pintarTarjetas).catch(function () {});
  }

  /** Opciones del filtro "paso actual" con los pasos que hoy tienen solicitudes en curso. */
  function cargarPasos() {
    return Gastos.opciones().then(function (o) {
      document.getElementById('filtroPaso').innerHTML = Gastos.opts((o.pasosActuales || []).map(function (n) { return [n, n]; }), '', 'Cualquier paso actual');
    }).catch(function () {});
  }

  const TIPO_TXT = { COTIZACION: 'Cotización', ANTICIPO: 'Anticipo', FACTURA: 'Factura' };

  function pintarTabs() {
    document.querySelectorAll('.tab').forEach(function (t) {
      const activa = t.dataset.vista === estado.vista;
      t.className = 'tab -mb-px border-b-2 px-4 py-2 ' +
        (activa ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700');
    });
  }

  /** Quién debe actuar en el paso en curso. */
  function quienActua(f) {
    if (f.ESTADO !== 'EN_CURSO' || !f.PASO_NOMBRE) return '';
    const quien = f.PASO_RESP_TIPO === 'SOLICITANTE' ? 'Solicitante' : (f.PASO_ROL || 'Usuario asignado');
    return '<div class="font-medium text-slate-800">' + Gastos.esc(f.PASO_NOMBRE) + '</div>' +
           '<div class="text-xs text-slate-500">' + Gastos.esc(quien) + '</div>';
  }

  function pintarFilas(d) {
    // En "Por atender": toda fila requiere tu atención; las que cambiaron en las últimas 24 h se marcan como "Nueva" / "Actualizada".
    const porAtender = estado.vista === 'pendientes';
    $filas.innerHTML = d.filas.map(function (f) {
      const min = Number(f.MIN_DESDE_CAMBIO);
      const reciente = porAtender && !isNaN(min) && min >= 0 && min < 1440;
      const nueva = reciente && f.FECHA_MODIFICACION === f.FECHA_CREACION;
      const marca = reciente
        ? '<span class="ml-2 inline-flex items-center gap-1 rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-bold uppercase text-rose-700">' +
          '<span class="h-1.5 w-1.5 animate-pulse rounded-full bg-rose-500"></span>' + (nueva ? 'Nueva' : 'Actualizada') + '</span>'
        : '';
      const borde = porAtender ? (reciente ? 'border-l-4 border-rose-500 bg-rose-50/40' : 'border-l-4 border-amber-300') : '';
      return '<tr class="cursor-pointer hover:bg-slate-50 ' + borde + '" onclick="location.href=\'solicitud.php?id=' + Number(f.ID) + '\'">' +
        '<td class="px-4 py-3"><div class="whitespace-nowrap font-semibold text-indigo-700">' + Gastos.esc(f.CODIGO) + marca + '</div>' +
          '<div class="text-xs text-slate-500">' + Gastos.esc(TIPO_TXT[f.TIPO] || f.TIPO) + '</div></td>' +
        '<td class="px-4 py-3"><div>' + Gastos.esc(f.TERCERO_NOMBRE || '—') + '</div>' +
          '<div class="text-xs text-slate-500">' + Gastos.esc(f.TERCERO_NIT || '') + '</div></td>' +
        '<td class="px-4 py-3">' + Gastos.esc(f.PROCESO || '—') + '</td>' +
        '<td class="px-4 py-3 whitespace-nowrap text-right tabular-nums">' + Gastos.moneda(f.VALOR) + '</td>' +
        '<td class="px-4 py-3">' + Gastos.badgeEstado(f.ESTADO) + '</td>' +
        '<td class="px-4 py-3">' + quienActua(f) + '</td>' +
        '<td class="px-4 py-3 whitespace-nowrap text-slate-500">' + Gastos.fecha(f.FECHA_CREACION) + '<div class="text-xs">' + Gastos.esc(f.SOLICITANTE) + '</div></td>' +
        '</tr>';
    }).join('');
    $vacio.classList.toggle('hidden', d.filas.length > 0);
    document.getElementById('resumen').textContent = d.total + ' solicitud(es) · página ' + d.pagina + ' de ' + d.paginas;
    document.getElementById('prev').disabled = d.pagina <= 1;
    document.getElementById('next').disabled = d.pagina >= d.paginas;
  }

  /** Filtros actuales (y rango de fechas si es el historial). */
  function filtrosActuales() {
    const f = new FormData($form);
    const d = { q: f.get('q'), tipo: f.get('tipo'), paso: f.get('paso') };
    if (estado.vista === 'historial') {
      d.desde = $hist.elements.desde.value;
      d.hasta = $hist.elements.hasta.value;
    }
    return d;
  }

  function cargar() {
    pintarTabs();
    const esHist = estado.vista === 'historial';
    $hist.classList.toggle('hidden', !esHist);
    $hist.classList.toggle('flex', esHist);
    // El historial no consulta solo: espera a que se pulse "Consultar".
    if (esHist && !estado.historialConsultado) {
      $filas.innerHTML = '';
      $vacio.textContent = 'Elige el rango de fechas y pulsa «Consultar».';
      $vacio.classList.remove('hidden');
      document.getElementById('resumen').textContent = '';
      document.getElementById('prev').disabled = document.getElementById('next').disabled = true;
      return Promise.resolve();
    }
    $vacio.textContent = 'No hay solicitudes para mostrar.';
    return Gastos.api('solicitudes', 'listar', Object.assign({ vista: estado.vista, pagina: estado.pagina }, filtrosActuales()))
      .then(pintarFilas).catch(Gastos.error);
  }

  /** Descarga el historial (mismos filtros y fechas) como archivo .xlsx. */
  function descargarExcel() {
    if (!$hist.reportValidity()) return;
    const btn = document.getElementById('btnExcel');
    const COLS = [
      ['CODIGO', 'Código'], ['TIPO', 'Tipo'], ['FLUJO', 'Flujo'], ['ESTADO', 'Estado'], ['PASO_ACTUAL', 'Paso actual'],
      ['RESPONSABLE_ACTUAL', 'Responsable actual'], ['SOLICITANTE', 'Solicitante'], ['ROL_SOLICITANTE', 'Rol del solicitante'],
      ['ORGANIZACION', 'Organización'], ['OFICINA', 'Oficina'], ['PROCESO', 'Proceso'], ['DESCRIPCION', 'Descripción'],
      ['TERCERO_NIT', 'NIT tercero'], ['TERCERO_NOMBRE', 'Tercero'], ['CARGO', 'Cargo'], ['CENTRO_COSTOS', 'Centro de costos'],
      ['TIPO_ANTICIPO', 'Tipo de anticipo'], ['VALOR_ANTICIPO', 'Valor anticipo'], ['VALOR_TOTAL', 'Valor preliminar'], ['RETEFUENTE_LEGALIZACION', 'Retefuente legalización'], ['VALOR_LEGALIZADO', 'Valor legalizado'], ['SALDO_LEGALIZACION', 'Saldo legalización (+ a favor empresa / - a favor tercero)'],
      ['NUM_PRELIMINAR', 'N.º preliminar'], ['FONDO', 'Fondo'], ['FECHA_FACTURA', 'Fecha factura'], ['NUM_CONTABILIZACION', 'N.º contabilización'],
      ['NUM_COMPENSACION', 'N.º compensación'], ['NUM_COMPROBANTE_ZP', 'Comprobante ZP'], ['FECHA_PAGO', 'Fecha de pago'],
      ['FECHA_CREACION', 'Creada'], ['FECHA_FIN', 'Cerrada'], ['MOTIVO_CIERRE', 'Motivo de cierre'],
    ];
    const NUMEROS = { VALOR_ANTICIPO: 1, VALOR_TOTAL: 1, VALOR_LEGALIZADO: 1, SALDO_LEGALIZACION: 1, RETEFUENTE_LEGALIZACION: 1 };
    Gastos.conBoton(btn, Promise.all([Gastos.cargarScript(XLSX_URL), Gastos.api('solicitudes', 'exportar', filtrosActuales())]))
      .then(function (r) {
        const filas = r[1].map(function (row) {
          const o = {};
          COLS.forEach(function (c) {
            const v = row[c[0]];
            o[c[1]] = NUMEROS[c[0]] && v !== null && v !== '' ? Number(v) : (v === null ? '' : v);
          });
          return o;
        });
        if (!filas.length) return Gastos.error({ gastos: true, mensaje: 'No hay solicitudes en ese rango de fechas.' });
        const hoja = XLSX.utils.json_to_sheet(filas, { header: COLS.map(function (c) { return c[1]; }) });
        const libro = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(libro, hoja, 'Historial');
        XLSX.writeFile(libro, 'historial-gastos_' + $hist.elements.desde.value + '_' + $hist.elements.hasta.value + '.xlsx');
      })
      .catch(Gastos.error);
  }

  /** Crea la solicitud y abre su detalle. */
  function crear(tipo, flujoId) {
    Gastos.api('solicitudes', 'crear', { tipo: tipo, flujo_id: flujoId || '' })
      .then(function (d) { location.href = 'solicitud.php?id=' + d.id; })
      .catch(Gastos.error);
  }

  /**
   * Tras elegir el tipo se pide confirmación (crear la solicitud es inmediato); luego, si hay varios
   * flujos disponibles se pregunta cuál usar, y si no se crea directo.
   */
  function elegirFlujo(tipo) {
    Gastos.confirmar('¿Crear la solicitud de ' + (TIPO_TXT[tipo] || tipo) + '?',
      'Se registrará de inmediato. Si te equivocas, podrás cancelarla desde su detalle.', 'Sí, crear').then(function (si) {
      if (!si) return nueva();
      return Gastos.api('solicitudes', 'variantes', { tipo: tipo }).then(function (v) {
        if (v.length === 0) {
          return Gastos.error({ gastos: true, mensaje: 'No hay un flujo activo para tu organización. Pide al administrador que lo configure.' });
        }
        if (v.length === 1) return crear(tipo, v[0].ID);
        Swal.fire({
          title: 'Elige el flujo', showConfirmButton: false, showCloseButton: true, width: 520,
          html: '<div class="space-y-3 text-left">' + v.map(function (f) {
            return '<button data-flujo="' + Number(f.ID) + '" class="flujo w-full rounded-xl border border-slate-200 p-4 text-left font-semibold text-slate-900 hover:border-indigo-500 hover:bg-indigo-50">' + Gastos.esc(f.NOMBRE) + '</button>';
          }).join('') + '</div>',
          didOpen: function (el) {
            el.querySelectorAll('.flujo').forEach(function (b) {
              b.addEventListener('click', function () { crear(tipo, b.dataset.flujo); });
            });
          },
        });
      }).catch(Gastos.error);
    });
  }

  /** Modal para elegir el tipo de solicitud. */
  function nueva() {
    const tarjeta = function (tipo, titulo, texto) {
      return '<button data-tipo="' + tipo + '" class="tipo w-full rounded-xl border border-slate-200 p-4 text-left hover:border-indigo-500 hover:bg-indigo-50">' +
        '<div class="font-semibold text-slate-900">' + titulo + '</div><div class="mt-1 text-sm text-slate-500">' + texto + '</div></button>';
    };
    Swal.fire({
      title: 'Nueva solicitud', showConfirmButton: false, showCloseButton: true, width: 520,
      html: '<div class="space-y-3 text-left">' +
        '<div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">⚠ <b>Al elegir un tipo se crea la solicitud de inmediato.</b> ' +
        'Elige con cuidado: si te equivocas, tendrás que cancelarla.</div>' +
        tarjeta('COTIZACION', 'Cotización', 'Sube 3 cotizaciones en PDF para que las autorice Gerencia Administrativa. Luego podrás pedir anticipo o montar el preliminar.') +
        tarjeta('ANTICIPO', 'Anticipo', 'Solicita un anticipo por factura o por viáticos (gastos de viaje).') +
        tarjeta('FACTURA', 'Factura', 'Monta directamente el preliminar de una factura para su aprobación y pago.') +
        '</div>',
      didOpen: function (el) {
        el.querySelectorAll('.tipo').forEach(function (b) {
          b.addEventListener('click', function () { elegirFlujo(b.dataset.tipo); });
        });
      },
    });
  }

  document.getElementById('tabs').addEventListener('click', function (e) {
    const t = e.target.closest('.tab');
    if (!t) return;
    estado.vista = t.dataset.vista;
    estado.pagina = 1;
    cargar();
  });
  $form.addEventListener('submit', function (e) { e.preventDefault(); });
  $form.addEventListener('input', Gastos.debounce(function () { estado.pagina = 1; cargar(); }, 350));

  // Historial: rango por defecto = mes actual; solo consulta al pulsar "Consultar".
  const hoy = new Date();
  $hist.elements.desde.value = Gastos.isoFecha(new Date(hoy.getFullYear(), hoy.getMonth(), 1));
  $hist.elements.hasta.value = Gastos.isoFecha(hoy);
  $hist.addEventListener('submit', function (e) {
    e.preventDefault();
    if ($hist.elements.desde.value > $hist.elements.hasta.value) {
      return Gastos.error({ gastos: true, mensaje: 'La fecha «desde» no puede ser mayor que «hasta».' });
    }
    estado.historialConsultado = true;
    estado.pagina = 1;
    cargar();
  });
  document.getElementById('btnExcel').addEventListener('click', descargarExcel);
  cargarTarjetas();
  cargarPasos();
  document.getElementById('prev').addEventListener('click', function () { estado.pagina--; cargar(); });
  document.getElementById('next').addEventListener('click', function () { estado.pagina++; cargar(); });
  document.getElementById('btnNueva').addEventListener('click', nueva);

  // Si no hay nada por atender, abrir directamente "Mis solicitudes".
  cargar().then(function () {
    if (estado.vista === 'pendientes' && document.getElementById('resumen').textContent.indexOf('0 solicitud') === 0) {
      estado.vista = 'mias';
      cargar();
    }
  });
})();
