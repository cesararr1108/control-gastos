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
      '<legend class="px-2 text-sm font-semibold text-slate-700">Datos del tercero</legend>' +
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
    function rellenar(t) {
      form.elements.tercero_nit.value = t.NIT || '';
      form.elements.tercero_nombre.value = t.NOMBRE || '';
      form.elements.tercero_celular.value = (t.CELULAR || '').replace(/\D/g, '');
      form.elements.tercero_email.value = t.EMAIL || '';
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
  function mostrarSi(form, nombre, valor, selector) {
    const bloque = form.querySelector(selector);
    const actualizar = function () {
      const el = form.elements[nombre];
      const v = el.value !== undefined ? el.value : '';
      const ver = v === valor;
      bloque.classList.toggle('hidden', !ver);
      bloque.querySelectorAll('input,select,textarea').forEach(function (i) {
        if (i.dataset.req === undefined) i.dataset.req = i.required ? '1' : '0';
        i.disabled = !ver;               // lo oculto no se envía ni se valida
        i.required = ver && i.dataset.req === '1';
      });
    };
    form.querySelectorAll('[name="' + nombre + '"]').forEach(function (e) { e.addEventListener('change', actualizar); });
    actualizar();
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
   * Sirve para advertir al usuario antes de enviar.
   */
  function siguiente(S, hayAnticipo) {
    const actual = S.pasoActual ? Number(S.pasoActual.ORDEN) : 0;
    const omitidos = [];
    let sig = null;
    S.pasos
      .filter(function (p) { return Number(p.ORDEN) > actual && p.ESTADO === 'PENDIENTE'; })
      .sort(function (a, b) { return Number(a.ORDEN) - Number(b.ORDEN); })
      .some(function (p) {
        if (cumple(p.CONDICION, hayAnticipo)) { sig = p; return true; }
        omitidos.push(p);
        return false;
      });
    return { siguiente: sig, omitidos: omitidos };
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
        campo('Decisión', radios('decision', [['APROBAR', 'Autorizar la cotización elegida'], ['RECHAZAR', 'Rechazar']])) +
        areaComentario(true, 'Comentario (obligatorio)');
    },
    bind: function (form) {
      // Al rechazar no hace falta elegir cotización.
      const grupo = form.querySelector('#grupoCot');
      form.querySelectorAll('[name="decision"]').forEach(function (r) {
        r.addEventListener('change', function () { grupo.classList.toggle('opacity-40', form.elements.decision.value === 'RECHAZAR'); });
      });
    },
    validar: function (form) {
      if (form.elements.decision.value === 'APROBAR' && !form.elements.cotizacion_elegida.value) return 'Elige una de las tres cotizaciones.';
    },
  };

  FORMS.DECISION_ANTICIPO = {
    html: function (S) {
      const el = S.cotizaciones.filter(function (c) { return Number(c.NUMERO) === Number(S.COTIZACION_ELEGIDA); })[0];
      return (el ? '<div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">Cotización autorizada: <b>' + esc(el.PROVEEDOR || 'Cotización ' + el.NUMERO) + '</b> · ' + Gastos.moneda(el.VALOR) + '</div>' : '') +
        campo('¿Necesitas anticipo?', radios('requiere_anticipo', [['SI', 'Sí, necesito anticipo'], ['NO', 'No, ya tengo el preliminar']])) +
        '<div id="blqAnticipo" class="hidden">' + campo('Valor del anticipo (por cotización)', dinero('valor_anticipo', '', 'required')) + '</div>' +
        tercero(S);
    },
    bind: function (form) { mostrarSi(form, 'requiere_anticipo', 'SI', '#blqAnticipo'); },
    /** Advertencia previa al envío: dice a qué paso se va y cuáles se saltan. */
    confirmar: function (form, S) {
      const hay = form.elements.requiere_anticipo.value === 'SI';
      const r = siguiente(S, hay);
      const destino = r.siguiente
        ? '«' + r.siguiente.NOMBRE + '» (' + r.siguiente.RESPONSABLE + ')'
        : 'el cierre de la solicitud';
      const omit = r.omitidos.length ? '\n\nSe omitirán estos pasos: ' + r.omitidos.map(function (p) { return p.NOMBRE; }).join(', ') + '.' : '';
      if (hay) {
        return { titulo: '¿Solicitar anticipo?', texto: 'La solicitud continuará con ' + destino + '.' + omit, boton: 'Sí, solicitar anticipo' };
      }
      return {
        titulo: '¿Seguro que ya tienes el preliminar?',
        texto: 'No se solicitará anticipo: pasarás directo a ' + destino + '.' + omit + '\n\nEsta decisión no se puede deshacer.',
        boton: 'Sí, pasar al preliminar',
      };
    },
  };

  FORMS.SOLICITAR_ANTICIPO = {
    html: function (S, paso, opc) {
      const conceptos = Object.keys(opc.conceptos).map(function (k) { return [k, opc.conceptos[k]]; });
      return '<div class="grid gap-4 sm:grid-cols-2">' + selectProceso(S, opc) +
        campo('Tipo de anticipo', '<select name="tipo_anticipo" required class="' + C.input + '">' +
          Gastos.opts([['FACTURA', 'Por factura'], ['VIATICOS', 'Por viáticos (gastos de viaje)']], '', 'Selecciona…') + '</select>') + '</div>' +
        campo('Descripción del gasto', '<textarea name="descripcion" rows="2" required maxlength="500" class="' + C.input + '"></textarea>') +
        '<div id="blqFactura" class="hidden">' + campo('Valor del anticipo', dinero('valor_anticipo', '', 'required')) + '</div>' +
        '<fieldset id="blqViaticos" class="hidden space-y-3 rounded-xl border border-slate-200 p-4">' +
          '<legend class="px-2 text-sm font-semibold text-slate-700">Formulario de gastos de viaje</legend>' +
          '<div class="grid gap-3 sm:grid-cols-2">' +
            campo('Destino', input('viaticos_destino', '', 'required maxlength="100"')) +
            campo('Motivo del viaje', input('viaticos_motivo', '', 'required maxlength="300"')) +
            campo('Fecha de inicio', input('viaticos_fecha_inicio', '', 'type="date" required')) +
            campo('Fecha de fin', input('viaticos_fecha_fin', '', 'type="date" required')) +
          '</div>' +
          '<div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="text-left text-xs uppercase text-slate-500"><tr>' +
            '<th class="py-1 pr-2">Concepto</th><th class="py-1 pr-2">Descripción</th><th class="py-1 pr-2 w-24">Cant.</th>' +
            '<th class="py-1 pr-2 w-36">Valor unit.</th><th class="py-1 pr-2 w-36 text-right">Total</th><th></th></tr></thead>' +
            '<tbody id="lineasViaticos"></tbody></table></div>' +
          '<div class="flex items-center justify-between"><button type="button" id="addLinea" class="' + C.btn + ' ' + C.ghost + '">+ Agregar línea</button>' +
            '<div class="text-sm">Total anticipo: <b id="totalViaticos" class="tabular-nums">$ 0</b></div></div>' +
        '</fieldset>' + tercero(S);
    },
    bind: function (form, S, opc) {
      const tbody = form.querySelector('#lineasViaticos');
      const conceptos = Object.keys(opc.conceptos).map(function (k) { return [k, opc.conceptos[k]]; });
      let n = 0;
      function total() {
        let t = 0;
        tbody.querySelectorAll('tr').forEach(function (tr) {
          const sub = Gastos.num(tr.querySelector('[data-cant]').value) * Gastos.num(tr.querySelector('[data-unit]').value);
          tr.querySelector('[data-sub]').textContent = Gastos.moneda(sub);
          t += sub;
        });
        form.querySelector('#totalViaticos').textContent = Gastos.moneda(t);
      }
      function agregar() {
        const i = n++;
        const tr = document.createElement('tr');
        tr.innerHTML =
          '<td class="py-1 pr-2"><select name="lineas[' + i + '][concepto]" class="' + C.input + '">' + Gastos.opts(conceptos, '', 'Concepto…') + '</select></td>' +
          '<td class="py-1 pr-2"><input name="lineas[' + i + '][descripcion]" maxlength="200" class="' + C.input + '"></td>' +
          '<td class="py-1 pr-2"><input name="lineas[' + i + '][cantidad]" data-cant inputmode="decimal" value="1" class="' + C.input + ' text-right"></td>' +
          '<td class="py-1 pr-2"><input name="lineas[' + i + '][valor_unitario]" data-money data-unit inputmode="decimal" class="' + C.input + ' text-right"></td>' +
          '<td class="py-1 pr-2 text-right tabular-nums" data-sub>$ 0</td>' +
          '<td class="py-1"><button type="button" class="text-rose-600 hover:text-rose-800" title="Quitar">✕</button></td>';
        tr.querySelector('button').addEventListener('click', function () { tr.remove(); total(); });
        tr.addEventListener('input', total);
        tbody.appendChild(tr);
      }
      form.querySelector('#addLinea').addEventListener('click', agregar);
      agregar();
      mostrarSi(form, 'tipo_anticipo', 'FACTURA', '#blqFactura');
      // Bloque de viáticos: mismo mecanismo con otro valor.
      const bv = form.querySelector('#blqViaticos');
      const actualizarV = function () {
        const ver = form.elements.tipo_anticipo.value === 'VIATICOS';
        bv.classList.toggle('hidden', !ver);
        bv.querySelectorAll('input,select').forEach(function (i) {
          if (i.dataset.req === undefined) i.dataset.req = i.required ? '1' : '0';
          i.disabled = !ver;
          i.required = ver && i.dataset.req === '1';
        });
      };
      form.elements.tipo_anticipo.addEventListener('change', actualizarV);
      actualizarV();
    },
  };

  FORMS.MONTAR_PRELIMINAR = {
    html: function (S, paso, opc) {
      const exige = !!Number(S.REQUIERE_SOPORTE_PAGO);
      return (Number(S.REQUIERE_ANTICIPO) && S.VALOR_ANTICIPO ? '<div class="rounded-lg bg-indigo-50 p-3 text-sm text-indigo-800">Anticipo aprobado: <b>' + Gastos.moneda(S.VALOR_ANTICIPO) + '</b></div>' : '') +
        '<div class="grid gap-4 sm:grid-cols-2">' +
          campo('Número de preliminar', input('num_preliminar', '', 'required maxlength="30"')) +
          campo('Valor del preliminar', dinero('valor_total', '', 'required')) +
          (S.PROCESO_ID ? '' : selectProceso(S, opc)) +
        '</div>' +
        (S.DESCRIPCION ? '' : campo('Descripción', '<textarea name="descripcion" rows="2" maxlength="500" class="' + C.input + '"></textarea>')) +
        tercero(S) +
        '<div class="grid gap-4 sm:grid-cols-2">' +
          campo('Soporte de pago (PDF)' + (exige ? ' — obligatorio' : ' — opcional'), pdf('soporte_pago', exige)) +
          campo('Preliminar en PDF (opcional)', pdf('preliminar_pdf', false)) +
        '</div>';
    },
  };

  FORMS.APROBAR = {
    html: function (S, paso) {
      return campo('Decisión', radios('decision', [['APROBAR', 'Aprobar'], ['RECHAZAR', 'Rechazar']])) +
        areaComentario(false, 'Comentario (obligatorio si rechazas)');
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
    html: function () {
      const hoy = new Date().toISOString().slice(0, 10);
      return '<div class="grid gap-4 sm:grid-cols-2">' +
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

  return { FORMS: FORMS, enlazar: enlazar, siguiente: siguiente };
})();
