/**
 * Formularios de cada ACCION de paso (namespace `Pasos`).
 *
 * Cada entrada de Pasos.FORMS tiene:
 *   html(S, paso, opc)  -> string con los campos del formulario
 *   bind(form, S, opc)  -> (opcional) comportamiento: autocompletar, mostrar/ocultar, totales
 *
 * El servidor valida todo de nuevo (GasMotor); aquí solo se ayuda al usuario.
 */
const Pasos = (function () {
  const C = Gastos.CLS;
  const esc = Gastos.esc;

  /** Campo con etiqueta. `input` ya es HTML. */
  function campo(label, input, extra) {
    return '<div class="' + (extra || '') + '"><label class="' + C.label + '">' + label + '</label>' + input + '</div>';
  }
  function input(name, valor, attrs) {
    return '<input name="' + name + '" value="' + esc(valor || '') + '" class="' + C.input + '" ' + (attrs || '') + '>';
  }
  function dinero(name, valor, attrs) {
    return '<input name="' + name + '" data-money value="' + esc(valor || '') + '" inputmode="decimal" placeholder="0" class="' + C.input + ' text-right tabular-nums" ' + (attrs || '') + '>';
  }
  function pdf(name, requerido) {
    return '<input type="file" name="' + name + '" accept="application/pdf,.pdf" ' + (requerido ? 'required' : '') +
      ' class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100">';
  }
  function radios(name, pares, sel) {
    return '<div class="flex flex-wrap gap-4">' + pares.map(function (p) {
      return '<label class="inline-flex items-center gap-2 text-sm"><input type="radio" name="' + name + '" value="' + p[0] + '" required ' +
        (p[0] === sel ? 'checked' : '') + ' class="h-4 w-4 text-indigo-600"> ' + p[1] + '</label>';
    }).join('') + '</div>';
  }
  function areaComentario(obligatorio, label) {
    return campo(label || 'Comentario', '<textarea name="comentario" rows="3" ' + (obligatorio ? 'required' : '') + ' class="' + C.input + '"></textarea>');
  }
  function selectProceso(S, opc) {
    const pares = opc.procesos.map(function (p) { return [p.ID, p.PROCESO]; });
    return campo('Departamento o proceso',
      '<select name="proceso_id" required class="' + C.input + '">' + Gastos.opts(pares, S.PROCESO_ID, 'Selecciona…') + '</select>');
  }

  /** Bloque de datos del tercero, con búsqueda en T_TERCEROS para autocompletar. */
  function tercero(S) {
    return '<fieldset class="space-y-3 rounded-xl border border-slate-200 p-4">' +
      '<legend id="tituloTercero" class="px-2 text-sm font-semibold text-slate-700">Datos del tercero</legend>' +
      campo('Buscar tercero (NIT o razón social)',
        '<input id="buscarTercero" list="dlTerceros" autocomplete="off" placeholder="Escribe al menos 3 caracteres…" class="' + C.input + '">' +
        '<datalist id="dlTerceros"></datalist>') +
      '<div class="grid gap-3 sm:grid-cols-2">' +
        campo('NIT', input('tercero_nit', S.TERCERO_NIT, 'required maxlength="16"')) +
        campo('Razón social', input('tercero_nombre', S.TERCERO_NOMBRE, 'required maxlength="150"')) +
        campo('Celular', input('tercero_celular', S.TERCERO_CELULAR, 'required inputmode="numeric" maxlength="15"')) +
        campo('Correo', input('tercero_email', S.TERCERO_EMAIL, 'required type="email" maxlength="100"')) +
        campo('Cargo', input('cargo', S.CARGO, 'required maxlength="80"')) +
        campo('Centro de costos', input('centro_costos', S.CENTRO_COSTOS, 'required maxlength="40"')) +
      '</div></fieldset>';
  }

  /** Enlaza el buscador de terceros con los campos del formulario. */
  function bindTercero(form) {
    const buscador = form.querySelector('#buscarTercero');
    if (!buscador) return;
    const lista = form.querySelector('#dlTerceros');
    let mapa = {};
    // Solo los campos de este bloque (el modal de viáticos tiene campos con el mismo nombre).
    const fs = buscador.closest('fieldset');
    const f = function (n) { return fs.querySelector('[name="' + n + '"]'); };
    function rellenar(t) {
      f('tercero_nit').value = t.NIT || '';
      f('tercero_nombre').value = t.NOMBRE || '';
      f('tercero_celular').value = (t.CELULAR || '').replace(/\D/g, '');
      f('tercero_email').value = t.EMAIL || '';
    }
    buscador.addEventListener('input', Gastos.debounce(function () {
      const v = buscador.value.trim();
      if (mapa[v]) { rellenar(mapa[v]); return; }
      if (v.length < 3) return;
      Gastos.api('catalogo', 'terceros', { q: v }).then(function (rows) {
        mapa = {};
        lista.innerHTML = rows.map(function (t) {
          const clave = t.NIT + ' — ' + t.NOMBRE;
          mapa[clave] = t;
          return '<option value="' + esc(clave) + '"></option>';
        }).join('');
      }).catch(function () {});
    }, 300));
  }

  /** Alterna la visibilidad de un bloque según el valor de un radio/select. */
  /**
   * Habilita o deshabilita todos los campos de un contenedor. Lo deshabilitado no se envía ni se
   * valida; el "required" original se recuerda en data-req para restaurarlo.
   */
  function habilitar(cont, on) {
    cont.querySelectorAll('input,select,textarea').forEach(function (i) {
      if (i.dataset.req === undefined) i.dataset.req = i.required ? '1' : '0';
      i.disabled = !on;
      i.required = on && i.dataset.req === '1';
    });
  }

  /** Muestra (y habilita) un bloque solo cuando el campo `nombre` vale `valor`. */
  function mostrarSi(form, nombre, valor, selector) {
    const bloque = form.querySelector(selector);
    const actualizar = function () {
      const el = form.elements[nombre];
      const ver = (el && el.value !== undefined ? el.value : '') === valor;
      bloque.classList.toggle('hidden', !ver);
      habilitar(bloque, ver);
    };
    form.querySelectorAll('[name="' + nombre + '"]').forEach(function (e) { e.addEventListener('change', actualizar); });
    actualizar();
  }

  /* ------------------------------------------------------------------ modales */

  /**
   * Modal a pantalla completa. Si va dentro del <form>, sus campos se envían con el formulario
   * aunque esté cerrado. Botones: [data-abrir="id"] lo abre, [data-cerrar] lo cierra y
   * [data-guardar] valida sus campos y lo cierra (dispara el evento "formato-guardado").
   */
  function modal(id, cuerpo, ro, ancho) {
    return '<div id="' + id + '" data-modal class="fixed inset-0 z-40 hidden overflow-y-auto bg-slate-900/50 p-2 sm:p-6">' +
      '<div class="mx-auto ' + (ancho || 'max-w-6xl') + ' rounded-xl bg-white p-4 shadow-2xl sm:p-6">' + cuerpo +
      '<div class="mt-5 flex justify-end gap-3">' +
        (ro ? '<button type="button" data-cerrar class="' + C.btn + ' ' + C.ghost + '">Cerrar</button>'
            : '<button type="button" data-cerrar class="' + C.btn + ' ' + C.ghost + '">Cerrar sin validar</button>' +
              '<button type="button" data-guardar class="' + C.btn + ' ' + C.primary + '">Guardar formato</button>') +
      '</div></div></div>';
  }

  function abrirModal(m) {
    m.classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
  }

  function cerrarModal(m) {
    m.classList.add('hidden');
    if (!document.querySelector('[data-modal]:not(.hidden)')) document.body.classList.remove('overflow-hidden');
  }

  // Delegación global (una sola vez) para abrir, cerrar y guardar modales.
  document.addEventListener('click', function (e) {
    const ab = e.target.closest('[data-abrir]');
    if (ab) { const m = document.getElementById(ab.dataset.abrir); if (m) abrirModal(m); return; }
    const m = e.target.closest('[data-modal]');
    if (!m) return;
    if (e.target.closest('[data-cerrar]') || e.target === m) { cerrarModal(m); return; }
    if (e.target.closest('[data-guardar]')) {
      // El primer campo inválido del formato se señala sin cerrar el modal.
      const inv = Array.prototype.filter.call(m.querySelectorAll('input,select,textarea'), function (i) { return !i.disabled && !i.checkValidity(); })[0];
      if (inv) { inv.reportValidity(); return; }
      cerrarModal(m);
      m.dispatchEvent(new CustomEvent('formato-guardado', { bubbles: true }));
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    const abiertos = document.querySelectorAll('[data-modal]:not(.hidden)');
    if (abiertos.length) cerrarModal(abiertos[abiertos.length - 1]);
  });

  /* --------------------------------------------- piezas visuales de los formatos */

  const BARRA = 'bg-[#12808a] px-2 py-1 text-center text-xs font-bold uppercase tracking-wide text-white';
  const CELDA = 'border border-slate-300 px-2 py-1 align-middle';
  const CAMPO_F = 'w-full rounded border border-slate-300 bg-white px-2 py-1 text-sm focus:border-[#12808a] focus:outline-none focus:ring-1 focus:ring-[#12808a]';

  /** Encabezado como el de los formatos impresos: logo, título y la caja de código/versión. */
  function encabezado(titulo, codigo, version, fecha) {
    return '<table class="mb-3 w-full border-collapse text-sm"><tr>' +
      '<td class="' + CELDA + ' w-40 text-center text-xl font-black tracking-tight text-[#12808a]">roma<span class="text-xs font-semibold"> s.a</span></td>' +
      '<td class="' + CELDA + ' text-center text-base font-semibold uppercase">' + titulo + '</td>' +
      '<td class="' + CELDA + ' w-44 p-0 text-xs"><div class="border-b border-slate-300 px-2 py-0.5">Código: ' + codigo + '</div>' +
        '<div class="border-b border-slate-300 px-2 py-0.5">Versión: ' + version + '</div>' +
        '<div class="border-b border-slate-300 px-2 py-0.5">Página 1 de 1</div><div class="px-2 py-0.5">Fecha: ' + fecha + '</div></td>' +
      '</tr></table>';
  }

  /** Celda "etiqueta: valor" para los formatos en solo lectura. */
  function dato(etiqueta, valor) {
    return '<td class="' + CELDA + '"><span class="text-xs font-semibold uppercase text-slate-500">' + etiqueta + ':</span> ' + esc(valor || '—') + '</td>';
  }

  function preliminarDatos(S, opc) {
    const fondos = Object.keys(opc.fondos || {}).map(function (k) { return [k, opc.fondos[k]]; });
    return '<div class="grid gap-4 sm:grid-cols-2">' +
      campo('Número de preliminar', input('num_preliminar', S.NUM_PRELIMINAR, 'required maxlength="30"')) +
      campo('Fecha de la factura', input('fecha_factura', S.FECHA_FACTURA, 'type="date" required')) +
      campo('Valor de la factura / preliminar', dinero('valor_total', S.VALOR_TOTAL ? Number(S.VALOR_TOTAL) : '', 'required')) +
      (S.PROCESO_ID ? '' : selectProceso(S, opc)) +
      '</div>' +
      (S.DESCRIPCION ? '' : campo('Descripción', '<textarea name="descripcion" rows="2" maxlength="500" class="' + C.input + '"></textarea>')) +
      // ¿De dónde sale la plata?
      '<div class="grid items-end gap-4 sm:grid-cols-2">' +
        campo('¿El pago sale de un fondo?', radios('pago_fondo', [['SI', 'Sí'], ['NO', 'No']], S.PAGO_FONDO === null || S.PAGO_FONDO === undefined ? '' : (Number(S.PAGO_FONDO) ? 'SI' : 'NO'))) +
        '<div data-fondo class="hidden">' + campo('Fondo', '<select name="fondo" class="' + C.input + '">' + Gastos.opts(fondos, S.FONDO || '', 'Selecciona el fondo…') + '</select>') + '</div>' +
      '</div>';
  }

  /** Muestra el selector de fondo solo si el pago sale de un fondo. */
  function bindFondo(form) {
    const blq = form.querySelector('[data-fondo]');
    if (!blq) return;
    const act = function () { blq.classList.toggle('hidden', !(form.elements.pago_fondo && form.elements.pago_fondo.value === 'SI')); };
    form.querySelectorAll('[name="pago_fondo"]').forEach(function (r) { r.addEventListener('change', act); });
    act();
  }

  /** Validación del fondo (el select no es "required" para no chocar con bloques ocultos). */
  function validarFondo(form) {
    const pf = form.elements.pago_fondo;
    if (pf && pf.length && !pf[0].disabled && pf.value === 'SI' && !form.elements.fondo.value) return 'Elige de qué fondo sale el pago.';
  }

  /**
   * Factura en PDF (obligatoria), soporte de pago (obligatorio si la cotización lo exigió) y preliminar en PDF (opcional).
   * Con `soloFactura` solo se pide la factura (decisión «ya tengo el preliminar»).
   */
  function preliminarArchivos(S, soloFactura) {
    const exige = !!Number(S.REQUIERE_SOPORTE_PAGO);
    if (soloFactura) return campo('Factura (PDF) — obligatoria', pdf('factura_pdf', true));
    return '<div class="grid gap-4 sm:grid-cols-2">' +
      campo('Factura (PDF) — obligatoria', pdf('factura_pdf', true)) +
      campo('Soporte de pago (PDF)' + (exige ? ' — obligatorio' : ' — opcional'), pdf('soporte_pago', exige)) +
      campo('Preliminar en PDF (opcional)', pdf('preliminar_pdf', false)) +
      '</div>';
  }

  /* ------------------------------------------------------------------ formularios */

  /** ¿Se cumple la condición de un paso dado lo que el usuario eligió? (misma regla que GasCatalogo::cumpleCondicion). */
  function cumple(cond, hayAnticipo) {
    switch (cond) {
      case 'ANTICIPO_SI': case 'ANTICIPO_COTIZACION': return hayAnticipo;
      case 'ANTICIPO_NO': return !hayAnticipo;
      case 'ANTICIPO_FACTURA': case 'ANTICIPO_VIATICOS': return false; // en cotización el anticipo es "por cotización"
      default: return true;
    }
  }

  /**
   * Predice a qué paso se llega después del actual y cuáles se omiten, según haya o no anticipo.
   * Con `registraPreliminar` (ya tengo el preliminar) el paso «Montar preliminar» del solicitante
   * queda hecho al enviar, así que tampoco se cuenta como siguiente. Sirve para advertir antes de enviar.
   */
  function siguiente(S, hayAnticipo, registraPreliminar) {
    const actual = S.pasoActual ? Number(S.pasoActual.ORDEN) : 0;
    const omitidos = [];
    const hechos = [];
    let sig = null;
    S.pasos
      .filter(function (p) { return Number(p.ORDEN) > actual && p.ESTADO === 'PENDIENTE'; })
      .sort(function (a, b) { return Number(a.ORDEN) - Number(b.ORDEN); })
      .some(function (p) {
        if (registraPreliminar && p.ACCION === 'MONTAR_PRELIMINAR' && p.RESPONSABLE_TIPO === 'SOLICITANTE' && !hechos.length) {
          hechos.push(p);
          return false;
        }
        if (cumple(p.CONDICION, hayAnticipo)) { sig = p; return true; }
        omitidos.push(p);
        return false;
      });
    return { siguiente: sig, omitidos: omitidos, hechos: hechos };
  }

  const FORMS = {};

  FORMS.SUBIR_COTIZACIONES = {
    html: function (S, paso, opc) {
      let h = '<div class="grid gap-4 sm:grid-cols-2">' + selectProceso(S, opc) +
        campo('¿Requiere soporte de pago?', radios('requiere_soporte', [['SI', 'Sí'], ['NO', 'No']])) + '</div>' +
        campo('Descripción del gasto', '<textarea name="descripcion" rows="2" required maxlength="500" class="' + C.input + '"></textarea>') +
        '<p class="text-sm text-slate-500">Sube <b>tres cotizaciones</b> en PDF (máx. 10 MB c/u).</p>';
      for (let n = 1; n <= 3; n++) {
        h += '<div class="grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-3">' +
          '<div class="sm:col-span-3 text-sm font-semibold text-slate-700">Cotización ' + n + '</div>' +
          campo('Proveedor (opcional)', input('cot[' + n + '][proveedor]', '', 'maxlength="150"')) +
          campo('Valor (opcional)', dinero('cot[' + n + '][valor]')) +
          campo('Archivo PDF', pdf('cot_' + n, true)) + '</div>';
      }
      return h;
    },
  };

  FORMS.AUTORIZAR_COTIZACION = {
    html: function (S) {
      const filas = S.cotizaciones.map(function (c) {
        return '<label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3 hover:bg-slate-50">' +
          '<input type="radio" name="cotizacion_elegida" value="' + c.NUMERO + '" class="h-4 w-4 text-indigo-600">' +
          '<span class="flex-1"><b>Cotización ' + c.NUMERO + '</b> · ' + esc(c.PROVEEDOR || 'Sin proveedor') + ' · ' + Gastos.moneda(c.VALOR) + '</span>' +
          '<a href="' + Gastos.urlPdf(c.ARCHIVO_ID) + '" target="_blank" rel="noopener" class="text-sm font-semibold text-indigo-600 hover:underline">Ver PDF</a></label>';
      }).join('');
      return '<div class="space-y-2" id="grupoCot">' + filas + '</div>' +
        campo('Decisión', radios('decision', [['APROBAR', 'Autorizar la cotización elegida'], ['DEVOLVER', 'Devolver para corrección'], ['RECHAZAR', 'Rechazar']])) +
        areaComentario(true, 'Comentario (obligatorio)');
    },
    bind: function (form) {
      // Al rechazar no hace falta elegir cotización.
      const grupo = form.querySelector('#grupoCot');
      form.querySelectorAll('[name="decision"]').forEach(function (r) {
        r.addEventListener('change', function () { grupo.classList.toggle('opacity-40', form.elements.decision.value !== 'APROBAR'); });
      });
    },
    validar: function (form) {
      if (form.elements.decision.value === 'APROBAR' && !form.elements.cotizacion_elegida.value) return 'Elige una de las tres cotizaciones.';
    },
  };

  FORMS.DECISION_ANTICIPO = {
    html: function (S, paso, opc) {
      const el = S.cotizaciones.filter(function (c) { return Number(c.NUMERO) === Number(S.COTIZACION_ELEGIDA); })[0];
      const valor = el && Number(el.VALOR) > 0 ? ' · ' + Gastos.moneda(el.VALOR) : '';
      return (el ? '<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">Cotización autorizada: <b>' + esc(el.PROVEEDOR || 'Cotización ' + el.NUMERO) + '</b>' + valor + '</div>' : '') +
        campo('¿Necesitas anticipo?', radios('requiere_anticipo', [['SI', 'Sí, necesito anticipo'], ['NO', 'No, ya tengo el preliminar']])) +
        // Sí: solo el valor del anticipo.
        '<div id="blqAnticipo" class="hidden">' + campo('Valor del anticipo (por cotización)', dinero('valor_anticipo', '', 'required')) + '</div>' +
        tercero(S) +
        // No: se registra aquí mismo el preliminar y la factura (equivale al paso «Montar preliminar»).
        '<fieldset id="blqPreliminar" class="hidden space-y-4 rounded-xl border border-indigo-200 bg-indigo-50/40 p-4">' +
          '<legend class="px-2 text-sm font-semibold text-indigo-700">Preliminar y factura</legend>' +
          '<p class="text-sm text-slate-600">Como ya tienes el preliminar, regístralo ahora. La solicitud pasará a la aprobación de Gerencia administrativa y el paso «Montar preliminar» quedará hecho.</p>' +
          preliminarDatos(S, opc) + preliminarArchivos(S, true) +
        '</fieldset>';
    },
    bind: function (form) {
      mostrarSi(form, 'requiere_anticipo', 'SI', '#blqAnticipo');
      mostrarSi(form, 'requiere_anticipo', 'NO', '#blqPreliminar');
      bindFondo(form);
    },
    validar: function (form) { return validarFondo(form); },
    /** Advertencia previa al envío: dice a qué paso se va y cuáles se omiten. */
    confirmar: function (form, S) {
      const hay = form.elements.requiere_anticipo.value === 'SI';
      const r = siguiente(S, hay, !hay);
      const destino = r.siguiente
        ? '«' + r.siguiente.NOMBRE + '» (' + r.siguiente.RESPONSABLE + ')'
        : 'el cierre de la solicitud';
      const omit = r.omitidos.length ? '\n\nSe omitirán estos pasos: ' + r.omitidos.map(function (p) { return p.NOMBRE; }).join(', ') + '.' : '';
      if (hay) {
        return { titulo: '¿Solicitar anticipo?', texto: 'La solicitud continuará con ' + destino + '.' + omit, boton: 'Sí, solicitar anticipo' };
      }
      const hecho = r.hechos.length ? 'Con los datos del preliminar y la factura que registraste, el paso «' + r.hechos[0].NOMBRE + '» queda hecho. ' : '';
      return {
        titulo: '¿Seguro que ya tienes el preliminar?',
        texto: 'No se solicitará anticipo. ' + hecho + 'La solicitud pasará a ' + destino + '.' + omit + '\n\nEsta decisión no se puede deshacer.',
        boton: 'Sí, enviar el preliminar',
      };
    },
  };

  /** "AAAA-MM-DD" + n días. */
  function sumarDias(iso, n) {
    const d = new Date(iso + 'T00:00:00');
    d.setDate(d.getDate() + n);
    return Gastos.isoFecha(d);
  }

  /** Lugar del viaje: el destino si existe; si no, el motivo (el F-FR-023 no tiene campo de lugar). */
  function lugarViaje(S) { return S.VIATICOS_DESTINO || S.VIATICOS_MOTIVO || ''; }

  /* ============ Topes de viáticos por nivel (auxilio de alimentación y hospedaje) ============ */

  /** Días (alimentación) y noches (hotel) entre dos fechas AAAA-MM-DD. */
  function diasViaje(ini, fin) {
    if (!ini || !fin || fin < ini) return { dias: 0, noches: 0 };
    const n = Math.round((new Date(fin + 'T00:00:00') - new Date(ini + 'T00:00:00')) / 86400000);
    return { dias: n + 1, noches: n };
  }

  /** Tabla de topes del nivel, con el estilo de la tabla de la empresa. */
  function tablaTopes(t) {
    if (!t) return '';
    const fila = function (k, v) { return '<tr><td class="' + CELDA + '">' + k + '</td><td class="' + CELDA + ' text-right tabular-nums">' + Gastos.moneda(v) + '</td></tr>'; };
    return '<table class="w-full border-collapse text-xs">' +
      '<tr><td class="' + CELDA + ' bg-slate-100 text-center font-bold" colspan="2">AUXILIO DE ALIMENTACIÓN Y HOSPEDAJE ' + t.anio + ' · ' + esc(t.nivelNombre).toUpperCase() + '</td></tr>' +
      '<tr><td class="' + BARRA + '">Concepto</td><td class="' + BARRA + '">Gasto</td></tr>' +
      fila('Desayuno', t.DESAYUNO) + fila('Almuerzo', t.ALMUERZO) + fila('Cena', t.CENA) +
      '<tr><td class="' + CELDA + ' font-bold">Total por día</td><td class="' + CELDA + ' text-right font-bold tabular-nums">' + Gastos.moneda(t.DIA) + '</td></tr>' +
      fila('Tarifa única de hotel', t.HOTEL) + fila('Tarifa única de hotel con desayuno', t.HOTEL_DESAYUNO) +
      '<tr><td class="' + CELDA + ' text-[11px] text-slate-600" colspan="2"><b>Nota:</b> los hoteles se definen en coordinación con quien viaja; la empresa los gestiona y paga directamente para negociar tarifas corporativas.</td></tr>' +
      '</table>';
  }

  /**
   * Avisos de la solicitud de viáticos contra los topes del nivel (no bloquean: Gerencia decide).
   * pres = { ALIMENTACION, HOSPEDAJE } valores presupuestados.
   */
  function avisosF023(t, ini, fin, pres) {
    if (!t) return [];
    const d = diasViaje(ini, fin);
    const av = [];
    if (!d.dias) return av;
    const maxAlim = d.dias * t.DIA;
    const maxHotel = d.noches * t.HOTEL_DESAYUNO;
    if ((pres.ALIMENTACION || 0) > maxAlim) {
      av.push('Alimentación ' + Gastos.moneda(pres.ALIMENTACION) + ' supera el tope de ' + Gastos.moneda(maxAlim) + ' (' + d.dias + ' día(s) × ' + Gastos.moneda(t.DIA) + ').');
    }
    if ((pres.HOSPEDAJE || 0) > 0) {
      av.push('Hospedaje: según la política, la empresa gestiona y paga directamente los hoteles.' +
        ((pres.HOSPEDAJE || 0) > maxHotel ? ' Además supera la tarifa única: ' + Gastos.moneda(maxHotel) + ' (' + d.noches + ' noche(s) × ' + Gastos.moneda(t.HOTEL_DESAYUNO) + ').' : ''));
    }
    return av;
  }

  /** Avisos de la legalización: alimentación por día y hotel por noche sobre los topes. filas: [{fecha, v:{TIPO: valor}}]. */
  function avisosF024(t, filas) {
    if (!t) return [];
    const alimDia = {};
    const av = [];
    filas.forEach(function (f) {
      if (f.fecha && f.v.ALIMENT) alimDia[f.fecha] = (alimDia[f.fecha] || 0) + f.v.ALIMENT;
      if (f.v.HOTEL && f.v.HOTEL > t.HOTEL_DESAYUNO) {
        av.push('Hotel ' + Gastos.moneda(f.v.HOTEL) + (f.fecha ? ' el ' + Gastos.fecha(f.fecha) : '') + ' supera la tarifa única de ' + Gastos.moneda(t.HOTEL_DESAYUNO) + ' por noche (si es de varias noches, ignora este aviso).');
      }
    });
    Object.keys(alimDia).sort().forEach(function (dia) {
      if (alimDia[dia] > t.DIA) av.push('Alimentación del ' + Gastos.fecha(dia) + ': ' + Gastos.moneda(alimDia[dia]) + ' supera el tope diario de ' + Gastos.moneda(t.DIA) + '.');
    });
    return av;
  }

  function cajaAvisos(av, nivel) {
    if (!av.length) return '<div class="rounded-lg border border-emerald-300 bg-emerald-50 p-2 text-xs text-emerald-800">✓ Dentro de los topes' + (nivel ? ' de ' + esc(nivel) : '') + '.</div>';
    return '<div class="rounded-lg border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900"><b>⚠ Revisar contra los topes' + (nivel ? ' de ' + esc(nivel) : '') + ':</b><ul class="ml-4 list-disc">' +
      av.map(function (a) { return '<li>' + esc(a) + '</li>'; }).join('') + '</ul></div>';
  }

  /* ====================== F-FR-023 · Solicitud de viáticos ====================== */

  /** Cuerpo del formato F-FR-023. ro = solo lectura (vista de Gerencia / administrador). */
  function f023(S, opc, ro) {
    const hoy = new Date();
    // Topes: en solo lectura los del solicitante (S.topes); al diligenciar, los del usuario.
    const topes = ro ? S.topes : (S.topes || opc.topes);
    const fecha = S.FECHA_CREACION ? S.FECHA_CREACION.substr(0, 10) : Gastos.isoFecha(hoy);
    const f = fecha.split('-');
    const yo = opc.yo || {};
    // En edición: input; en solo lectura: el valor guardado.
    const val = function (name, guardado, attrs) {
      return ro ? esc(guardado || '—') : '<input name="' + name + '" value="' + esc(guardado || '') + '" class="' + CAMPO_F + '" ' + (attrs || '') + '>';
    };
    let total = 0;
    const guardados = {};
    (S.viaticos || []).forEach(function (v) { guardados[v.CONCEPTO] = v; total += Number(v.VALOR_TOTAL); });
    const filas = Object.keys(opc.conceptos).map(function (k) {
      const g = guardados[k];
      const otros = k === 'OTROS'
        ? (ro ? (g && g.DESCRIPCION ? ' (' + esc(g.DESCRIPCION) + ')' : '') : ' <input name="presupuesto_otros" maxlength="200" placeholder="¿Cuál?" value="' + esc(g && g.DESCRIPCION ? g.DESCRIPCION : '') + '" class="ml-1 inline-block w-40 rounded border border-slate-300 px-2 py-0.5 text-xs">')
        : '';
      return '<tr><td class="' + CELDA + ' w-1/2">' + esc(opc.conceptos[k]) + (k === 'OTROS' ? ' (¿cuál?)' : '') + otros + '</td>' +
        '<td class="' + CELDA + ' text-right tabular-nums">' + (ro ? (g ? Gastos.moneda(g.VALOR_TOTAL) : '') :
          '<input name="presupuesto[' + k + ']" data-money data-pres inputmode="decimal" placeholder="$ 0" value="' + (g ? Number(g.VALOR_TOTAL).toLocaleString('es-CO') : '') + '" class="' + CAMPO_F + ' text-right">') + '</td></tr>';
    }).join('');
    return encabezado('Formato solicitud de viáticos', 'F-FR-023', '1', '5/09/2020') +
      '<table class="w-full border-collapse text-sm">' +
        '<tr><td class="' + BARRA + '" colspan="2">Fecha</td><td class="' + BARRA + '" colspan="2">Dependencia</td></tr>' +
        '<tr><td class="' + CELDA + ' text-center" colspan="2">' + f[2] + ' / ' + f[1] + ' / ' + f[0] + '</td>' +
          '<td class="' + CELDA + ' text-center font-semibold" colspan="2" data-dependencia>' + esc(S.PROCESO || '—') + '</td></tr>' +
        '<tr><td class="' + BARRA + '" colspan="4">Datos de quien solicita</td></tr>' +
        '<tr><td class="' + CELDA + '" colspan="2"><div class="text-xs font-semibold uppercase text-slate-500">Nombres y apellidos</div>' + val('tercero_nombre', S.TERCERO_NOMBRE || yo.NOMBRE, 'required maxlength="150"') + '</td>' +
          '<td class="' + CELDA + '" colspan="2"><div class="text-xs font-semibold uppercase text-slate-500">Documento identidad</div>' + val('tercero_nit', S.TERCERO_NIT || yo.IDENTIFICACION, 'required maxlength="16"') + '</td></tr>' +
        '<tr><td class="' + CELDA + '" colspan="2"><div class="text-xs font-semibold uppercase text-slate-500">Cargo</div>' + val('cargo', S.CARGO, 'required maxlength="80"') + '</td>' +
          '<td class="' + CELDA + '"><div class="text-xs font-semibold uppercase text-slate-500">Tel. fijo</div>' + val('viaticos_tel_fijo', S.VIATICOS_TEL_FIJO || yo.EXT, 'maxlength="20" inputmode="numeric"') + '</td>' +
          '<td class="' + CELDA + '"><div class="text-xs font-semibold uppercase text-slate-500">Celular</div>' + val('tercero_celular', S.TERCERO_CELULAR || yo.CELULAR, 'required maxlength="15" inputmode="numeric"') + '</td></tr>' +
        '<tr><td class="' + CELDA + '" colspan="2"><div class="text-xs font-semibold uppercase text-slate-500">Centro de costos</div>' + val('centro_costos', S.CENTRO_COSTOS, 'required maxlength="40"') + '</td>' +
          '<td class="' + CELDA + '" colspan="2"><div class="text-xs font-semibold uppercase text-slate-500">Correo electrónico</div>' + val('tercero_email', S.TERCERO_EMAIL || yo.EMAIL, 'required type="email" maxlength="100"') + '</td></tr>' +
        '<tr><td class="' + BARRA + '" colspan="4">Motivo de la solicitud</td></tr>' +
        '<tr><td class="' + CELDA + ' text-center" colspan="4">' + (ro ? esc(S.VIATICOS_MOTIVO || '—') :
          '<textarea name="viaticos_motivo" rows="2" required maxlength="300" class="' + CAMPO_F + ' text-center">' + esc(S.VIATICOS_MOTIVO || '') + '</textarea>') + '</td></tr>' +
        '<tr><td class="' + CELDA + ' font-semibold">Fecha salida</td><td class="' + CELDA + '">' +
          (ro ? Gastos.fecha(S.VIATICOS_FECHA_INICIO) : '<input type="date" name="viaticos_fecha_inicio" required min="' + Gastos.isoFecha(hoy) + '" value="' + esc(S.VIATICOS_FECHA_INICIO || '') + '" class="' + CAMPO_F + '">') + '</td>' +
          '<td class="' + CELDA + ' font-semibold">Fecha regreso</td><td class="' + CELDA + '">' +
          (ro ? Gastos.fecha(S.VIATICOS_FECHA_FIN) : '<input type="date" name="viaticos_fecha_fin" required min="' + Gastos.isoFecha(hoy) + '" value="' + esc(S.VIATICOS_FECHA_FIN || '') + '" class="' + CAMPO_F + '">') + '</td></tr>' +
        '<tr><td class="' + BARRA + '" colspan="4">Valor presupuestado</td></tr>' +
      '</table>' +
      '<table class="w-full border-collapse text-sm">' + filas +
        '<tr><td class="' + CELDA + ' text-right font-bold">TOTAL SOLICITADO</td><td class="' + CELDA + ' text-right font-bold tabular-nums" data-total-f023>' + Gastos.moneda(total) + '</td></tr>' +
      '</table>' +      (topes ? '<div class="mt-3 grid gap-3 md:grid-cols-2"><div>' + tablaTopes(topes) + '</div><div data-avisos-topes>' +
        (ro ? cajaAvisos(avisosF023(topes, S.VIATICOS_FECHA_INICIO, S.VIATICOS_FECHA_FIN, { ALIMENTACION: guardados.ALIMENTACION ? Number(guardados.ALIMENTACION.VALOR_TOTAL) : 0, HOSPEDAJE: guardados.HOSPEDAJE ? Number(guardados.HOSPEDAJE.VALOR_TOTAL) : 0 }), topes.nivelNombre) : '') +
        '</div></div>' : '<p class="mt-2 text-xs text-slate-500">Tu rol no tiene nivel en T_ROLES_GASTOS_INFO: no se muestran topes de viáticos.</p>') +
      '<div class="mt-3 flex gap-3 border border-slate-300 bg-[#a9d18e]/40 p-3 text-xs font-semibold text-slate-800">' +
        (ro ? (Number(S.VIATICOS_ACEPTA_DESCUENTO) ? '<span>✓</span>' : '<span>✗</span>') : '<input type="checkbox" name="acepta_descuento" value="SI" required class="mt-0.5 h-4 w-4 shrink-0">') +
        '<span>En el eventual caso que llegase a finalizar mi contrato de trabajo y no haya realizado la debida legalización de viáticos en el plazo convenido de ' +
        Number(opc.diasLegalizacion || 30) + ' días posteriores de recibido el anticipo, de manera expresa, libre y voluntaria, autorizo al empleador para que retenga, deduzca y cobre de mi ' +
        'liquidación final de prestaciones sociales, salarios, comisiones e indemnizaciones los saldos que esté adeudando por este concepto; esta autorización la hago de conformidad a lo citado en los artículos 150 y 151 del Código Sustantivo del Trabajo.</span></div>' +
      '<p class="mt-2 text-xs text-slate-500">Las firmas (colaborador, jefe inmediato y gerente) se registran como aprobaciones en el flujo de autorización.</p>';
  }

  function totalF023(cont) {
    let t = 0;
    cont.querySelectorAll('[data-pres]').forEach(function (i) { t += Gastos.num(i.value); });
    return t;
  }

  /* ====================== F-FR-024 · Legalización de viáticos ====================== */

  /**
   * Agrupa las líneas guardadas (una por columna con valor) en las filas del formato:
   * filas consecutivas con la misma fecha, centro de costo, doc y detalle son una sola fila.
   */
  function filasLegalizacion(lineas) {
    const filas = [];
    (lineas || []).forEach(function (l) {
      const clave = [l.FECHA, l.CENTRO_COSTO, l.NUM_DOCUMENTO || '', l.DETALLE].join('|');
      const ult = filas[filas.length - 1];
      if (ult && ult.clave === clave && !ult.v[l.TIPO_GASTO]) { ult.v[l.TIPO_GASTO] = Number(l.VALOR); return; }
      const v = {};
      v[l.TIPO_GASTO] = Number(l.VALOR);
      filas.push({ clave: clave, fecha: l.FECHA, cc: l.CENTRO_COSTO, doc: l.NUM_DOCUMENTO, det: l.DETALLE, v: v });
    });
    return filas;
  }

  /** Cuerpo del formato F-FR-024. ro = solo lectura. */
  function f024(S, opc, ro) {
    const tipos = Object.keys(opc.tiposGasto);
    const th = function (t, cls) { return '<th class="' + CELDA + ' bg-slate-50 text-xs font-bold ' + (cls || '') + '">' + t + '</th>'; };
    let cuerpo = '';
    if (ro) {
      cuerpo = filasLegalizacion(S.legalizacion).map(function (f) {
        let tot = 0;
        return '<tr><td class="' + CELDA + ' whitespace-nowrap text-xs">' + Gastos.fecha(f.fecha) + '</td><td class="' + CELDA + ' text-xs">' + esc(f.cc) + '</td>' +
          '<td class="' + CELDA + ' text-xs">' + esc(f.doc || '') + '</td><td class="' + CELDA + ' text-xs">' + esc(f.det) + '</td>' +
          tipos.map(function (t) { tot += f.v[t] || 0; return '<td class="' + CELDA + ' whitespace-nowrap text-right text-xs tabular-nums">' + (f.v[t] ? Gastos.moneda(f.v[t]) : '') + '</td>'; }).join('') +
          '<td class="' + CELDA + ' whitespace-nowrap text-right text-xs font-semibold tabular-nums">' + Gastos.moneda(tot) + '</td></tr>';
      }).join('');
    }
    const fila = function (k, v, id, fuerte) {
      return '<tr><td class="' + CELDA + ' text-right text-xs font-bold uppercase ' + (fuerte ? 'bg-slate-50' : '') + '">' + k + '</td>' +
        '<td class="' + CELDA + ' w-36 text-right text-sm tabular-nums ' + (fuerte ? 'font-bold' : '') + '"' + (id ? ' ' + id : '') + '>' + v + '</td></tr>';
    };
    const info = Gastos.saldo(S.SALDO_LEGALIZACION);
    return encabezado('Formato legalización de viáticos', 'F-FR-024', '1', '26/12/2018') +
      '<table class="mb-2 w-full border-collapse text-sm"><tr>' + dato('Nombre', S.TERCERO_NOMBRE) + dato('C.C.', S.TERCERO_NIT) + '</tr>' +
        '<tr>' + dato('Lugar', lugarViaje(S)) + dato('Fecha', S.VIATICOS_FECHA_INICIO ? 'del ' + Gastos.fecha(S.VIATICOS_FECHA_INICIO) + ' al ' + Gastos.fecha(S.VIATICOS_FECHA_FIN) : '') + '</tr></table>' +
      '<div class="overflow-x-auto"><table class="w-full min-w-[76rem] border-collapse"><thead><tr>' +
        th('Fecha', 'w-32') + th('Centro de costo', 'w-20') + th('Doc', 'w-24') + th('Ciudad y detalles') +
        tipos.map(function (t) { return th(esc(opc.tiposGasto[t]), 'w-24 text-right'); }).join('') + th('Tot diario', 'w-24 text-right') + (ro ? '' : th('', 'w-8')) +
      '</tr></thead><tbody' + (ro ? '' : ' id="lineasLegal"') + '>' + cuerpo + '</tbody></table></div>' +
      (ro ? '' : '<button type="button" id="addLegal" class="mt-2 ' + C.btn + ' ' + C.ghost + '">+ Agregar fila</button>') +
      ((S.topes || opc.topes) ? '<div class="mt-3" ' + (ro ? '' : 'id="avisosLegal"') + '>' +
        (ro ? cajaAvisos(avisosF024(S.topes, filasLegalizacion(S.legalizacion)), S.topes && S.topes.nivelNombre) : '') + '</div>' : '') +
      '<table class="ml-auto mt-3 border-collapse">' +
        fila('Retefuente descontada', Gastos.moneda(S.RETEFUENTE_LEGALIZACION || 0), ro ? '' : 'id="reteLegal"') +
        fila('Total cuenta de gastos', Gastos.moneda(S.VALOR_LEGALIZADO || 0), ro ? '' : 'id="totalLegal"', true) +
        fila('Suma recibida', Gastos.moneda(S.VALOR_ANTICIPO)) +
        fila('Menos valor de esta cuenta de gastos', Gastos.moneda(S.VALOR_LEGALIZADO || 0), ro ? '' : 'id="menosLegal"') +
        fila('Saldo A/F (+ DF Roma) (− empleado)', ro ? (Number(S.SALDO_LEGALIZACION) > 0 ? '+ ' : Number(S.SALDO_LEGALIZACION) < 0 ? '− ' : '') + Gastos.moneda(Math.abs(Number(S.SALDO_LEGALIZACION || 0))) : '', ro ? '' : 'id="saldoNum"', true) +
      '</table>' +
      '<div ' + (ro ? '' : 'id="saldoLegal" ') + 'class="mt-3 rounded-lg border p-3 text-sm ' + (ro ? info.cls : '') + '">' + (ro ? '<b>' + esc(info.titulo) + '</b><br>' + esc(info.detalle) : '') + '</div>' +
      '<p class="mt-2 border border-slate-300 px-2 py-1 text-xs">ORIGINAL: CONTABILIDAD. VALORES EN HOTEL Y ALIMENTACIÓN SUPERIORES A ' + Gastos.moneda(opc.retefuente.tope) +
        ' DESCONTAR EL ' + (opc.retefuente.tasa * 100).toLocaleString('es-CO') + ' % RTE.</p>' +
      '<p class="mt-1 text-xs text-slate-500">Las firmas (responsable, jefe inmediato y gerente administrativo) se registran como aprobaciones en el flujo.</p>';
  }

  /** Filas dinámicas de la legalización y cálculo en vivo (misma regla que el servidor). */
  function bindLegalizacion(form, S, opc, alGuardar) {
    const tbody = form.querySelector('#lineasLegal');
    const tipos = Object.keys(opc.tiposGasto);
    const rt = opc.retefuente;
    // La fecha de cada gasto es la fecha en que se consumió: no puede ser posterior a hoy.
    const max = Gastos.isoFecha(new Date());
    const min = '';
    let n = 0;
    const resumen = { total: 0, saldo: 0, filas: 0 };
    function recalcular() {
      let suma = 0, rete = 0, filas = 0;
      tbody.querySelectorAll('tr').forEach(function (tr) {
        let tot = 0;
        tr.querySelectorAll('[data-tipo]').forEach(function (i) {
          const v = Math.round(Gastos.num(i.value) * 100) / 100;
          if (v <= 0) return;
          tot += v;
          if (rt.tipos.indexOf(i.dataset.tipo) !== -1 && v > rt.tope) rete += Math.round(v * rt.tasa);
        });
        tr.querySelector('[data-tot]').textContent = tot ? Gastos.moneda(tot) : '';
        suma += tot;
        if (tot > 0) filas++;
      });
      const total = suma - rete;
      const saldo = (Number(S.VALOR_ANTICIPO) || 0) - total;
      const info = Gastos.saldo(saldo);
      form.querySelector('#reteLegal').textContent = Gastos.moneda(rete);
      form.querySelector('#totalLegal').textContent = Gastos.moneda(total);
      form.querySelector('#menosLegal').textContent = Gastos.moneda(total);
      form.querySelector('#saldoNum').textContent = (saldo > 0 ? '+ ' : saldo < 0 ? '− ' : '') + Gastos.moneda(Math.abs(saldo));
      const box = form.querySelector('#saldoLegal');
      box.className = 'mt-3 rounded-lg border p-3 text-sm ' + info.cls;
      box.innerHTML = '<b>' + esc(info.titulo) + '</b><br>' + esc(info.detalle);
      resumen.total = total; resumen.saldo = saldo; resumen.filas = filas;
      // Avisos contra los topes del nivel (alimentación por día, hotel por noche).
      const topes = S.topes || opc.topes;
      const caja = form.querySelector('#avisosLegal');
      if (caja && topes) {
        const fs = [];
        tbody.querySelectorAll('tr').forEach(function (tr) {
          const v = {};
          tr.querySelectorAll('[data-tipo]').forEach(function (i) { const x = Gastos.num(i.value); if (x > 0) v[i.dataset.tipo] = x; });
          fs.push({ fecha: tr.querySelector('input[type=date]').value, v: v });
        });
        caja.innerHTML = cajaAvisos(avisosF024(topes, fs), topes.nivelNombre);
      }
    }
    /** Agrega una fila; `f` (opcional) la prellena con una fila guardada (devolución para corrección). */
    function agregar(f) {
      f = f && f.v ? f : { v: {} };
      const i = n++;
      const tr = document.createElement('tr');
      tr.innerHTML =
        '<td class="' + CELDA + '"><input type="date" name="legal[' + i + '][fecha]" required' + (min ? ' min="' + min + '"' : '') + ' max="' + max + '" value="' + esc(f.fecha || '') + '" class="' + CAMPO_F + ' min-w-[8.5rem]"></td>' +
        '<td class="' + CELDA + '"><input name="legal[' + i + '][centro_costo]" required maxlength="40" value="' + esc(f.cc || S.CENTRO_COSTOS || '') + '" class="' + CAMPO_F + ' min-w-[4.5rem]"></td>' +
        '<td class="' + CELDA + '"><input name="legal[' + i + '][documento]" maxlength="30" value="' + esc(f.doc || '') + '" class="' + CAMPO_F + ' min-w-[5.5rem]"></td>' +
        '<td class="' + CELDA + '"><input name="legal[' + i + '][detalle]" required maxlength="200" value="' + esc(f.det || '') + '" class="' + CAMPO_F + ' min-w-[11rem]"></td>' +
        tipos.map(function (t) {
          return '<td class="' + CELDA + '"><input name="legal[' + i + '][v][' + t + ']" data-money data-tipo="' + t + '" inputmode="decimal" value="' + (f.v[t] ? f.v[t].toLocaleString('es-CO') : '') + '" class="' + CAMPO_F + ' min-w-[5.5rem] text-right"></td>';
        }).join('') +
        '<td class="' + CELDA + ' whitespace-nowrap text-right text-xs font-semibold tabular-nums" data-tot></td>' +
        '<td class="' + CELDA + ' text-center"><button type="button" class="text-rose-600 hover:text-rose-800" title="Quitar fila">✕</button></td>';
      tr.querySelector('button').addEventListener('click', function () {
        if (tbody.children.length > 1) { tr.remove(); recalcular(); }
      });
      tr.addEventListener('input', recalcular);
      tbody.appendChild(tr);
    }
    form.querySelector('#addLegal').addEventListener('click', function () { agregar(); });
    const previas = filasLegalizacion(S.legalizacion);
    if (previas.length) previas.forEach(agregar); else agregar();
    recalcular();
    if (alGuardar) form.addEventListener('formato-guardado', function () { alGuardar(resumen); });
    return resumen;
  }

  /** Tarjeta en el formulario del paso: estado del formato + botón para abrir el modal. */
  function tarjetaFormato(id, titulo, ayuda) {
    return '<div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50/50 p-4">' +
      '<div><div class="text-sm font-semibold text-slate-800">' + titulo + '</div>' +
      '<div class="text-sm text-slate-600" data-estado-formato="' + id + '">' + ayuda + '</div></div>' +
      '<button type="button" data-abrir="' + id + '" class="' + C.btn + ' ' + C.primary + '">Diligenciar formato</button></div>';
  }

  function marcarFormato(form, id, html) {
    const e = form.querySelector('[data-estado-formato="' + id + '"]');
    if (e) e.innerHTML = html;
    const b = form.querySelector('[data-abrir="' + id + '"]');
    if (b) b.textContent = 'Editar formato';
  }

  /* ================================ formularios ================================ */

  FORMS.SOLICITAR_ANTICIPO = {
    html: function (S, paso, opc) {
      return '<div class="grid gap-4 sm:grid-cols-2">' + selectProceso(S, opc) +
        campo('Tipo de anticipo', '<select name="tipo_anticipo" required class="' + C.input + '">' +
          Gastos.opts([['FACTURA', 'Por factura'], ['VIATICOS', 'Por viáticos (formato F-FR-023)']], S.TIPO_ANTICIPO || '', 'Selecciona…') + '</select>') + '</div>' +
        '<div id="blqFactura" class="hidden space-y-4">' +
          campo('Descripción del gasto', '<textarea name="descripcion" rows="2" required maxlength="500" class="' + C.input + '">' + esc(S.TIPO_ANTICIPO === 'FACTURA' ? S.DESCRIPCION || '' : '') + '</textarea>') +
          campo('Valor del anticipo', dinero('valor_anticipo', S.TIPO_ANTICIPO === 'FACTURA' && S.VALOR_ANTICIPO ? Number(S.VALOR_ANTICIPO) : '', 'required')) +
          tercero(S) +
        '</div>' +
        '<div id="blqViaticos" class="hidden">' + tarjetaFormato('modalF023', 'Formato de solicitud de viáticos (F-FR-023)',
          (S.viaticos && S.viaticos.length) ? 'Tiene los datos que enviaste antes: ábrelo, corrige y vuelve a guardar.' : 'Sin diligenciar.') + '</div>' +
        modal('modalF023', f023(S, opc, false), false, 'max-w-5xl');
    },
    bind: function (form, S, opc) {
      mostrarSi(form, 'tipo_anticipo', 'FACTURA', '#blqFactura');
      mostrarSi(form, 'tipo_anticipo', 'VIATICOS', '#blqViaticos');
      // Los campos del modal solo cuentan cuando el anticipo es por viáticos (el modal no se oculta con el bloque).
      const m = form.querySelector('#modalF023');
      const tipo = form.elements.tipo_anticipo;
      const act = function () { habilitar(m, tipo.value === 'VIATICOS'); };
      tipo.addEventListener('change', act);
      act();
      // Dependencia del formato = proceso elegido; total en vivo; regreso >= salida.
      form.elements.proceso_id.addEventListener('change', function () {
        m.querySelector('[data-dependencia]').textContent = this.options[this.selectedIndex].text;
      });
      const topes = S.topes || opc.topes;
      const actTopes = function () {
        const caja = m.querySelector('[data-avisos-topes]');
        if (!caja || !topes) return;
        const v = function (k) { const i = m.querySelector('[name="presupuesto[' + k + ']"]'); return i ? Gastos.num(i.value) : 0; };
        caja.innerHTML = cajaAvisos(avisosF023(topes, m.querySelector('[name=viaticos_fecha_inicio]').value, m.querySelector('[name=viaticos_fecha_fin]').value,
          { ALIMENTACION: v('ALIMENTACION'), HOSPEDAJE: v('HOSPEDAJE') }), topes.nivelNombre);
      };
      m.addEventListener('input', function () { m.querySelector('[data-total-f023]').textContent = Gastos.moneda(totalF023(m)); actTopes(); });
      m.addEventListener('change', actTopes);
      actTopes();
      m.querySelector('[name=viaticos_fecha_inicio]').addEventListener('change', function () {
        m.querySelector('[name=viaticos_fecha_fin]').min = this.value;
      });
      form.addEventListener('formato-guardado', function () {
        marcarFormato(form, 'modalF023', '✓ Diligenciado · total solicitado <b>' + Gastos.moneda(totalF023(m)) + '</b> · ' +
          Gastos.fecha(m.querySelector('[name=viaticos_fecha_inicio]').value) + ' a ' + Gastos.fecha(m.querySelector('[name=viaticos_fecha_fin]').value));
      });
    },
    validar: function (form) {
      if (form.elements.tipo_anticipo.value !== 'VIATICOS') return;
      const m = form.querySelector('#modalF023');
      const ini = m.querySelector('[name=viaticos_fecha_inicio]').value, fin = m.querySelector('[name=viaticos_fecha_fin]').value;
      if (ini && fin && fin < ini) return 'F-FR-023: la fecha de regreso no puede ser anterior a la de salida.';
      if (totalF023(m) <= 0) return 'F-FR-023: indica al menos un valor presupuestado.';
      if (Gastos.num(m.querySelector('[name="presupuesto[OTROS]"]').value) > 0 && !m.querySelector('[name=presupuesto_otros]').value.trim()) return 'F-FR-023: indica cuál es el concepto «Otros».';
    },
  };

  FORMS.MONTAR_PRELIMINAR = {
    html: function (S, paso, opc) {
      const aviso = Number(S.REQUIERE_ANTICIPO) && S.VALOR_ANTICIPO ? '<div class="rounded-lg bg-indigo-50 p-3 text-sm text-indigo-800">Anticipo aprobado: <b>' + Gastos.moneda(S.VALOR_ANTICIPO) + '</b></div>' : '';
      // Anticipo por viáticos: en lugar de la factura se diligencia la legalización (F-FR-024).
      if (S.TIPO_ANTICIPO === 'VIATICOS') {
        let plazo = '';
        const dias = Number(opc.diasLegalizacion || 30);
        if (S.VIATICOS_FECHA_FIN) {
          const trans = Math.floor((new Date() - new Date(S.VIATICOS_FECHA_FIN + 'T00:00:00')) / 86400000);
          plazo = trans > dias
            ? '<div class="rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800">⚠ Han pasado <b>' + trans + ' días</b> desde el regreso: el plazo para legalizar es de ' + dias + ' días.</div>'
            : '<div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">Plazo para legalizar: ' + dias + ' días después del viaje' + (trans >= 0 ? ' (van ' + trans + ').' : '.') + '</div>';
        }
        return aviso + plazo +
          tarjetaFormato('modalF024', 'Formato de legalización de viáticos (F-FR-024)',
            (S.legalizacion && S.legalizacion.length) ? 'Tiene los gastos que enviaste antes: ábrelo, corrige y vuelve a guardar.' : 'Sin diligenciar.') +
          campo('Soportes de la legalización (un solo PDF con todas las facturas y, si hay reintegro, su comprobante)', pdf('soportes_legalizacion', true)) +
          modal('modalF024', f024(S, opc, false), false, 'max-w-[96rem]');
      }
      return aviso + preliminarDatos(S, opc) + tercero(S) + preliminarArchivos(S);
    },
    bind: function (form, S, opc) {
      if (S.TIPO_ANTICIPO === 'VIATICOS') {
        bindLegalizacion(form, S, opc, function (r) {
          const info = Gastos.saldo(r.saldo);
          marcarFormato(form, 'modalF024', '✓ Diligenciado · ' + r.filas + ' fila(s) · total cuenta de gastos <b>' + Gastos.moneda(r.total) + '</b> · ' + esc(info.titulo));
        });
      } else {
        bindFondo(form);
      }
    },
    validar: function (form, S) {
      if (S.TIPO_ANTICIPO !== 'VIATICOS') return validarFondo(form);
      // Cada fila debe tener al menos un valor en alguna columna.
      let vacia = false;
      form.querySelectorAll('#lineasLegal tr').forEach(function (tr) {
        let t = 0;
        tr.querySelectorAll('[data-tipo]').forEach(function (i) { t += Gastos.num(i.value); });
        if (t <= 0) vacia = true;
      });
      if (vacia) return 'F-FR-024: cada fila debe tener un valor en alguna columna (Transp, Bus/taxis, Hotel…).';
    },
  };

  /** Formatos en solo lectura (vista de Gerencia administrativa / administradores). */
  function verFormato(cual, S, opc) {
    const id = 'ver' + cual;
    let m = document.getElementById(id);
    if (!m) {
      const div = document.createElement('div');
      div.innerHTML = modal(id, cual === 'F023' ? f023(S, opc, true) : f024(S, opc, true), true, cual === 'F023' ? 'max-w-5xl' : 'max-w-[96rem]');
      m = div.firstChild;
      document.body.appendChild(m);
    }
    abrirModal(m);
  }

  FORMS.APROBAR = {
    html: function (S, paso) {
      let resumen = '';
      if (S.VALOR_LEGALIZADO !== null && S.VALOR_LEGALIZADO !== undefined) {
        const info = Gastos.saldo(S.SALDO_LEGALIZACION);
        resumen = '<div class="rounded-lg border p-3 text-sm ' + info.cls + '">Anticipo ' + Gastos.moneda(S.VALOR_ANTICIPO) + ' · legalizado ' +
          Gastos.moneda(S.VALOR_LEGALIZADO) + '<br><b>' + esc(info.titulo) + '</b></div>';
      }
      return resumen + campo('Decisión', radios('decision', [['APROBAR', 'Aprobar'], ['DEVOLVER', 'Devolver para corrección'], ['RECHAZAR', 'Rechazar']])) +
        '<p class="text-xs text-slate-500">«Devolver para corrección» regresa la solicitud al solicitante con tu comentario para que cambie lo necesario (por ejemplo, un valor) y la vuelva a enviar.</p>' +
        areaComentario(false, 'Comentario (obligatorio si devuelves o rechazas)');
    },
  };

  FORMS.CONTABILIZAR = {
    html: function (S) {
      const dif = (Number(S.VALOR_ANTICIPO) || 0) - (Number(S.VALOR_TOTAL) || 0);
      const info = Number(S.REQUIERE_ANTICIPO) && S.VALOR_ANTICIPO && S.VALOR_TOTAL
        ? '<div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">Consolidación: anticipo ' + Gastos.moneda(S.VALOR_ANTICIPO) +
          ' vs preliminar ' + Gastos.moneda(S.VALOR_TOTAL) + ' → diferencia <b>' + Gastos.moneda(Math.abs(dif)) + '</b> ' +
          (dif > 0 ? '(a favor de la empresa)' : dif < 0 ? '(a favor del tercero)' : '(cuadrado)') + '</div>' : '';
      return info + '<div class="grid gap-4 sm:grid-cols-2">' +
        campo('Número de contabilización', input('num_contabilizacion', '', 'required maxlength="30"')) +
        campo('Causación de compensación (si aplica)', input('num_compensacion', '', 'maxlength="30"')) + '</div>' +
        areaComentario(false, 'Comentario (opcional)');
    },
  };

  FORMS.PAGAR = {
    html: function (S, paso, opc) {
      const hoy = new Date().toISOString().slice(0, 10);
      const fondo = Number(S.PAGO_FONDO)
        ? '<div class="rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-900">El pago sale del <b>' + esc((opc.fondos && opc.fondos[S.FONDO]) || S.FONDO) + '</b>.</div>' : '';
      return fondo + '<div class="grid gap-4 sm:grid-cols-2">' +
        campo('Número de comprobante ZP', input('num_comprobante_zp', '', 'required maxlength="30"')) +
        campo('Fecha de pago', input('fecha_pago', hoy, 'type="date" required')) + '</div>' +
        campo('Comprobante de pago (PDF)', pdf('comprobante', true)) +
        areaComentario(false, 'Comentario (opcional)');
    },
  };

  /** Comportamientos comunes a todos los formularios que llevan bloque de tercero. */
  function enlazar(form, accion, S, opc) {
    bindTercero(form);
    const f = FORMS[accion];
    if (f && f.bind) f.bind(form, S, opc);
  }

  return { FORMS: FORMS, enlazar: enlazar, siguiente: siguiente, abrirModal: abrirModal, verFormato: verFormato,
    avisosF023: avisosF023, avisosF024: avisosF024, cajaAvisos: cajaAvisos, filasLegalizacion: filasLegalizacion };
})();
