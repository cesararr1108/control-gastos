<?php
$GAS_TITULO      = 'Flujos';
$GAS_MENU_ACTIVO = 'flujos';
$GAS_SCRIPTS     = array('flujos.js');
require dirname(__FILE__) . '/_cabecera.php';
if (!$GAS_ADMIN) {
    echo '<div class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">No tienes permiso para configurar flujos.</div>';
    require dirname(__FILE__) . '/_pie.php';
    exit;
}
?>
<div id="vistaLista">
  <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
      <h1 class="text-xl font-bold text-slate-900">Flujos de autorización</h1>
      <p class="text-sm text-slate-500">Arma los pasos, quién los ejecuta y cuándo aplican. Cada flujo aplica a una organización (1000, 2000…) o a todas, y vale para todas sus oficinas. Puedes tener varios del mismo tipo: al crear la solicitud el usuario elige cuál usar. Un flujo de una organización con el <b>mismo nombre</b> que uno general lo reemplaza.</p>
    </div>
    <button id="btnNuevoFlujo" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">+ Nuevo flujo</button>
  </div>
  <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
        <tr><th class="px-4 py-3">Tipo</th><th class="px-4 py-3">Nombre</th><th class="px-4 py-3">Alcance</th><th class="px-4 py-3">Pasos</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3"></th></tr>
      </thead>
      <tbody id="tablaFlujos" class="divide-y divide-slate-100"></tbody>
    </table>
  </div>
</div>

<form id="vistaEditor" class="hidden space-y-5" novalidate>
  <div class="flex items-center justify-between">
    <h1 id="tituloEditor" class="text-xl font-bold text-slate-900">Flujo</h1>
    <button type="button" id="btnVolver" class="text-sm text-slate-500 hover:text-indigo-700">← Volver</button>
  </div>
  <section class="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2">
    <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Nombre</label>
      <input id="fNombre" maxlength="120" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></div>
    <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Tipo de flujo</label>
      <select id="fTipo" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></select></div>
    <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Aplica a la organización</label>
      <select id="fAlcance" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></select></div>
    <label class="flex items-end gap-2 pb-2 text-sm"><input id="fActivo" type="checkbox" class="h-4 w-4 text-indigo-600"> Flujo activo</label>
  </section>

  <section class="space-y-3">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-semibold text-slate-700">Pasos (en orden)</h2>
      <button type="button" id="btnAddPaso" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">+ Agregar paso</button>
    </div>
    <div id="pasos" class="space-y-3"></div>
  </section>

  <div class="flex justify-end gap-3">
    <button type="button" id="btnCancelarEd" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancelar</button>
    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Guardar flujo</button>
  </div>
</form>
<?php require dirname(__FILE__) . '/_pie.php'; ?>
