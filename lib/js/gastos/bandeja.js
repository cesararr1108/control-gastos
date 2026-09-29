/**
 * Bandeja de solicitudes: pestañas por vista, filtros, paginación y creación de solicitudes.
 */
(function () {
  const estado = { vista: 'pendientes', pagina: 1 };
  const $filas = document.getElementById('filas');
  const $vacio = document.getElementById('vacio');
  const $form = document.getElementById('filtros');

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
        '<td class="px-4 py-3"><div class="font-semibold text-indigo-700">' + Gastos.esc(f.CODIGO) + '</div>' +
          '<div class="text-xs text-slate-500">' + Gastos.esc(TIPO_TXT[f.TIPO] || f.TIPO) + '</div></td>' +
        '<td class="px-4 py-3"><div>' + Gastos.esc(f.TERCERO_NOMBRE || '—') + '</div>' +
          '<div class="text-xs text-slate-500">' + Gastos.esc(f.TERCERO_NIT || '') + '</div></td>' +
        '<td class="px-4 py-3">' + Gastos.esc(f.PROCESO || '—') + '</td>' +
        '<td class="px-4 py-3 text-right tabular-nums">' + Gastos.moneda(f.VALOR) + '</td>' +
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

  function cargar() {
    pintarTabs();
    const f = new FormData($form);
    return Gastos.api('solicitudes', 'listar', {
      vista: estado.vista, pagina: estado.pagina,
      q: f.get('q'), tipo: f.get('tipo'), estado: f.get('estado'),
    }).then(pintarFilas).catch(Gastos.error);
  }

  /** Modal para elegir el tipo de solicitud y crearla. */
  function nueva() {
    const tarjeta = function (tipo, titulo, texto) {
      return '<button data-tipo="' + tipo + '" class="tipo w-full rounded-xl border border-slate-200 p-4 text-left hover:border-indigo-500 hover:bg-indigo-50">' +
        '<div class="font-semibold text-slate-900">' + titulo + '</div><div class="mt-1 text-sm text-slate-500">' + texto + '</div></button>';
    };
    Swal.fire({
      title: 'Nueva solicitud', showConfirmButton: false, showCloseButton: true, width: 520,
      html: '<div class="space-y-3 text-left">' +
        tarjeta('COTIZACION', 'Cotización', 'Sube 3 cotizaciones en PDF para que las autorice Gerencia Administrativa. Luego podrás pedir anticipo o montar el preliminar.') +
        tarjeta('ANTICIPO', 'Anticipo', 'Solicita un anticipo por factura o por viáticos (gastos de viaje).') +
        tarjeta('FACTURA', 'Factura', 'Monta directamente el preliminar de una factura para su aprobación y pago.') +
        '</div>',
      didOpen: function (el) {
        el.querySelectorAll('.tipo').forEach(function (b) {
          b.addEventListener('click', function () {
            Gastos.api('solicitudes', 'crear', { tipo: b.dataset.tipo })
              .then(function (d) { location.href = 'solicitud.php?id=' + d.id; })
              .catch(Gastos.error);
          });
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
