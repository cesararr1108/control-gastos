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

  /** Número, fecha y valor del preliminar (+ proceso/descripción si el flujo no los pidió antes). */
  function preliminarDatos(S, opc) {
    return '<div class="grid gap-4 sm:grid-cols-2">' +
      campo('Número de preliminar', input('num_preliminar', '', 'required maxlength="30"')) +
      campo('Fecha de la factura', input('fecha_factura', '', 'type="date" required')) +
      campo('Valor de la factura / preliminar', dinero('valor_total', '', 'required')) +
      (S.PROCESO_ID ? '' : selectProceso(S, opc)) +
      '</div>' +
      (S.DESCRIPCION ? '' : campo('Descripción', '<textarea name="descripcion" rows="2" maxlength="500" class="' + C.input + '"></textarea>'));
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
    },
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

  /* ---------------- Solicitud de viáticos: formato F-FR-023 ---------------- */

  function viaticosHtml(S, opc) {
    const hoy = Gastos.isoFecha(new Date());
    const rutas = function (clave, titulo) {
      return '<div class="grid items-end gap-3 sm:grid-cols-[8rem_1fr_auto]">' +
        '<div class="text-sm font-semibold text-slate-600">' + titulo + '</div>' +
        campo('Ruta', input('ruta_' + clave, '', 'maxlength="150" placeholder="Ej. Medellín - Bogotá"')) +
        '<div class="flex gap-4 pb-2 text-sm">' +
          '<label class="inline-flex items-center gap-2"><input type="radio" name="tipo_' + clave + '" value="AEREO" class="h-4 w-4 text-indigo-600"> Aéreo</label>' +
          '<label class="inline-flex items-center gap-2"><input type="radio" name="tipo_' + clave + '" value="TERRESTRE" class="h-4 w-4 text-indigo-600"> Terrestre</label>' +
        '</div></div>';
    };
    const filas = Object.keys(opc.conceptos).map(function (k) {
      return '<tr><td class="py-1 pr-3 text-sm text-slate-700">' + esc(opc.conceptos[k]) +
        (k === 'OTROS' ? ' <input name="presupuesto_otros" maxlength="200" placeholder="¿Cuál?" class="ml-2 inline-block w-40 rounded-lg border border-slate-300 px-2 py-1 text-sm">' : '') +
        '</td><td class="py-1 w-44">' + dinero('presupuesto[' + k + ']', '', 'data-pres') + '</td></tr>';
    }).join('');
    return '<fieldset id="blqViaticos" class="hidden min-w-0 space-y-5 rounded-xl border border-slate-200 p-4">' +
      '<legend class="px-2 text-sm font-semibold text-slate-700">Formato de solicitud de viáticos (F-FR-023)</legend>' +
      '<div><h4 class="mb-2 text-xs font-bold uppercase tracking-wide text-indigo-700">Motivo y fechas</h4>' +
        '<div class="grid gap-3 sm:grid-cols-2">' +
          campo('Lugar / destino', input('viaticos_destino', '', 'required maxlength="100"')) +
          campo('Tel. fijo (opcional)', input('viaticos_tel_fijo', '', 'maxlength="20" inputmode="numeric"')) +
          campo('Fecha de salida', input('viaticos_fecha_inicio', '', 'type="date" required min="' + hoy + '"')) +
          campo('Fecha de regreso', input('viaticos_fecha_fin', '', 'type="date" required min="' + hoy + '"')) +
        '</div>' +
        campo('Motivo de la solicitud', '<textarea name="viaticos_motivo" rows="2" required maxlength="300" class="' + C.input + '"></textarea>', 'mt-3') +
      '</div>' +
      '<div class="space-y-2"><h4 class="text-xs font-bold uppercase tracking-wide text-indigo-700">Solicitud de pasajes</h4>' +
        rutas('salida', 'Salida') + rutas('regreso', 'Regreso') + '</div>' +
      '<div><h4 class="mb-2 text-xs font-bold uppercase tracking-wide text-indigo-700">Valor presupuestado</h4>' +
        '<table class="w-full max-w-lg"><tbody>' + filas + '</tbody><tfoot><tr><td class="pt-2 text-right text-sm font-bold">Total solicitado</td>' +
        '<td class="pt-2 text-right text-sm font-bold tabular-nums" id="totalViaticos">$ 0</td></tr></tfoot></table></div>' +
      '<label class="flex gap-3 rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-xs text-emerald-900">' +
        '<input type="checkbox" name="acepta_descuento" value="SI" required class="mt-0.5 h-4 w-4 shrink-0">' +
        '<span>En el eventual caso que llegase a finalizar mi contrato de trabajo y no haya realizado la debida legalización de viáticos en el plazo convenido de ' +
        Number(opc.diasLegalizacion || 30) + ' días posteriores de recibido el anticipo, de manera expresa, libre y voluntaria, autorizo al empleador para que retenga, deduzca y cobre de mi ' +
        'liquidación final de prestaciones sociales, salarios, comisiones e indemnizaciones los saldos que esté adeudando por este concepto, de conformidad con los artículos 150 y 151 del Código Sustantivo del Trabajo.</span></label>' +
      '</fieldset>';
  }

  function bindViaticos(form, S, opc) {
    const total = function () {
      let t = 0;
      form.querySelectorAll('[data-pres]').forEach(function (i) { t += Gastos.num(i.value); });
      form.querySelector('#totalViaticos').textContent = Gastos.moneda(t);
    };
    form.querySelector('#blqViaticos').addEventListener('input', total);
    // La fecha de regreso no puede ser anterior a la de salida.
    form.elements.viaticos_fecha_inicio.addEventListener('change', function () {
      form.elements.viaticos_fecha_fin.min = this.value || form.elements.viaticos_fecha_fin.min;
    });
    // En viáticos el "tercero" es quien viaja: se prellenan sus datos (editables).
    const tipo = form.elements.tipo_anticipo;
    const prellenar = function () {
      const esViat = tipo.value === 'VIATICOS';
      form.querySelector('#tituloTercero').textContent = esViat ? 'Datos de quien solicita (quien viaja)' : 'Datos del tercero';
      const yo = opc.yo || {};
      if (esViat) {
        [['tercero_nit', yo.IDENTIFICACION], ['tercero_nombre', yo.NOMBRE], ['tercero_celular', yo.CELULAR], ['tercero_email', yo.EMAIL]].forEach(function (p) {
          if (!form.elements[p[0]].value && p[1]) form.elements[p[0]].value = String(p[1]).trim();
        });
        if (!form.elements.viaticos_tel_fijo.value && yo.EXT) form.elements.viaticos_tel_fijo.value = String(yo.EXT).trim();
      }
    };
    tipo.addEventListener('change', prellenar);
    total();
  }

  FORMS.SOLICITAR_ANTICIPO = {
    html: function (S, paso, opc) {
      return '<div class="grid gap-4 sm:grid-cols-2">' + selectProceso(S, opc) +
        campo('Tipo de anticipo', '<select name="tipo_anticipo" required class="' + C.input + '">' +
          Gastos.opts([['FACTURA', 'Por factura'], ['VIATICOS', 'Por viáticos (formato F-FR-023)']], '', 'Selecciona…') + '</select>') + '</div>' +
        '<div id="blqFactura" class="hidden space-y-4">' +
          campo('Descripción del gasto', '<textarea name="descripcion" rows="2" required maxlength="500" class="' + C.input + '"></textarea>') +
          campo('Valor del anticipo', dinero('valor_anticipo', '', 'required')) +
        '</div>' +
        tercero(S) + viaticosHtml(S, opc);
    },
    bind: function (form, S, opc) {
      mostrarSi(form, 'tipo_anticipo', 'FACTURA', '#blqFactura');
      mostrarSi(form, 'tipo_anticipo', 'VIATICOS', '#blqViaticos');
      bindViaticos(form, S, opc);
    },
    validar: function (form) {
      if (form.elements.tipo_anticipo.value !== 'VIATICOS') return;
      const ini = form.elements.viaticos_fecha_inicio.value, fin = form.elements.viaticos_fecha_fin.value;
      if (ini && fin && fin < ini) return 'La fecha de regreso no puede ser anterior a la de salida.';
      let t = 0;
      form.querySelectorAll('[data-pres]').forEach(function (i) { t += Gastos.num(i.value); });
      if (t <= 0) return 'Indica al menos un valor presupuestado.';
      if (Gastos.num(form.elements['presupuesto[OTROS]'].value) > 0 && !form.elements.presupuesto_otros.value.trim()) return 'Indica cuál es el concepto «Otros».';
    },
  };

  /* ---------------- Legalización de viáticos: formato F-FR-024 ---------------- */

  function legalizacionHtml(S, opc) {
    const dias = Number(opc.diasLegalizacion || 30);
    let plazo = '';
    if (S.VIATICOS_FECHA_FIN) {
      const trans = Math.floor((new Date() - new Date(S.VIATICOS_FECHA_FIN + 'T00:00:00')) / 86400000);
      plazo = trans > dias
        ? '<div class="rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-800">⚠ Han pasado <b>' + trans + ' días</b> desde el regreso: el plazo para legalizar es de ' + dias + ' días.</div>'
        : '<div class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">Plazo para legalizar: ' + dias + ' días después del viaje' + (trans >= 0 ? ' (van ' + trans + ').' : '.') + '</div>';
    }
    const tipos = Object.keys(opc.tiposGasto);
    const dato = function (l, v) { return '<div><div class="' + C.label + '">' + l + '</div><div class="text-sm text-slate-800">' + esc(v || '—') + '</div></div>'; };
    return '<fieldset class="min-w-0 space-y-4 rounded-xl border border-slate-200 p-4">' +
      '<legend class="px-2 text-sm font-semibold text-slate-700">Formato de legalización de viáticos (F-FR-024)</legend>' +
      '<div class="grid gap-3 sm:grid-cols-4">' + dato('Nombre', S.TERCERO_NOMBRE) + dato('C.C.', S.TERCERO_NIT) + dato('Lugar', S.VIATICOS_DESTINO) +
        dato('Del / al', S.VIATICOS_FECHA_INICIO ? Gastos.fecha(S.VIATICOS_FECHA_INICIO) + ' — ' + Gastos.fecha(S.VIATICOS_FECHA_FIN) : '') + '</div>' +
      plazo +
      '<div class="overflow-x-auto"><table class="min-w-full text-sm"><thead class="text-left text-xs uppercase text-slate-500"><tr>' +
        '<th class="py-1 pr-1">Fecha</th><th class="py-1 pr-1">C. costo</th><th class="py-1 pr-1">Doc</th>' +
        '<th class="py-1 pr-1">Ciudad y detalles</th><th class="py-1 pr-1">Tipo</th><th class="py-1 pr-1 text-right">Valor</th><th></th></tr></thead>' +
        '<tbody id="lineasLegal"></tbody></table></div>' +
      '<button type="button" id="addLegal" class="' + C.btn + ' ' + C.ghost + '">+ Agregar gasto</button>' +
      '<div class="overflow-x-auto"><table class="min-w-full text-xs"><thead class="text-slate-500"><tr>' +
        tipos.map(function (t) { return '<th class="px-2 py-1 text-right font-semibold">' + esc(opc.tiposGasto[t]) + '</th>'; }).join('') +
        '</tr></thead><tbody><tr id="subtotalesLegal"></tr></tbody></table></div>' +
      '<table class="ml-auto text-sm"><tbody>' +
        '<tr><td class="py-0.5 pr-6 text-right">Retefuente descontada</td><td class="py-0.5 text-right tabular-nums" id="reteLegal">$ 0</td></tr>' +
        '<tr><td class="py-0.5 pr-6 text-right font-semibold">Total cuenta de gastos</td><td class="py-0.5 text-right font-semibold tabular-nums" id="totalLegal">$ 0</td></tr>' +
        '<tr><td class="py-0.5 pr-6 text-right">Suma recibida (anticipo)</td><td class="py-0.5 text-right tabular-nums">' + Gastos.moneda(S.VALOR_ANTICIPO) + '</td></tr>' +
        '<tr><td class="py-0.5 pr-6 text-right">Menos valor de esta cuenta de gastos</td><td class="py-0.5 text-right tabular-nums" id="menosLegal">$ 0</td></tr>' +
      '</tbody></table>' +
      '<div id="saldoLegal" class="rounded-lg border p-3 text-sm"></div>' +
      '<p class="text-xs text-slate-500">Hotel y alimentación superiores a ' + Gastos.moneda(opc.retefuente.tope) + ': se descuenta el ' + (opc.retefuente.tasa * 100).toLocaleString('es-CO') + ' % de retefuente.</p>' +
      campo('Soportes de la legalización (un solo PDF con todas las facturas y, si hay reintegro, su comprobante)', pdf('soportes_legalizacion', true)) +
      '</fieldset>';
  }

  /** Líneas dinámicas y cálculo en vivo de subtotales, retefuente, total y saldo (misma regla que el servidor). */
  function bindLegalizacion(form, S, opc) {
    const tbody = form.querySelector('#lineasLegal');
    const tipos = Object.keys(opc.tiposGasto);
    const rt = opc.retefuente;
    const hoy = Gastos.isoFecha(new Date());
    const min = S.VIATICOS_FECHA_INICIO ? sumarDias(S.VIATICOS_FECHA_INICIO, -1) : '';
    let max = S.VIATICOS_FECHA_FIN ? sumarDias(S.VIATICOS_FECHA_FIN, 1) : hoy;
    if (max > hoy) max = hoy;
    let n = 0;
    function recalcular() {
      const sub = {};
      let suma = 0, rete = 0;
      tbody.querySelectorAll('tr').forEach(function (tr) {
        const t = tr.querySelector('[data-tipo]').value;
        const v = Math.round(Gastos.num(tr.querySelector('[data-money]').value) * 100) / 100;
        if (!t || v <= 0) return;
        sub[t] = (sub[t] || 0) + v;
        suma += v;
        if (rt.tipos.indexOf(t) !== -1 && v > rt.tope) rete += Math.round(v * rt.tasa);
      });
      const total = suma - rete;
      form.querySelector('#subtotalesLegal').innerHTML = tipos.map(function (t) {
        return '<td class="px-2 py-1 text-right tabular-nums">' + (sub[t] ? Gastos.moneda(sub[t]) : '—') + '</td>';
      }).join('');
      form.querySelector('#reteLegal').textContent = Gastos.moneda(rete);
      form.querySelector('#totalLegal').textContent = Gastos.moneda(total);
      form.querySelector('#menosLegal').textContent = Gastos.moneda(total);
      const info = Gastos.saldo((Number(S.VALOR_ANTICIPO) || 0) - total);
      const box = form.querySelector('#saldoLegal');
      box.className = 'rounded-lg border p-3 text-sm ' + info.cls;
      box.innerHTML = '<b>Saldo A/F (+ DF Roma / − empleado): ' + esc(info.titulo) + '</b><br>' + esc(info.detalle);
    }
    function agregar() {
      const i = n++;
      const tr = document.createElement('tr');
      tr.innerHTML =
        '<td class="py-1 pr-1"><input type="date" name="legal[' + i + '][fecha]" required' + (min ? ' min="' + min + '"' : '') + ' max="' + max + '" class="' + C.input + ' min-w-[8.5rem] px-2"></td>' +
        '<td class="py-1 pr-1"><input name="legal[' + i + '][centro_costo]" required maxlength="40" value="' + esc(S.CENTRO_COSTOS || '') + '" class="' + C.input + ' w-[4.5rem] px-2"></td>' +
        '<td class="py-1 pr-1"><input name="legal[' + i + '][documento]" maxlength="30" class="' + C.input + ' w-[5.5rem] px-2"></td>' +
        '<td class="py-1 pr-1"><input name="legal[' + i + '][detalle]" required maxlength="200" class="' + C.input + ' min-w-[9rem] px-2"></td>' +
        '<td class="py-1 pr-1"><select name="legal[' + i + '][tipo]" data-tipo required class="' + C.input + ' min-w-[7rem] px-2">' +
          Gastos.opts(tipos.map(function (t) { return [t, opc.tiposGasto[t]]; }), '', 'Tipo…') + '</select></td>' +
        '<td class="py-1 pr-1"><input name="legal[' + i + '][valor]" data-money required inputmode="decimal" class="' + C.input + ' w-[6.5rem] px-2 text-right"></td>' +
        '<td class="py-1"><button type="button" class="text-rose-600 hover:text-rose-800" title="Quitar">✕</button></td>';
      tr.querySelector('button').addEventListener('click', function () {
        if (tbody.children.length > 1) { tr.remove(); recalcular(); }
      });
      tr.addEventListener('input', recalcular);
      tr.addEventListener('change', recalcular);
      tbody.appendChild(tr);
    }
    form.querySelector('#addLegal').addEventListener('click', agregar);
    agregar();
    recalcular();
  }

  FORMS.MONTAR_PRELIMINAR = {
    html: function (S, paso, opc) {
      const aviso = Number(S.REQUIERE_ANTICIPO) && S.VALOR_ANTICIPO ? '<div class="rounded-lg bg-indigo-50 p-3 text-sm text-indigo-800">Anticipo aprobado: <b>' + Gastos.moneda(S.VALOR_ANTICIPO) + '</b></div>' : '';
      // Anticipo por viáticos: en lugar de la factura se diligencia la legalización (F-FR-024).
      if (S.TIPO_ANTICIPO === 'VIATICOS') {
        return aviso + '<div class="grid gap-4 sm:grid-cols-2">' + campo('Número de preliminar', input('num_preliminar', '', 'required maxlength="30"')) + '</div>' +
          legalizacionHtml(S, opc);
      }
      return aviso + preliminarDatos(S, opc) + tercero(S) + preliminarArchivos(S);
    },
    bind: function (form, S, opc) {
      if (S.TIPO_ANTICIPO === 'VIATICOS') bindLegalizacion(form, S, opc);
    },
    validar: function (form, S) {
      if (S.TIPO_ANTICIPO === 'VIATICOS' && !form.querySelector('#lineasLegal tr')) return 'Agrega al menos un gasto a la legalización.';
    },
  };

  FORMS.APROBAR = {
    html: function (S, paso) {
      let resumen = '';
      if (S.VALOR_LEGALIZADO !== null && S.VALOR_LEGALIZADO !== undefined) {
        const info = Gastos.saldo(S.SALDO_LEGALIZACION);
        resumen = '<div class="rounded-lg border p-3 text-sm ' + info.cls + '">Anticipo ' + Gastos.moneda(S.VALOR_ANTICIPO) + ' · legalizado ' +
          Gastos.moneda(S.VALOR_LEGALIZADO) + '<br><b>' + esc(info.titulo) + '</b></div>';
      }
      return resumen + campo('Decisión', radios('decision', [['APROBAR', 'Aprobar'], ['RECHAZAR', 'Rechazar']])) +
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
