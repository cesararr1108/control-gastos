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
$legEmp = array('REQUIERE_ANTICIPO' => 1, 'TIPO_ANTICIPO' => 'VIATICOS', 'SALDO_LEGALIZACION' => '-15000.00');
$legEmpr = array('REQUIERE_ANTICIPO' => 1, 'TIPO_ANTICIPO' => 'VIATICOS', 'SALDO_LEGALIZACION' => '15000.00');
igual(true,  GasCatalogo::cumpleCondicion('SALDO_EMPLEADO', $legEmp), 'SALDO_EMPLEADO con saldo a favor del empleado');
igual(false, GasCatalogo::cumpleCondicion('SALDO_EMPLEADO', $legEmpr), 'SALDO_EMPLEADO con saldo a favor de la empresa');
igual(false, GasCatalogo::cumpleCondicion('SALDO_EMPLEADO', array('REQUIERE_ANTICIPO' => 1, 'TIPO_ANTICIPO' => 'VIATICOS', 'SALDO_LEGALIZACION' => '0.00')), 'SALDO_EMPLEADO cuadrado');
igual(false, GasCatalogo::cumpleCondicion('SALDO_EMPLEADO', $antViat), 'SALDO_EMPLEADO sin legalizar');
igual(false, GasCatalogo::cumpleCondicion('SALDO_EMPLEADO', array('REQUIERE_ANTICIPO' => 1, 'TIPO_ANTICIPO' => 'FACTURA', 'SALDO_LEGALIZACION' => '-5')), 'SALDO_EMPLEADO solo en viáticos');

echo "Catálogo\n";
$tipos = array_keys(GasCatalogo::tipos());
foreach (GasCatalogo::acciones() as $k => $a) {
    $metodo = 'h' . str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', $k))));
    require_once $base . 'GasMotor.php';
    igual(true, method_exists('GasMotor', $metodo), "acción $k tiene manejador $metodo");
    igual(true, count(array_diff($a['tipos'], $tipos)) === 0, "acción $k usa tipos válidos");
}

echo "Número de contabilización (19 o 17 + máx. 10 dígitos) y fondos\n";
$m = new ReflectionMethod('GasMotor', 'numeroContabilizacion');
$m->setAccessible(true);
$valida = function ($n) use ($m) {
    try { $m->invoke(null, array('num_contabilizacion' => $n)); return true; } catch (Exception $e) { return false; }
};
igual(true,  $valida('19'), 'contabilización «19»');
igual(true,  $valida('17'), 'contabilización «17»');
igual(true,  $valida('1900000001'), 'contabilización de 10 dígitos con 19');
igual(true,  $valida('1700000001'), 'contabilización de 10 dígitos con 17');
igual(false, $valida('19000000012'), 'contabilización de 11 dígitos');
igual(false, $valida('17000000012'), 'contabilización de 11 dígitos con 17');
igual(false, $valida('2000000001'), 'contabilización que no empieza por 19 ni 17');
igual(false, $valida('1800000001'), 'contabilización que empieza por 18');
igual(false, $valida('19A00'), 'contabilización con letras');
igual(false, $valida(''), 'contabilización vacía');
igual(array('ROMA', 'FONDO', 'NOTA', 'TARJETA'), array_keys(GasCatalogo::fondos()), 'fondos: Roma, Fondo, Nota, Tarjeta');
igual(array('FONDO', 'NOTA'), GasCatalogo::fondosConNumero(), 'Fondo y Nota piden número');

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

echo "Tarjeta (últimos 4 dígitos, titular y banco)\n";
$tarjeta = function ($in) { try { return GasCatalogo::validarTarjeta($in); } catch (GasError $e) { return $e->getMessage(); } };
igual(array('1234', 'Ana Pérez', 'Bancolombia'), $tarjeta(array('tarjeta_ultimos4' => ' 1234 ', 'tarjeta_titular' => 'Ana Pérez', 'tarjeta_banco' => 'Bancolombia')), 'tarjeta válida');
igual('Tarjeta: escribe los últimos 4 dígitos (solo números).', $tarjeta(array('tarjeta_ultimos4' => '123', 'tarjeta_titular' => 'A', 'tarjeta_banco' => 'B')), 'tarjeta con 3 dígitos');
igual('Tarjeta: escribe los últimos 4 dígitos (solo números).', $tarjeta(array('tarjeta_ultimos4' => '12a4', 'tarjeta_titular' => 'A', 'tarjeta_banco' => 'B')), 'tarjeta con letras');
igual('Tarjeta: indica el titular.', $tarjeta(array('tarjeta_ultimos4' => '1234', 'tarjeta_banco' => 'B')), 'tarjeta sin titular');
igual('Tarjeta: indica el banco.', $tarjeta(array('tarjeta_ultimos4' => '1234', 'tarjeta_titular' => 'A')), 'tarjeta sin banco');

echo "Reparto por centros de costo\n";
$existe = function ($cc) { return in_array($cc, array('CC100', 'CC200', 'CC300'), true); };
$centros = function ($filas, $total) use ($existe) { try { return GasCatalogo::validarCentros($filas, $total, $existe); } catch (GasError $e) { return $e->getMessage(); } };
igual(array(array('centro' => 'CC100', 'valor' => 1000000.0)), $centros(array(array('centro' => 'CC100', 'valor' => '1.000.000')), 1000000), 'un centro por el total');
igual(array(array('centro' => 'CC100', 'valor' => 600000.0), array('centro' => 'CC200', 'valor' => 400000.0)),
    $centros(array(array('centro' => 'CC100', 'valor' => '600000'), array('centro' => '', 'valor' => ''), array('centro' => 'CC200', 'valor' => '400.000')), 1000000),
    'dos centros que suman el total (fila vacía ignorada)');
igual(true, strpos($centros(array(array('centro' => 'CC100', 'valor' => '600000'), array('centro' => 'CC200', 'valor' => '300000')), 1000000), 'debe ser igual al valor de la factura') !== false, 'suma menor al total');
igual(true, strpos($centros(array(array('centro' => 'CC100', 'valor' => '600000'), array('centro' => 'CC100', 'valor' => '400000')), 1000000), 'repetido') !== false, 'centro repetido');
igual(true, strpos($centros(array(array('centro' => 'CC999', 'valor' => '1000000')), 1000000), 'no existe') !== false, 'centro inexistente');
igual(true, strpos($centros(array(array('centro' => '', 'valor' => '')), 0), 'al menos un centro') !== false, 'sin filas');
igual(true, strpos($centros(array(array('centro' => '', 'valor' => '5000')), 5000), 'selecciónalo') !== false, 'valor sin centro');
igual(true, strpos($centros(array(array('centro' => 'CC100', 'valor' => '1000'), array('centro' => 'CC200', 'valor' => '0')), 1000), 'mayor que cero') !== false, 'centro sin valor');
igual(true, is_array($centros(array(array('centro' => 'CC100', 'valor' => '333,33'), array('centro' => 'CC200', 'valor' => '333,33'), array('centro' => 'CC300', 'valor' => '333,34')), 1000)), 'decimales que cuadran');

echo "Códigos de formatos de viáticos por organización\n";
igual(array('solicitud' => 'F-FR-0137', 'legalizacion' => 'F-FR-0138'), GasConfig::formatosViaticos('1000'), 'org. 1000: F-FR-0137 / F-FR-0138');
igual(array('solicitud' => 'F-FR-023', 'legalizacion' => 'F-FR-024'), GasConfig::formatosViaticos('2000'), 'org. 2000: F-FR-023 / F-FR-024');
igual(array('solicitud' => 'F-FR-023', 'legalizacion' => 'F-FR-024'), GasConfig::formatosViaticos('3000'), 'otra org.: los de la 2000');
igual(array('solicitud' => 'F-FR-0137', 'legalizacion' => 'F-FR-0138'), GasConfig::formatosViaticos('1000 '), 'org. con espacios (char(4))');
igual(true, array_key_exists('SALDO_EMPLEADO', GasCatalogo::condiciones()), 'condición SALDO_EMPLEADO en el catálogo');
igual(array('ANTICIPO'), GasCatalogo::acciones()['PAGAR_SALDO']['tipos'], 'PAGAR_SALDO solo en flujos de anticipo');

echo $fallos ? "\n$fallos prueba(s) fallaron\n" : "\nTodo bien\n";
exit($fallos ? 1 : 0);
