<?php
$GAS_TITULO      = 'Solicitudes';
$GAS_MENU_ACTIVO = 'bandeja';
$GAS_SCRIPTS     = array('bandeja.js');
require dirname(__FILE__) . '/_cabecera.php';
$inputCls = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
?>
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
  <div>
    <h1 class="text-xl font-bold text-slate-900">Solicitudes de gasto</h1>
    <p class="text-sm text-slate-500">Cotizaciones, anticipos y facturas con su flujo de autorización.</p>
  </div>
  <button id="btnNueva" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
    + Nueva solicitud
  </button>
</div>

<!-- Tarjetas de resumen -->
<p id="alcanceTarjetas" class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500"></p>
<div id="tarjetas" class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7"></div>

<!-- Pestañas por vista -->
<div id="tabs" class="mb-4 flex flex-wrap gap-1 border-b border-slate-200 text-sm font-semibold">
  <button data-vista="pendientes" class="tab -mb-px border-b-2 px-4 py-2">Por atender</button>
  <button data-vista="mias" class="tab -mb-px border-b-2 px-4 py-2">Mis solicitudes</button>
  <?php if ($GAS_ADMIN) { ?><button data-vista="historial" class="tab -mb-px border-b-2 px-4 py-2">Historial</button><?php } ?>
</div>

<!-- Historial: solo consulta al pulsar el botón -->
<form id="barraHistorial" class="mb-4 hidden flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
  <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Desde</label>
    <input type="date" name="desde" required class="<?php echo $inputCls; ?>"></div>
  <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Hasta</label>
    <input type="date" name="hasta" required class="<?php echo $inputCls; ?>"></div>
  <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Consultar</button>
  <button type="button" id="btnExcel" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">⬇ Descargar Excel</button>
</form>

<!-- Filtros -->
<form id="filtros" class="mb-4 grid gap-3 sm:grid-cols-5">
  <input name="q" placeholder="Buscar por código, NIT, tercero, preliminar o paso" class="sm:col-span-2 <?php echo $inputCls; ?>">
  <select name="paso" id="filtroPaso" class="<?php echo $inputCls; ?>">
    <option value="">Cualquier paso actual</option>
  </select>
  <select name="tipo" class="<?php echo $inputCls; ?>">
    <option value="">Todos los tipos</option>
    <option value="COTIZACION">Cotización</option>
    <option value="ANTICIPO">Anticipo</option>
    <option value="FACTURA">Factura</option>
  </select>
  <select name="estado" class="<?php echo $inputCls; ?>">
    <option value="">Todos los estados</option>
    <option value="EN_CURSO">En curso</option>
    <option value="FINALIZADA">Finalizada</option>
    <option value="RECHAZADA">Rechazada</option>
    <option value="CANCELADA">Cancelada</option>
  </select>
</form>

<div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
  <table class="min-w-full text-sm">
    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
      <tr>
        <th class="px-4 py-3">Código</th>
        <th class="px-4 py-3">Tercero</th>
        <th class="px-4 py-3">Proceso</th>
        <th class="px-4 py-3 text-right">Valor</th>
        <th class="px-4 py-3">Estado</th>
        <th class="px-4 py-3">Paso actual</th>
        <th class="px-4 py-3">Creada</th>
      </tr>
    </thead>
    <tbody id="filas" class="divide-y divide-slate-100"></tbody>
  </table>
  <p id="vacio" class="hidden p-8 text-center text-sm text-slate-500">No hay solicitudes para mostrar.</p>
</div>

<div id="paginacion" class="mt-4 flex items-center justify-between text-sm text-slate-600">
  <span id="resumen"></span>
  <div class="flex gap-2">
    <button id="prev" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 disabled:opacity-40">← Anterior</button>
    <button id="next" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 disabled:opacity-40">Siguiente →</button>
  </div>
</div>
<?php require dirname(__FILE__) . '/_pie.php'; ?>
