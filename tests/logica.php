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

echo "Número de contabilización (19 + máx. 10 dígitos) y fondos\n";
$m = new ReflectionMethod('GasMotor', 'numeroContabilizacion');
$m->setAccessible(true);
$valida = function ($n) use ($m) {
    try { $m->invoke(null, array('num_contabilizacion' => $n)); return true; } catch (Exception $e) { return false; }
};
igual(true,  $valida('19'), 'contabilización «19»');
igual(true,  $valida('1900000001'), 'contabilización de 10 dígitos');
igual(false, $valida('19000000012'), 'contabilización de 11 dígitos');
igual(false, $valida('2000000001'), 'contabilización que no empieza por 19');
igual(false, $valida('19A00'), 'contabilización con letras');
igual(false, $valida(''), 'contabilización vacía');
igual(array('ROMA', 'FONDO', 'NOTA', 'TARJETA'), array_keys(GasCatalogo::fondos()), 'fondos: Roma, Fondo, Nota, Tarjeta de crédito');
igual(array('FONDO', 'NOTA', 'TARJETA'), GasCatalogo::fondosConNumero(), 'Fondo, Nota y Tarjeta piden número');
igual('**** 1234', GasCatalogo::enmascararTarjeta('4111 1111 1111 1234'), 'tarjeta: solo se guardan los últimos 4');
igual('**** 9876', GasCatalogo::enmascararTarjeta('9876'), 'tarjeta: basta con los últimos 4');
igual(null, GasCatalogo::enmascararTarjeta('12a4'), 'tarjeta con letras');
igual(null, GasCatalogo::enmascararTarjeta('123'), 'tarjeta con menos de 4 dígitos');

echo "Archivos del FTP\n";
require_once $base . 'GasArchivos.php';
$ftp = function ($ruta, $nombre, $grupo, $tamano = 1000) {
    try {
        $g = GasArchivos::desdeFtp(array('ruta' => $ruta, 'nombre' => $nombre, 'tamano' => $tamano), 'Soporte', $grupo);
        return $g['ruta'];
    } catch (GasError $e) {
        return false;
    }
};
igual('web/gastos/gas_abc.pdf', $ftp('/web/gastos/gas_abc.pdf', 'factura.pdf', 'pdf'), 'FTP: PDF en la carpeta del módulo');
igual('web/gastos/gas_abc.xlsx', $ftp('web/gastos/gas_abc.xlsx', 'cuadro.xlsx', 'documento'), 'FTP: Excel como documento');
igual(false, $ftp('/web/gastos/gas_abc.xlsx', 'cuadro.xlsx', 'pdf'), 'FTP: Excel donde solo va PDF');
igual('web/gastos/gas_abc.jpg', $ftp('/web/gastos/gas_abc.jpg', 'foto.jpg', 'imagen'), 'FTP: foto como imagen');
igual(false, $ftp('/web/otra/gas_abc.pdf', 'factura.pdf', 'pdf'), 'FTP: carpeta ajena');
igual(false, $ftp('/web/gastos/../x.pdf', 'factura.pdf', 'pdf'), 'FTP: ruta con ..');
igual(false, $ftp('/web/gastos/sub/x.pdf', 'factura.pdf', 'pdf'), 'FTP: subcarpeta');
igual(false, $ftp('/web/gastos/gas_abc.php', 'factura.pdf', 'pdf'), 'FTP: archivo con extensión no permitida');
igual(false, $ftp('/web/gastos/gas_abc.pdf', 'factura.pdf', 'pdf', 50 * 1024 * 1024), 'FTP: supera el tamaño máximo');
igual(true, GasArchivos::esFtp('web/gastos/gas_abc.pdf'), 'esFtp con carpeta');
igual(false, GasArchivos::esFtp('a1b2c3.pdf'), 'archivo local antiguo');

echo "Áreas del dashboard\n";
$acc = array();
foreach (GasCatalogo::areasRespuesta() as $a) {
    $acc = array_merge($acc, $a['acciones']);
}
igual(array(), array_diff($acc, array_keys(GasCatalogo::acciones())), 'las acciones de las áreas existen');

echo $fallos ? "\n$fallos prueba(s) fallaron\n" : "\nTodo bien\n";
exit($fallos ? 1 : 0);
