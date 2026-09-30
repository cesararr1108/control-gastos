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

  /** Tarjetas de resumen: cifra grande + etiqueta. Las de trabajo pendiente se resaltan si hay algo. */
  function pintarTarjetas(t) {
    document.getElementById('alcanceTarjetas').textContent = t.todas ? 'Resumen de todas las solicitudes' : 'Resumen de tus solicitudes';
    const items = [
      ['Solicitudes', t.TOTAL, false],
      ['En curso (no finalizadas)', t.EN_CURSO, false],
      ['Por aprobar', t.POR_APROBAR, true],
      ['Para contabilidad', t.CONTABILIDAD, true],
      ['Para tesorería', t.TESORERIA, true],
      ['Finalizadas', t.FINALIZADAS, false],
      ['Rechazadas o canceladas', t.CERRADAS, false],
    ];
    document.getElementById('tarjetas').innerHTML = items.map(function (i) {
      const resalta = i[2] && i[1] > 0;
      return '<div class="rounded-xl border ' + (resalta ? 'border-indigo-300 bg-white ring-1 ring-indigo-200' : 'border-slate-200 bg-white') + ' p-4">' +
        '<div class="text-2xl font-bold tabular-nums text-slate-900">' + Number(i[1]).toLocaleString('es-CO') + '</div>' +
        '<div class="mt-1 text-xs font-medium text-slate-500">' + Gastos.esc(i[0]) + '</div></div>';
    }).join('');
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
    $filas.innerHTML = d.filas.map(function (f) {
      return '<tr class="cursor-pointer hover:bg-slate-50" onclick="location.href=\'solicitud.php?id=' + Number(f.ID) + '\'">' +
        '<td class="px-4 py-3"><div class="whitespace-nowrap font-semibold text-indigo-700">' + Gastos.esc(f.CODIGO) + '</div>' +
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
    const d = { q: f.get('q'), tipo: f.get('tipo'), estado: f.get('estado'), paso: f.get('paso') };
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
      ['NUM_PRELIMINAR', 'N.º preliminar'], ['FECHA_FACTURA', 'Fecha factura'], ['NUM_CONTABILIZACION', 'N.º contabilización'],
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
