<?php
/**
 * Encabezado HTML + menú del módulo.
 * Antes de incluirlo define $GAS_TITULO y, opcionalmente, $GAS_MENU_ACTIVO (bandeja|dashboard|flujos).
 *
 * Tema por organización (GasConfig::$TEMAS): se reemplaza la paleta "indigo" de Tailwind, que es la
 * que usan botones, pestañas, enlaces y resaltados, por la del tema. El fondo y el encabezado no cambian.
 */
require_once dirname(__FILE__) . '/_inicio.php';
$GAS_TITULO      = isset($GAS_TITULO) ? $GAS_TITULO : 'Control de gastos';
$GAS_MENU_ACTIVO = isset($GAS_MENU_ACTIVO) ? $GAS_MENU_ACTIVO : '';

// Paletas: los tonos 500-700 son oscuros para que el texto blanco de los botones se lea bien.
$GAS_PALETAS = array(
    'amarillo' => array('50' => '#fffbeb', '100' => '#fef3c7', '200' => '#fde68a', '300' => '#fcd34d', '400' => '#fbbf24',
                        '500' => '#d97706', '600' => '#b45309', '700' => '#92400e', '800' => '#78350f', '900' => '#451a03'),
    // Basado en #279ca2 (tono pedido): se usa tal cual en acentos y barras (500); botones y textos
    // van un paso más oscuro (600/700) para que el texto se lea bien (contraste >= 4.5:1).
    'turquesa' => array('50' => '#effbfb', '100' => '#d4f1f2', '200' => '#a9e2e5', '300' => '#72cdd2', '400' => '#45b6bc',
                        '500' => '#279ca2', '600' => '#1f8187', '700' => '#1b7277', '800' => '#185458', '900' => '#123e41'),
);
$GAS_PALETA = isset($GAS_PALETAS[$GAS_TEMA]) ? $GAS_PALETAS[$GAS_TEMA] : null;

function gasMenuLink($href, $texto, $clave, $activo)
{
    $cls = $clave === $activo ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600 hover:bg-slate-100';
    return '<a href="' . $href . '" class="rounded-lg px-3 py-2 text-sm font-semibold ' . $cls . '">' . $texto . '</a>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($GAS_TITULO, ENT_QUOTES, 'UTF-8'); ?> · Control de gastos</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <?php if ($GAS_PALETA) { ?>
  <script>tailwind.config = { theme: { extend: { colors: { indigo: <?php echo json_encode($GAS_PALETA); ?> } } } };</script>
  <?php } ?>
  <script>window.GASTOS_COLOR = '<?php echo $GAS_PALETA ? $GAS_PALETA['600'] : '#4f46e5'; ?>';</script>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
  <header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
      <a href="index.php" class="text-base font-bold text-slate-900">Control de gastos
        <?php if ($GAS_USR['org'] !== '') { ?><span class="ml-2 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700">Org. <?php echo htmlspecialchars($GAS_USR['org'], ENT_QUOTES, 'UTF-8'); ?></span><?php } ?>
      </a>
      <nav class="flex items-center gap-1">
        <?php echo gasMenuLink('index.php', 'Solicitudes <span id="badgePendientes" class="ml-1 hidden rounded-full bg-rose-600 px-2 py-0.5 text-xs font-bold text-white"></span>', 'bandeja', $GAS_MENU_ACTIVO); ?>
        <?php if ($GAS_ADMIN) echo gasMenuLink('dashboard.php', 'Dashboard', 'dashboard', $GAS_MENU_ACTIVO); ?>
        <?php if ($GAS_ADMIN) echo gasMenuLink('flujos.php', 'Flujos', 'flujos', $GAS_MENU_ACTIVO); ?>
      </nav>
      <span class="text-sm text-slate-600"><?php echo htmlspecialchars($GAS_USR['nombre'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  </header>
  <main class="mx-auto max-w-6xl px-4 py-6">
