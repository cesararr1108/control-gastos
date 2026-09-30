/**
 * Núcleo del módulo de control de gastos (namespace global `Gastos`).
 * Depende de jQuery, SweetAlert2 y lib/js/servicios.js (enviarPeticion).
 */
const Gastos = (function () {
  const API = window.GASTOS_API || '../../controllers/gastos/api.php';

  /** Clases Tailwind reutilizadas por los formularios. */
  const CLS = {
    input: 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500',
    label: 'mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500',
    btn: 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition',
    primary: 'bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-50',
    ghost: 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
    danger: 'bg-rose-600 text-white hover:bg-rose-700',
  };

  const ESTADOS = {
    EN_CURSO: ['En curso', 'bg-amber-100 text-amber-800'],
    FINALIZADA: ['Finalizada', 'bg-emerald-100 text-emerald-800'],
    RECHAZADA: ['Rechazada', 'bg-rose-100 text-rose-800'],
    CANCELADA: ['Cancelada', 'bg-slate-200 text-slate-700'],
  };

  /** Escapa texto antes de insertarlo en HTML (evita XSS con datos de la BD). */
  function esc(v) {
    return String(v === null || v === undefined ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /**
   * Convierte importes escritos a la colombiana a número: "1.250.000,50" -> 1250000.5,
   * "150.000" -> 150000 (punto seguido de 3 dígitos = miles), "1234.5" -> 1234.5.
   * GasDb::aNumero() (PHP) aplica la misma regla.
   */
  function num(v) {
    let s = String(v === null || v === undefined ? '' : v).trim();
    if (s === '') return 0;
    if (s.indexOf(',') !== -1) s = s.replace(/\./g, '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
    const n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }

  function moneda(v) {
    if (v === null || v === undefined || v === '') return '—';
    return '$ ' + Number(v).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  /** "2026-09-29 14:05:00" -> "29/09/2026 14:05" */
  function fecha(v) {
    if (!v) return '—';
    const m = String(v).match(/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/);
    if (!m) return esc(v);
    return m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '');
  }

  function badgeEstado(estado) {
    const e = ESTADOS[estado] || [estado, 'bg-slate-100 text-slate-700'];
    return '<span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ' + e[1] + '">' + esc(e[0]) + '</span>';
  }

  /** Extrae un mensaje legible de un error de enviarPeticion / $.ajax. */
  function mensajeError(err) {
    if (err && err.gastos) return err.mensaje;
    try {
      const j = JSON.parse(err.message || (err.xhr && err.xhr.responseText));
      if (j && j.mensaje) return j.mensaje;
    } catch (e) { /* respuesta no JSON */ }
    return 'No se pudo completar la operación. Revisa tu conexión e intenta de nuevo.';
  }

  /** Procesa la respuesta estándar {ok, data, mensaje}. */
  function procesar(resp) {
    if (resp && resp.ok) return resp.data;
    throw { gastos: true, mensaje: (resp && resp.mensaje) || 'Error desconocido.' };
  }

  /** Llama a la API con parámetros planos. Devuelve una promesa con `data`. */
  function api(recurso, accion, datos) {
    return enviarPeticion(Object.assign({ link: API, recurso: recurso, accion: accion }, datos || {}))
      .then(procesar);
  }

  /** Envía un FormData (con PDF) a la API. */
  function enviarForm(recurso, accion, formData) {
    formData.set('recurso', recurso);
    formData.set('accion', accion);
    return new Promise(function (resolve, reject) {
      $.ajax({
        url: API, type: 'POST', data: formData, dataType: 'json',
        contentType: false, processData: false, cache: false,
        success: function (r) { try { resolve(procesar(r)); } catch (e) { reject(e); } },
        error: function (xhr) { reject({ status: xhr.status, message: xhr.responseText, xhr: xhr }); },
      });
    });
  }

  function urlPdf(id) {
    return API + '?recurso=archivos&accion=ver&id=' + encodeURIComponent(id);
  }

  /** Catálogos (procesos, roles, etc.): se piden una sola vez por página. */
  let _opciones = null;
  function opciones() {
    if (!_opciones) _opciones = api('catalogo', 'opciones');
    return _opciones;
  }

  /* ------------------------------------------------------------------ avisos */

  function error(err) {
    return Swal.fire({ icon: 'error', title: 'No se pudo continuar', text: mensajeError(err), confirmButtonColor: (window.GASTOS_COLOR || '#4f46e5') });
  }

  function ok(texto) {
    return Swal.fire({ icon: 'success', title: texto, timer: 1600, showConfirmButton: false });
  }

  function confirmar(titulo, texto, botonSi) {
    return Swal.fire({
      icon: 'question', title: titulo, text: texto || '', showCancelButton: true,
      confirmButtonText: botonSi || 'Sí, continuar', cancelButtonText: 'Volver', confirmButtonColor: (window.GASTOS_COLOR || '#4f46e5'),
    }).then(function (r) { return r.isConfirmed; });
  }

  /** Bloquea el botón mientras corre la promesa y muestra el error si falla. */
  function conBoton(btn, promesa) {
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = 'Procesando…';
    return promesa.finally(function () { btn.disabled = false; btn.innerHTML = original; });
  }

  /**
   * Interpreta el saldo de una legalización (anticipo - legalizado).
   * Devuelve { titulo, detalle, cls } para mostrarlo con texto (nunca solo con color).
   */
  function saldo(valor, quien) {
    const q = quien || 'empleado';
    const v = Math.round(Number(valor || 0) * 100) / 100;
    if (v > 0) return { titulo: 'Saldo a favor de la empresa: ' + moneda(v), detalle: 'El ' + q + ' debe reintegrar este valor. El comprobante del reintegro va en el PDF de soportes.', cls: 'border-amber-300 bg-amber-50 text-amber-900' };
    if (v < 0) return { titulo: 'Saldo a favor del ' + q + ': ' + moneda(-v), detalle: 'Se gastó más que el anticipo: se le debe pagar la diferencia.', cls: 'border-sky-300 bg-sky-50 text-sky-900' };
    return { titulo: 'Legalización cuadrada', detalle: 'Lo legalizado es igual al anticipo: no hay saldo.', cls: 'border-emerald-300 bg-emerald-50 text-emerald-900' };
  }

  /** Carga un script externo una sola vez (p. ej. la librería de Excel, solo cuando se necesita). */
  const _scripts = {};
  function cargarScript(url) {
    if (!_scripts[url]) {
      _scripts[url] = new Promise(function (resolve, reject) {
        const s = document.createElement('script');
        s.src = url;
        s.onload = resolve;
        s.onerror = function () { delete _scripts[url]; reject({ gastos: true, mensaje: 'No se pudo cargar ' + url }); };
        document.head.appendChild(s);
      });
    }
    return _scripts[url];
  }

  /** "AAAA-MM-DD" de una fecha local. */
  function isoFecha(d) {
    const p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  function debounce(fn, ms) {
    let t;
    return function () {
      const args = arguments, ctx = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms);
    };
  }

  /** <option>s a partir de un arreglo de [valor, texto]. */
  function opts(pares, seleccionado, vacio) {
    let h = vacio !== undefined ? '<option value="">' + esc(vacio) + '</option>' : '';
    pares.forEach(function (p) {
      h += '<option value="' + esc(p[0]) + '"' + (String(p[0]) === String(seleccionado) ? ' selected' : '') + '>' + esc(p[1]) + '</option>';
    });
    return h;
  }

  return {
    API: API, CLS: CLS, ESTADOS: ESTADOS,
    esc: esc, mensajeError: mensajeError, num: num, moneda: moneda, fecha: fecha, badgeEstado: badgeEstado,
    api: api, enviarForm: enviarForm, urlPdf: urlPdf, opciones: opciones,
    error: error, ok: ok, confirmar: confirmar, conBoton: conBoton, debounce: debounce, opts: opts,
    cargarScript: cargarScript, isoFecha: isoFecha, saldo: saldo,
  };
})();
