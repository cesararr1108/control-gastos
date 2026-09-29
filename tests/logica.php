<?php
/**
 * Pruebas de la lógica que no necesita base de datos.
 * Ejecutar:  php tests/logica.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$base = dirname(dirname(__FILE__)) . '/models/gastos/';
foreach (array('GasError', 'GasConfig', 'GasDb', 'GasCatalogo', 'GasSesion') as $c) {
    require_once $base . $c . '.php';
}

$fallos = 0;
function igual($esperado, $real, $nombre)
{
    global $fallos;
    if ($esperado === $real) {
        echo "  ok   $nombre\n";
    } else {
        $fallos++;
        echo "  FALLA $nombre\n        esperado: " . var_export($esperado, true) . "\n        real:     " . var_export($real, true) . "\n";
    }
}

echo "GasDb (escapes)\n";
igual("'O''Brien'", GasDb::str("O'Brien"), 'comilla simple duplicada');
igual('NULL', GasDb::str('   '), 'vacío -> NULL');
igual("''", GasDb::lit(''), 'lit conserva vacío');
igual('NULL', GasDb::int('12; DROP TABLE x'), 'int rechaza texto con inyección');
igual('12', GasDb::int('12'), 'int válido');
igual('NULL', GasDb::int('abc'), 'int no numérico -> NULL');
igual('1250000.00', GasDb::num('1.250.000'), 'num con puntos de miles');
igual('1250000.50', GasDb::num('1.250.000,50'), 'num con coma decimal');
igual('1234.50', GasDb::num('1234.5'), 'num decimal con punto');
igual('150000.00', GasDb::num('150.000'), 'num 150.000 = ciento cincuenta mil');
igual('1250.00', GasDb::num('1.250'), 'num 1.250 = mil doscientos cincuenta');
igual("'2026-09-29'", GasDb::fecha('2026-09-29'), 'fecha válida');
igual('NULL', GasDb::fecha('2026-02-31'), 'fecha inexistente');
igual('NULL', GasDb::fecha("2026-09-29'; --"), 'fecha con inyección');

echo "GasCatalogo::cumpleCondicion\n";
$sinAnt   = array('REQUIERE_ANTICIPO' => 0, 'TIPO_ANTICIPO' => null);
$antCot   = array('REQUIERE_ANTICIPO' => 1, 'TIPO_ANTICIPO' => 'COTIZACION');
$antFact  = array('REQUIERE_ANTICIPO' => 1, 'TIPO_ANTICIPO' => 'FACTURA');
$antViat  = array('REQUIERE_ANTICIPO' => 1, 'TIPO_ANTICIPO' => 'VIATICOS');
igual(true,  GasCatalogo::cumpleCondicion('SIEMPRE', $sinAnt), 'SIEMPRE');
igual(false, GasCatalogo::cumpleCondicion('ANTICIPO_SI', $sinAnt), 'ANTICIPO_SI sin anticipo');
igual(true,  GasCatalogo::cumpleCondicion('ANTICIPO_SI', $antCot), 'ANTICIPO_SI con anticipo');
igual(true,  GasCatalogo::cumpleCondicion('ANTICIPO_NO', $sinAnt), 'ANTICIPO_NO sin anticipo');
igual(false, GasCatalogo::cumpleCondicion('ANTICIPO_NO', $antCot), 'ANTICIPO_NO con anticipo');
igual(true,  GasCatalogo::cumpleCondicion('ANTICIPO_FACTURA', $antFact), 'ANTICIPO_FACTURA');
igual(false, GasCatalogo::cumpleCondicion('ANTICIPO_FACTURA', $antViat), 'ANTICIPO_FACTURA con viáticos');
igual(true,  GasCatalogo::cumpleCondicion('ANTICIPO_VIATICOS', $antViat), 'ANTICIPO_VIATICOS');
igual(true,  GasCatalogo::cumpleCondicion('ANTICIPO_COTIZACION', $antCot), 'ANTICIPO_COTIZACION');
igual(false, GasCatalogo::cumpleCondicion('ANTICIPO_SI', array('REQUIERE_ANTICIPO' => null)), 'ANTICIPO_SI indefinido');

echo "Catálogo\n";
$tipos = array_keys(GasCatalogo::tipos());
foreach (GasCatalogo::acciones() as $k => $a) {
    $metodo = 'h' . str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', $k))));
    require_once $base . 'GasMotor.php';
    igual(true, method_exists('GasMotor', $metodo), "acción $k tiene manejador $metodo");
    igual(true, count(array_diff($a['tipos'], $tipos)) === 0, "acción $k usa tipos válidos");
}

echo $fallos ? "\n$fallos prueba(s) fallaron\n" : "\nTodo bien\n";
exit($fallos ? 1 : 0);
