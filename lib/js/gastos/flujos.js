/**
 * Armador de flujos (solo administradores).
 * Un flujo = tipo + alcance (todas las organizaciones o una: 1000, 2000…) + pasos ordenados.
 * Puede haber varias variantes activas del mismo tipo; el solicitante elige al crear.
 * Cada paso: ACCION (qué hace), responsable (solicitante | rol | usuario) y condición.
 */
(function () {
  const esc = Gastos.esc;
  const C = Gastos.CLS;
  let OPC = null;
  let editandoId = 0;

  const $lista = document.getElementById('vistaLista');
  const $editor = document.getElementById('vistaEditor');
  const $pasos = document.getElementById('pasos');

  const TIPO_TXT = { COTIZACION: 'Cotización', ANTICIPO: 'Anticipo', FACTURA: 'Factura' };

  /* ------------------------------------------------------------------ listado */

  function alcanceTexto(f) {
    return f.ORGANIZACION_VENTA ? 'Organización ' + f.ORGANIZACION_VENTA + ' (todas sus oficinas)' : 'Todas las organizaciones';
  }

  function cargarLista() {
    Gastos.api('flujos', 'listar').then(function (rows) {
      document.getElementById('tablaFlujos').innerHTML = rows.map(function (f) {
        const activo = Number(f.ACTIVO) === 1;
        const aviso = Number(f.SIN_RESPONSABLE) > 0
          ? '<div class="text-xs font-semibold text-rose-600">⚠ ' + f.SIN_RESPONSABLE + ' paso(s) sin responsable</div>' : '';
        return '<tr><td class="px-4 py-3 font-semibold">' + esc(TIPO_TXT[f.TIPO] || f.TIPO) + '</td>' +
          '<td class="px-4 py-3">' + esc(f.NOMBRE) + aviso + '</td>' +
          '<td class="px-4 py-3 text-slate-600">' + esc(alcanceTexto(f)) + '</td>' +
          '<td class="px-4 py-3">' + f.PASOS + '</td>' +
          '<td class="px-4 py-3"><span class="rounded-full px-2.5 py-0.5 text-xs font-semibold ' + (activo ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600') + '">' + (activo ? 'Activo' : 'Inactivo') + '</span></td>' +
          '<td class="px-4 py-3 text-right whitespace-nowrap">' +
            '<button data-editar="' + f.ID + '" class="font-semibold text-indigo-600 hover:underline">Editar</button> · ' +
            '<button data-estado="' + f.ID + '" data-activar="' + (activo ? 0 : 1) + '" class="font-semibold text-slate-600 hover:underline">' + (activo ? 'Desactivar' : 'Activar') + '</button></td></tr>';
      }).join('') || '<tr><td colspan="6" class="p-6 text-center text-slate-500">Aún no hay flujos.</td></tr>';
    }).catch(Gastos.error);
  }

  document.getElementById('tablaFlujos').addEventListener('click', function (e) {
    const ed = e.target.closest('[data-editar]');
    const es = e.target.closest('[data-estado]');
    if (ed) abrirEditor(Number(ed.dataset.editar));
    if (es) {
      Gastos.api('flujos', 'estado', { id: es.dataset.estado, activo: es.dataset.activar }).then(cargarLista).catch(Gastos.error);
    }
  });

  /* ------------------------------------------------------------------- editor */

  function alcanceOpciones(sel) {
    const pares = [['', 'Todas las organizaciones (flujo general)']].concat(
      OPC.organizaciones.map(function (o) { return [o, 'Organización ' + o + ' (todas sus oficinas)']; }));
    return Gastos.opts(pares, sel);
  }

  /** Acciones que aplican al tipo de flujo elegido. */
  function accionesDelTipo(tipo) {
    return Object.keys(OPC.acciones).filter(function (k) { return OPC.acciones[k].tipos.indexOf(tipo) !== -1; });
  }

  function pasoHtml(p) {
    const tipo = document.getElementById('fTipo').value;
    const acciones = accionesDelTipo(tipo).map(function (k) { return [k, OPC.acciones[k].nombre]; });
    const conds = Object.keys(OPC.condiciones).map(function (k) { return [k, OPC.condiciones[k]]; });
    const resp = Object.keys(OPC.responsables).map(function (k) { return [k, OPC.responsables[k]]; });
    const roles = OPC.roles.map(function (r) { return [r.ID, r.TITULO]; });
    return '<div class="paso rounded-xl border border-slate-200 bg-white p-4">' +
      '<div class="mb-3 flex items-center justify-between"><span class="numero rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-bold text-indigo-700"></span>' +
      '<div class="flex gap-1"><button type="button" data-mover="-1" class="rounded border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50" title="Subir">↑</button>' +
      '<button type="button" data-mover="1" class="rounded border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50" title="Bajar">↓</button>' +
      '<button type="button" data-quitar class="rounded border border-rose-200 px-2 py-1 text-xs text-rose-600 hover:bg-rose-50" title="Quitar">✕</button></div></div>' +
      '<div class="grid gap-3 sm:grid-cols-2">' +
        '<div><label class="' + C.label + '">Acción</label><select data-f="accion" class="' + C.input + '">' + Gastos.opts(acciones, p.ACCION) + '</select>' +
          '<p data-ayuda class="mt-1 text-xs text-slate-500"></p></div>' +
        '<div><label class="' + C.label + '">Nombre del paso</label><input data-f="nombre" maxlength="120" value="' + esc(p.NOMBRE) + '" class="' + C.input + '"></div>' +
        '<div><label class="' + C.label + '">Lo ejecuta</label><select data-f="responsable_tipo" class="' + C.input + '">' + Gastos.opts(resp, p.RESPONSABLE_TIPO) + '</select></div>' +
        '<div data-blq="ROL"><label class="' + C.label + '">Rol responsable</label><select data-f="rol_id" class="' + C.input + '">' + Gastos.opts(roles, p.ROL_ID, 'Selecciona un rol…') + '</select></div>' +
        '<div data-blq="USUARIO" class="hidden"><label class="' + C.label + '">Usuario responsable</label>' +
          '<input data-f="usuario" list="dlUsr" autocomplete="off" placeholder="Buscar por login o nombre…" value="' + esc(p.USUARIO_NOMBRE || '') + '" data-id="' + esc(p.USUARIO_ID || '') + '" class="' + C.input + '"></div>' +
        '<div><label class="' + C.label + '">Cuándo aplica</label><select data-f="condicion" class="' + C.input + '">' + Gastos.opts(conds, p.CONDICION) + '</select></div>' +
      '</div></div>';
  }

  /** Refresca numeración, ayuda y bloques visibles de cada paso. */
  function refrescar() {
    $pasos.querySelectorAll('.paso').forEach(function (el, i) {
      el.querySelector('.numero').textContent = 'Paso ' + (i + 1);
      const accion = el.querySelector('[data-f=accion]').value;
      el.querySelector('[data-ayuda]').textContent = OPC.acciones[accion] ? OPC.acciones[accion].ayuda : '';
      const rt = el.querySelector('[data-f=responsable_tipo]').value;
      el.querySelectorAll('[data-blq]').forEach(function (b) { b.classList.toggle('hidden', b.dataset.blq !== rt); });
    });
  }

  function agregarPaso(datos) {
    const tipo = document.getElementById('fTipo').value;
    const posibles = accionesDelTipo(tipo);
    const p = Object.assign({ ACCION: posibles[0], NOMBRE: '', RESPONSABLE_TIPO: 'ROL', ROL_ID: '', USUARIO_ID: '', CONDICION: 'SIEMPRE' }, datos || {});
    if (!datos) {
      // Sugerir nombre y responsable según la acción.
      p.NOMBRE = OPC.acciones[p.ACCION].nombre;
      p.RESPONSABLE_TIPO = OPC.acciones[p.ACCION].actor === 'SOLICITANTE' ? 'SOLICITANTE' : 'ROL';
    }
    const cont = document.createElement('div');
    cont.innerHTML = pasoHtml(p);
    $pasos.appendChild(cont.firstChild);
    refrescar();
  }

  $pasos.addEventListener('change', function (e) {
    const paso = e.target.closest('.paso');
    if (!paso) return;
    if (e.target.dataset.f === 'accion') {
      // Al cambiar la acción, proponer su nombre y quién la ejecuta.
      const a = OPC.acciones[e.target.value];
      paso.querySelector('[data-f=nombre]').value = a.nombre;
      paso.querySelector('[data-f=responsable_tipo]').value = a.actor === 'SOLICITANTE' ? 'SOLICITANTE' : 'ROL';
    }
    if (e.target.dataset.f === 'usuario') {
      // El datalist entrega "LOGIN - Nombre": buscar el ID en el mapa cargado.
      e.target.dataset.id = usuariosMapa[e.target.value] || '';
    }
    refrescar();
  });

  const usuariosMapa = {};
  $pasos.addEventListener('input', Gastos.debounce(function (e) {
    if (e.target.dataset.f !== 'usuario') return;
    const q = e.target.value.trim();
    if (usuariosMapa[q] || q.length < 3) return;
    Gastos.api('catalogo', 'usuarios', { q: q }).then(function (rows) {
      let dl = document.getElementById('dlUsr');
      if (!dl) { dl = document.createElement('datalist'); dl.id = 'dlUsr'; document.body.appendChild(dl); }
      dl.innerHTML = rows.map(function (u) {
        const clave = u.LOGIN + ' - ' + u.NOMBRE;
        usuariosMapa[clave] = u.ID;
        return '<option value="' + esc(clave) + '"></option>';
      }).join('');
    }).catch(function () {});
  }, 300));

  $pasos.addEventListener('click', function (e) {
    const paso = e.target.closest('.paso');
    if (!paso) return;
    if (e.target.closest('[data-quitar]')) paso.remove();
    const mv = e.target.closest('[data-mover]');
    if (mv) {
      if (mv.dataset.mover === '-1' && paso.previousElementSibling) $pasos.insertBefore(paso, paso.previousElementSibling);
      if (mv.dataset.mover === '1' && paso.nextElementSibling) $pasos.insertBefore(paso.nextElementSibling, paso);
    }
    refrescar();
  });

  function abrirEditor(id) {
    Gastos.api('flujos', 'obtener', { id: id || '' }).then(function (f) {
      editandoId = Number(f.ID) || 0;
      document.getElementById('tituloEditor').textContent = editandoId ? 'Editar flujo' : 'Nuevo flujo';
      document.getElementById('fNombre').value = f.NOMBRE || '';
      document.getElementById('fTipo').innerHTML = Gastos.opts(Object.keys(OPC.tipos).map(function (k) { return [k, OPC.tipos[k].nombre]; }), f.TIPO);
      document.getElementById('fAlcance').innerHTML = alcanceOpciones(f.ORGANIZACION_VENTA || '');
      document.getElementById('fActivo').checked = Number(f.ACTIVO) === 1;
      $pasos.innerHTML = '';
      (f.pasos || []).forEach(agregarPaso);
      if (!editandoId) agregarPaso();
      $lista.classList.add('hidden');
      $editor.classList.remove('hidden');
    }).catch(Gastos.error);
  }

  function cerrarEditor() {
    $editor.classList.add('hidden');
    $lista.classList.remove('hidden');
    cargarLista();
  }

  // Cambiar el tipo reconstruye los pasos porque no todas las acciones aplican a todos los tipos.
  let tipoPrevio = '';
  document.getElementById('fTipo').addEventListener('focus', function () { tipoPrevio = this.value; });
  document.getElementById('fTipo').addEventListener('change', function () {
    const sel = this;
    Gastos.confirmar('¿Cambiar el tipo de flujo?', 'Los pasos actuales se reemplazan por un paso inicial.').then(function (si) {
      if (!si) { sel.value = tipoPrevio; return; }
      tipoPrevio = sel.value;
      $pasos.innerHTML = '';
      agregarPaso();
    });
  });

  function recolectar() {
    const pasos = [];
    $pasos.querySelectorAll('.paso').forEach(function (el) {
      const g = function (n) { return el.querySelector('[data-f=' + n + ']'); };
      pasos.push({
        accion: g('accion').value, nombre: g('nombre').value, responsable_tipo: g('responsable_tipo').value,
        rol_id: g('rol_id').value, usuario_id: g('usuario').dataset.id || '', condicion: g('condicion').value,
      });
    });
    return {
      id: editandoId, nombre: document.getElementById('fNombre').value, tipo: document.getElementById('fTipo').value,
      organizacion: document.getElementById('fAlcance').value, activo: document.getElementById('fActivo').checked ? 1 : 0, pasos: pasos,
    };
  }

  $editor.addEventListener('submit', function (e) {
    e.preventDefault();
    const datos = recolectar();
    const faltan = datos.pasos.filter(function (p) {
      return (p.responsable_tipo === 'ROL' && !p.rol_id) || (p.responsable_tipo === 'USUARIO' && !p.usuario_id);
    });
    const guardar = function () {
      Gastos.api('flujos', 'guardar', datos).then(function () { return Gastos.ok('Flujo guardado'); }).then(cerrarEditor).catch(Gastos.error);
    };
    if (faltan.length) {
      Gastos.confirmar('Hay pasos sin responsable', 'Se guardará, pero nadie podrá crear solicitudes con este flujo hasta que asignes un responsable en cada paso.', 'Guardar de todos modos')
        .then(function (si) { if (si) guardar(); });
    } else {
      guardar();
    }
  });

  document.getElementById('btnNuevoFlujo').addEventListener('click', function () { abrirEditor(0); });
  document.getElementById('btnAddPaso').addEventListener('click', function () { agregarPaso(); });
  document.getElementById('btnVolver').addEventListener('click', cerrarEditor);
  document.getElementById('btnCancelarEd').addEventListener('click', cerrarEditor);

  Gastos.opciones().then(function (o) { OPC = o; cargarLista(); }).catch(Gastos.error);
})();
