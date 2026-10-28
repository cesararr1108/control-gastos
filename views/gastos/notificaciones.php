<?php
$GAS_TITULO      = 'Notificaciones';
$GAS_MENU_ACTIVO = 'notificaciones';
$GAS_SCRIPTS     = array('notificaciones.js');
require dirname(__FILE__) . '/_cabecera.php';
if (!$GAS_ADMIN) {
    echo '<div class="rounded-xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">No tienes permiso para ver esta página.</div>';
    require dirname(__FILE__) . '/_pie.php';
    exit;
}
?>
<div class="mb-5">
  <h1 class="text-xl font-bold text-slate-900">Diagnóstico de notificaciones push</h1>
  <p class="text-sm text-slate-500">Revisa la configuración de GasConfig, la conexión con la API de notificaciones y envía una notificación de prueba a tu propio correo.</p>
</div>
<div id="diag" class="space-y-6"><p class="py-10 text-center text-sm text-slate-500">Revisando…</p></div>
<?php require dirname(__FILE__) . '/_pie.php'; ?>
