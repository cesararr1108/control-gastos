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

  /** 1250000.5 -> "1.250.000,5" (puntos de miles y coma decimal, sin depender del navegador). */
  function formatoNum(v) {
    const n = Math.round(Number(v || 0) * 100) / 100;
    if (!n) return '';
    const p = Math.abs(n).toFixed(2).split('.');
    const ent = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const dec = p[1].replace(/0+$/, '');
    return (n < 0 ? '-' : '') + ent + (dec ? ',' + dec : '');
  }

  /**
   * Formatea un campo de dinero mientras se escribe: los puntos de miles los pone el sistema
   * (cualquier punto que escriba el usuario se ignora) y la coma es el decimal (máx. 2).
   * El cursor se conserva después del mismo número de dígitos.
   */
  function formatearDinero(el) {
    const v = el.value;
    const pos = el.selectionStart === null ? v.length : el.selectionStart;
    const antes = v.slice(0, pos).replace(/[^\d,]/g, '').length;
    const coma = v.indexOf(',');
    let ent = (coma === -1 ? v : v.slice(0, coma)).replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    const dec = coma === -1 ? null : v.slice(coma + 1).replace(/\D/g, '').slice(0, 2);
    ent = ent.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const nuevo = ent + (dec === null ? '' : ',' + dec);
    if (nuevo === v) return;
    el.value = nuevo;
    if (document.activeElement !== el) return;
    let i = 0, c = 0;
    while (i < nuevo.length && c < antes) { if (/[\d,]/.test(nuevo[i])) c++; i++; }
    try { el.setSelectionRange(i, i); } catch (e) { /* tipos sin selección */ }
  }

  // Se registra en captura para que el valor ya esté formateado cuando otros listeners lo lean (totales).
  document.addEventListener('input', function (e) {
    if (e.target && e.target.matches && e.target.matches('[data-money]')) formatearDinero(e.target);
  }, true);

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

  /* ------------------------------------------------------------------ archivos (FTP) */

  /** Configuración del FTP que imprime _pie.php (GasConfig). */
  const FTP = window.GASTOS_FTP || { activo: false, ext: {}, maxMb: 10 };

  /*
   * subirArchivos() de servicios.js usa la ruta fija '../models/ServiceUploadFile.php', pensada para
   * vistas en views/. Este módulo vive en views/gastos/, así que se corrige la URL solo para esa
   * petición, sin modificar servicios.js.
   */
  if (window.jQuery && FTP.servicio) {
    $.ajaxPrefilter(function (o) {
      if (o.url === '../models/ServiceUploadFile.php') o.url = FTP.servicio;
    });
  }

  function extension(nombre) {
    return String(nombre || '').split('.').pop().toLowerCase();
  }

  /** Valida tipo y tamaño de un archivo según su grupo (pdf | imagen | documento). Devuelve el error o null. */
  function validarArchivo(file, grupo, etiqueta) {
    const permitidas = (FTP.ext && FTP.ext[grupo]) || ['pdf'];
    if (permitidas.indexOf(extension(file.name)) === -1) {
      return (etiqueta || file.name) + ': tipo de archivo no permitido. Usa ' + permitidas.join(', ').toUpperCase() + '.';
    }
    if (file.size > (FTP.maxMb || 10) * 1048576) return (etiqueta || file.name) + ' supera ' + (FTP.maxMb || 10) + ' MB.';
    return null;
  }

  /**
   * Fotos grandes del celular: se reducen (máx. 2000 px, JPG 82 %) antes de subirlas.
   * Si el navegador no puede, se sube la original.
   */
  function comprimirImagen(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 1.5 * 1048576 || !window.createImageBitmap) return Promise.resolve(file);
    return createImageBitmap(file).then(function (img) {
      const k = Math.min(1, 2000 / Math.max(img.width, img.height));
      const c = document.createElement('canvas');
      c.width = Math.round(img.width * k);
      c.height = Math.round(img.height * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      return new Promise(function (res) {
        c.toBlob(function (b) {
          if (!b || b.size >= file.size) return res(file);
          res(new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.82);
      });
    }).catch(function () { return file; });
  }

  /** Sube un archivo al FTP (carpeta FTP.ruta) con subirArchivos() de servicios.js. Resuelve {ruta, nombre, tamano}. */
  function subirFtp(file) {
    const nombreUnico = 'gas_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
    let promesa;
    try {
      promesa = subirArchivos(file, { validateSize: false, maxSize: 0, validateExt: false, typesFile: {}, ruta: FTP.ruta }, { nuevo_nombre: nombreUnico });
    } catch (e) {
      return Promise.reject({ gastos: true, mensaje: (e && e.message) || 'Archivo no válido: ' + file.name });
    }
    return Promise.resolve(promesa).then(function (r) {
      if (!r || !r.ok || !r.nombre_archivo) {
        throw { gastos: true, mensaje: 'No se pudo subir «' + file.name + '»' + (r && r.mensaje ? ': ' + r.mensaje : '.') };
      }
      return { ruta: r.nombre_archivo, nombre: file.name, tamano: file.size };
    }, function (err) {
      if (err && err.gastos) throw err;
      throw { gastos: true, mensaje: 'No se pudo subir «' + file.name + '» al servidor de archivos. Revisa tu conexión e intenta de nuevo.' };
    });
  }

  /**
   * Prepara los archivos del formulario antes de enviarlo: valida tipo y tamaño (data-tipos del input)
   * y, con el FTP activo, sube cada uno y en el FormData reemplaza el archivo por ftp[campo][ruta|nombre|tamano].
   * `progreso(i, total, nombre)` informa el avance. Resuelve el mismo FormData.
   */
  function prepararArchivos(form, fd, progreso) {
    const inputs = Array.prototype.filter.call(form.querySelectorAll('input[type=file]'), function (el) {
      return !el.disabled && el.name && el.files.length;
    });
    for (let i = 0; i < inputs.length; i++) {
      const err = validarArchivo(inputs[i].files[0], inputs[i].dataset.tipos || 'pdf', inputs[i].dataset.etiqueta);
      if (err) return Promise.reject({ gastos: true, mensaje: err });
    }
    if (!FTP.activo) return Promise.resolve(fd);
    if (typeof subirArchivos !== 'function') {
      return Promise.reject({ gastos: true, mensaje: 'No se encontró subirArchivos() de lib/js/servicios.js: no se pueden subir los archivos.' });
    }
    let cadena = Promise.resolve();
    inputs.forEach(function (el, i) {
      cadena = cadena.then(function () {
        if (progreso) progreso(i + 1, inputs.length, el.files[0].name);
        return comprimirImagen(el.files[0]).then(subirFtp).then(function (r) {
          fd.delete(el.name);
          fd.set('ftp[' + el.name + '][ruta]', r.ruta);
          fd.set('ftp[' + el.name + '][nombre]', r.nombre);
          fd.set('ftp[' + el.name + '][tamano]', String(r.tamano));
        });
      });
    });
    return cadena.then(function () { return fd; });
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
    formatoNum: formatoNum, formatearDinero: formatearDinero, FTP: FTP, validarArchivo: validarArchivo, prepararArchivos: prepararArchivos,
    api: api, enviarForm: enviarForm, urlPdf: urlPdf, opciones: opciones,
    error: error, ok: ok, confirmar: confirmar, conBoton: conBoton, debounce: debounce, opts: opts,
    cargarScript: cargarScript, isoFecha: isoFecha, saldo: saldo,
  };
})();
