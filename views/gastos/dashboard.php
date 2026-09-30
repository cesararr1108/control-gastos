<?php
$GAS_TITULO      = 'Dashboard';
$GAS_MENU_ACTIVO = 'dashboard';
$GAS_SCRIPTS     = array('dashboard.js');
require dirname(__FILE__) . '/_cabecera.php';
if (!$GAS_ADMIN) {
    echo '<div class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">No tienes permiso para ver el dashboard.</div>';
    require dirname(__FILE__) . '/_pie.php';
    exit;
}
$inputCls = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500';
?>
<div class="mb-5">
  <h1 class="text-xl font-bold text-slate-900">Dashboard de gastos</h1>
  <p class="text-sm text-slate-500">Cantidad y valor de las solicitudes por tipo y por rol del solicitante. Valor = valor del preliminar o, si aún no hay, el del anticipo.</p>
</div>

<!-- Filtros en una fila, arriba de las gráficas -->
<form id="filtrosDash" class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
  <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Desde</label>
    <input type="date" name="desde" required class="<?php echo $inputCls; ?>"></div>
  <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Hasta</label>
    <input type="date" name="hasta" required class="<?php echo $inputCls; ?>"></div>
  <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Organización</label>
    <select name="org" id="dashOrg" class="<?php echo $inputCls; ?>"><option value="">Todas</option></select></div>
  <div><label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Estado</label>
    <select name="estado" class="<?php echo $inputCls; ?>">
      <option value="">Todos</option><option value="EN_CURSO">En curso</option><option value="FINALIZADA">Finalizada</option>
      <option value="RECHAZADA">Rechazada</option><option value="CANCELADA">Cancelada</option>
    </select></div>
  <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Consultar</button>
</form>

<div id="dash" class="space-y-6">
  <p class="py-10 text-center text-sm text-slate-500">Cargando…</p>
</div>
<?php require dirname(__FILE__) . '/_pie.php'; ?>
