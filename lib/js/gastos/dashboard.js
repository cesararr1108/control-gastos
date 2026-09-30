/**
 * Dashboard (administradores): cantidad y valor por tipo y por rol del solicitante.
 *
 * Gráficas de barras horizontales en HTML: una sola serie por gráfica (un solo tono, el del tema),
 * con el valor escrito al final de cada barra y un tooltip al pasar el mouse. Cantidad y valor van
 * en gráficas separadas (nunca dos escalas en el mismo eje). La tabla rol × tipo es la vista en tabla.
 */
(function () {
  const esc = Gastos.esc;
  const $f = document.getElementById('filtrosDash');
  const $dash = document.getElementById('dash');
  const TIPO_TXT = { COTIZACION: 'Cotización', ANTICIPO: 'Anticipo', FACTURA: 'Factura' };
  const ESTADO_TXT = { EN_CURSO: 'En curso', FINALIZADA: 'Finalizadas', RECHAZADA: 'Rechazadas', CANCELADA: 'Canceladas' };

  const entero = function (n) { return Number(n || 0).toLocaleString('es-CO'); };

  /** Tooltip propio (reutilizado por todas las barras). */
  const tip = document.createElement('div');
  tip.className = 'pointer-events-none fixed z-50 hidden rounded-lg bg-slate-900 px-3 py-2 text-xs text-white shadow-lg';
  document.body.appendChild(tip);
  document.addEventListener('mousemove', function (e) {
    const b = e.target.closest('[data-tip]');
    if (!b) { tip.classList.add('hidden'); return; }
    tip.innerHTML = b.getAttribute('data-tip');
    tip.style.left = (e.clientX + 12) + 'px';
    tip.style.top = (e.clientY + 12) + 'px';
    tip.classList.remove('hidden');
  });

  /**
   * Barras horizontales. filas: [{etiqueta, valor, texto}] (texto = cifra ya formateada).
   * La barra completa (etiqueta + pista) es el área de hover, más grande que la marca.
   */
  function barras(titulo, filas, fmt) {
    const max = Math.max.apply(null, filas.map(function (r) { return r.valor; }).concat([0]));
    const cuerpo = filas.length ? filas.map(function (r) {
      const pct = max > 0 ? Math.max(1, (r.valor / max) * 100) : 0;
      const t = '<b>' + esc(r.etiqueta) + '</b><br>' + esc(fmt(r.valor));
      return '<div class="grid grid-cols-[minmax(0,10rem)_1fr] items-center gap-3 rounded-md px-1 py-1 hover:bg-slate-50" data-tip="' + esc(t) + '">' +
        '<div class="truncate text-sm text-slate-700" title="' + esc(r.etiqueta) + '">' + esc(r.etiqueta) + '</div>' +
        '<div class="flex items-center gap-2">' +
          '<div class="h-3 rounded-r bg-indigo-600" style="width:' + pct.toFixed(1) + '%"></div>' +
          '<span class="shrink-0 text-xs font-semibold tabular-nums text-slate-700">' + esc(fmt(r.valor)) + '</span>' +
        '</div></div>';
    }).join('') : '<p class="text-sm text-slate-500">Sin datos.</p>';
    return '<section class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="mb-3 text-sm font-semibold text-slate-700">' + esc(titulo) + '</h2>' + cuerpo + '</section>';
  }

  function tile(etiqueta, cifra) {
    return '<div class="rounded-xl border border-slate-200 bg-white p-4"><div class="text-2xl font-bold tabular-nums text-slate-900">' + cifra + '</div>' +
      '<div class="mt-1 text-xs font-medium text-slate-500">' + esc(etiqueta) + '</div></div>';
  }

  /** Tabla rol × tipo: cantidad y valor por celda, con totales por fila. */
  function tablaRolTipo(rows) {
    const tipos = ['COTIZACION', 'ANTICIPO', 'FACTURA'];
    const roles = {};
    rows.forEach(function (r) {
      roles[r.ROL] = roles[r.ROL] || {};
      roles[r.ROL][r.TIPO] = r;
    });
    const nombres = Object.keys(roles).sort();
    if (!nombres.length) return '';
    const celda = function (r) {
      return r ? '<div class="tabular-nums">' + entero(r.CANT) + '</div><div class="text-xs text-slate-500 tabular-nums">' + Gastos.moneda(r.VALOR) + '</div>' : '<span class="text-slate-300">—</span>';
    };
    return '<section class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="mb-3 text-sm font-semibold text-slate-700">Solicitudes por rol y tipo (cantidad y valor)</h2>' +
      '<div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="text-left text-xs uppercase tracking-wide text-slate-500"><tr>' +
      '<th class="py-2 pr-4">Rol del solicitante</th>' + tipos.map(function (t) { return '<th class="py-2 pr-4 text-right">' + TIPO_TXT[t] + '</th>'; }).join('') +
      '<th class="py-2 text-right">Total</th></tr></thead><tbody class="divide-y divide-slate-100">' +
      nombres.map(function (n) {
        let c = 0, v = 0;
        tipos.forEach(function (t) { if (roles[n][t]) { c += Number(roles[n][t].CANT); v += Number(roles[n][t].VALOR); } });
        return '<tr><td class="py-2 pr-4 font-medium text-slate-800">' + esc(n) + '</td>' +
          tipos.map(function (t) { return '<td class="py-2 pr-4 text-right">' + celda(roles[n][t]) + '</td>'; }).join('') +
          '<td class="py-2 text-right font-semibold">' + celda({ CANT: c, VALOR: v }) + '</td></tr>';
      }).join('') + '</tbody></table></div></section>';
  }

  function pintar(d) {
    const total = d.porTipo.reduce(function (a, r) { return a + Number(r.CANT); }, 0);
    const valor = d.porTipo.reduce(function (a, r) { return a + Number(r.VALOR); }, 0);
    const est = {};
    d.porEstado.forEach(function (r) { est[r.ESTADO] = Number(r.CANT); });

    const porTipo = function (campo) {
      return d.porTipo.map(function (r) { return { etiqueta: TIPO_TXT[r.TIPO] || r.TIPO, valor: Number(r[campo]) }; });
    };
    const porRol = function (campo) {
      return d.porRol.map(function (r) { return { etiqueta: r.ROL, valor: Number(r[campo]) }; })
        .sort(function (a, b) { return b.valor - a.valor; });
    };

    $dash.innerHTML =
      '<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">' +
        tile('Solicitudes', entero(total)) + tile('Valor total', Gastos.moneda(valor)) +
        Object.keys(ESTADO_TXT).map(function (k) { return tile(ESTADO_TXT[k], entero(est[k] || 0)); }).join('') +
      '</div>' +
      '<div class="grid gap-6 lg:grid-cols-2">' +
        barras('Cantidad por tipo', porTipo('CANT'), entero) +
        barras('Valor por tipo', porTipo('VALOR'), Gastos.moneda) +
        barras('Cantidad por rol del solicitante', porRol('CANT'), entero) +
        barras('Valor por rol del solicitante', porRol('VALOR'), Gastos.moneda) +
      '</div>' + tablaRolTipo(d.porRolTipo);
  }

  function consultar() {
    const fd = new FormData($f);
    if (fd.get('desde') > fd.get('hasta')) {
      return Gastos.error({ gastos: true, mensaje: 'La fecha «desde» no puede ser mayor que «hasta».' });
    }
    return Gastos.api('solicitudes', 'dashboard', { desde: fd.get('desde'), hasta: fd.get('hasta'), org: fd.get('org'), estado: fd.get('estado') })
      .then(pintar)
      .catch(function (e) {
        $dash.innerHTML = '<div class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">' + esc(Gastos.mensajeError(e)) + '</div>';
      });
  }

  // Rango por defecto: del 1 de enero a hoy.
  const hoy = new Date();
  $f.elements.desde.value = Gastos.isoFecha(new Date(hoy.getFullYear(), 0, 1));
  $f.elements.hasta.value = Gastos.isoFecha(hoy);
  $f.addEventListener('submit', function (e) { e.preventDefault(); consultar(); });

  Gastos.opciones().then(function (o) {
    document.getElementById('dashOrg').innerHTML = Gastos.opts((o.organizaciones || []).map(function (x) { return [x, 'Organización ' + x]; }), '', 'Todas');
  }).catch(function () {}).then(consultar);
})();
