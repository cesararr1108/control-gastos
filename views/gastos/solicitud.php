<?php
$GAS_TITULO      = 'Solicitud';
$GAS_MENU_ACTIVO = 'bandeja';
$GAS_SCRIPTS     = array('pasos.js', 'solicitud.js');
require dirname(__FILE__) . '/_cabecera.php';
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
?>
<a href="index.php" class="mb-4 inline-block text-sm text-slate-500 hover:text-indigo-700">← Volver a solicitudes</a>
<div id="app" data-id="<?php echo $id; ?>">
  <p class="py-10 text-center text-sm text-slate-500">Cargando solicitud…</p>
</div>
<?php require dirname(__FILE__) . '/_pie.php'; ?>
