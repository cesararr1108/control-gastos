<?php
/**
 * Encabezado HTML + menú del módulo.
 * Antes de incluirlo define $GAS_TITULO y, opcionalmente, $GAS_MENU_ACTIVO (bandeja|flujos).
 */
require_once dirname(__FILE__) . '/_inicio.php';
$GAS_TITULO      = isset($GAS_TITULO) ? $GAS_TITULO : 'Control de gastos';
$GAS_MENU_ACTIVO = isset($GAS_MENU_ACTIVO) ? $GAS_MENU_ACTIVO : '';

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
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
  <header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
      <a href="index.php" class="text-base font-bold text-slate-900">Control de gastos</a>
      <nav class="flex items-center gap-1">
        <?php echo gasMenuLink('index.php', 'Solicitudes <span id="badgePendientes" class="ml-1 hidden rounded-full bg-rose-600 px-2 py-0.5 text-xs font-bold text-white"></span>', 'bandeja', $GAS_MENU_ACTIVO); ?>
        <?php if ($GAS_ADMIN) echo gasMenuLink('flujos.php', 'Flujos', 'flujos', $GAS_MENU_ACTIVO); ?>
      </nav>
      <span class="text-sm text-slate-500"><?php echo htmlspecialchars($GAS_USR['nombre'], ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  </header>
  <main class="mx-auto max-w-6xl px-4 py-6">
