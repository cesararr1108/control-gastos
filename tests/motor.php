<?php
/**
 * Pruebas del motor de flujos (GasMotor) sin SQL Server: se usa una base de datos falsa en memoria
 * que entiende los SQL que genera el motor (SELECT de la solicitud y de sus pasos, UPDATE de
 * columnas, INSERT de archivos y centros de costo) y archivos subidos simulados.
 * Ejecutar:  php tests/motor.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$base = dirname(dirname(__FILE__)) . '/models/gastos/';
foreach (array('GasError', 'GasConfig', 'GasCatalogo', 'GasSesion') as $c) {
    require_once $base . $c . '.php';
}
$_SESSION = array();

// Las notificaciones push no se prueban aquí (necesitan red y base de datos): se sustituyen por un stub.
if (!class_exists('GasNotificaciones', false)) {
    class GasNotificaciones
    {
        public static function solicitud($solId, $usuarioId, $tipo)
        {
        }
    }
}

/* ------------------------------------------------------------------------------------------
   GasDb falso: hereda los escapes reales (str, num, fecha...) y reemplaza las consultas.
   ------------------------------------------------------------------------------------------ */
eval('?>' . str_replace('class GasDb', 'class GasDbReal', file_get_contents($base . 'GasDb.php')));

class GasDb extends GasDbReal
{
    public static $sol = array();      // fila de T_GAS_SOLICITUDES
    public static $pasos = array();    // filas de T_GAS_SOLICITUD_PASOS (ID => fila)
    public static $archivos = array(); // filas de T_GAS_ARCHIVOS
    public static $centros = array();  // filas de T_GAS_CENTROS_SOLICITUD
    public static $legal = array();    // filas de T_GAS_LEGALIZACION_DETALLE
    public static $centrosCatalogo = array('CC100', 'CC200', 'CC300');
    public static $log = array();
    private static $snapshot = null;

    public static function begin()    { self::$snapshot = array(self::$sol, self::$pasos, self::$archivos, self::$centros); }
    public static function commit()   { self::$snapshot = null; }
    public static function rollback() { if (self::$snapshot) { list(self::$sol, self::$pasos, self::$archivos, self::$centros) = self::$snapshot; } }

    /** Valor SQL literal -> PHP. */
    private static function valor($v)
    {
        $v = trim($v);
        if (strtoupper($v) === 'NULL') {
            return null;
        }
        if (strtoupper($v) === 'GETDATE()') {
            return date('Y-m-d H:i:s');
        }
        if ($v !== '' && $v[0] === "'") {
            return utf8_encode(str_replace("''", "'", substr($v, 1, -1)));
        }
        return $v;
    }

    /** Separa por comas que no estén dentro de comillas ni paréntesis. */
    private static function partir($s)
    {
        $out = array(); $cur = ''; $q = false; $p = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if ($ch === "'") { $q = !$q; }
            if (!$q && $ch === '(') { $p++; }
            if (!$q && $ch === ')') { $p--; }
            if (!$q && $p === 0 && $ch === ',') { $out[] = $cur; $cur = ''; continue; }
            $cur .= $ch;
        }
        $out[] = $cur;
        return $out;
    }

    private static function asignaciones($set)
    {
        $a = array();
        foreach (self::partir($set) as $par) {
            $i = strpos($par, '=');
            $a[trim(substr($par, 0, $i))] = self::valor(substr($par, $i + 1));
        }
        return $a;
    }

    public static function query($sql)
    {
        self::$log[] = $sql;
        $sql1 = preg_replace('/\s+/', ' ', trim($sql));
        if (preg_match('/^UPDATE T_GAS_SOLICITUDES SET (.*) WHERE ID = (\d+)$/s', $sql1, $m)) {
            foreach (self::asignaciones($m[1]) as $k => $v) {
                self::$sol[$k] = $v;
            }
            return true;
        }
        if (preg_match('/^UPDATE T_GAS_SOLICITUD_PASOS SET (.*) WHERE ID = (\d+)$/s', $sql1, $m)) {
            foreach (self::asignaciones($m[1]) as $k => $v) {
                self::$pasos[(int) $m[2]][$k] = $v;
            }
            return true;
        }
        // Devolución: los pasos entre el destino y el actual vuelven a PENDIENTE.
        if (preg_match('/^UPDATE T_GAS_SOLICITUD_PASOS SET (.*) WHERE SOLICITUD_ID = \d+ AND ORDEN > (\d+) AND ORDEN <= (\d+)$/s', $sql1, $m)) {
            foreach (self::$pasos as $id => $p) {
                if ($p['ORDEN'] > (int) $m[2] && $p['ORDEN'] <= (int) $m[3]) {
                    foreach (self::asignaciones($m[1]) as $k => $v) {
                        self::$pasos[$id][$k] = $v;
                    }
                }
            }
            return true;
        }
        if (preg_match("/^DELETE FROM T_GAS_CENTROS_SOLICITUD WHERE SOLICITUD_ID = \d+ AND ETAPA = '(\w+)'/", $sql1, $m)) {
            self::$centros = array_values(array_filter(self::$centros, function ($c) use ($m) { return $c['ETAPA'] !== $m[1]; }));
            return true;
        }
        if (preg_match('/^INSERT INTO T_GAS_CENTROS_SOLICITUD \(SOLICITUD_ID, CENTRO_COSTO, VALOR, ETAPA\) VALUES \((.*)\)$/', $sql1, $m)) {
            $v = self::partir($m[1]);
            self::$centros[] = array('CENTRO' => self::valor($v[1]), 'VALOR' => (float) self::valor($v[2]), 'ETAPA' => self::valor($v[3]));
            return true;
        }
        if (preg_match('/^INSERT INTO T_GAS_LEGALIZACION_DETALLE \(.*\) VALUES \((.*)\)$/', $sql1, $m)) {
            $v = self::partir($m[1]);
            self::$legal[] = array('DETALLE' => self::valor($v[4]), 'TIPO_GASTO' => self::valor($v[5]), 'ARCHIVO_ID' => (int) $v[8]);
            return true;
        }
        if (preg_match('/^DELETE FROM T_GAS_LEGALIZACION_DETALLE/', $sql1)) {
            self::$legal = array();
            return true;
        }
        if (preg_match('/^(DELETE|INSERT|UPDATE) /', $sql1)) {
            return true; // detalle de viáticos / legalización, eventos...: no se necesitan en estas pruebas
        }
        throw new Exception('SQL no soportado por la BD falsa: ' . $sql1);
    }

    public static function all($sql)
    {
        $sql1 = preg_replace('/\s+/', ' ', trim($sql));
        if (strpos($sql1, 'FROM T_GAS_SOLICITUDES s') !== false) {
            return array(self::$sol);
        }
        if (preg_match("/FROM T_GAS_SOLICITUD_PASOS WHERE SOLICITUD_ID = \d+ AND ESTADO = 'ACTUAL'/", $sql1)) {
            foreach (self::$pasos as $p) {
                if ($p['ESTADO'] === 'ACTUAL') {
                    return array($p);
                }
            }
            return array();
        }
        if (preg_match("/FROM T_GAS_SOLICITUD_PASOS WHERE SOLICITUD_ID = \d+ AND ESTADO = 'PENDIENTE' AND ORDEN > (\d+)/", $sql1, $m)) {
            $out = array();
            foreach (self::$pasos as $p) {
                if ($p['ESTADO'] === 'PENDIENTE' && $p['ORDEN'] > (int) $m[1]) {
                    $out[] = $p;
                }
            }
            usort($out, function ($a, $b) { return $a['ORDEN'] - $b['ORDEN']; });
            return $out;
        }
        if (preg_match("/FROM T_GAS_SOLICITUD_PASOS WHERE SOLICITUD_ID = \d+ AND ORDEN < (\d+) AND ESTADO = 'COMPLETADO' AND RESPONSABLE_TIPO = 'SOLICITANTE'/", $sql1, $m)) {
            $dest = null;
            foreach (self::$pasos as $p) {
                if ($p['ORDEN'] < (int) $m[1] && $p['ESTADO'] === 'COMPLETADO' && $p['RESPONSABLE_TIPO'] === 'SOLICITANTE' && (!$dest || $p['ORDEN'] > $dest['ORDEN'])) {
                    $dest = $p;
                }
            }
            return $dest ? array($dest) : array();
        }
        if (preg_match("/FROM T_GAS_SOLICITUD_PASOS WHERE SOLICITUD_ID = \d+ AND ESTADO = 'PENDIENTE' AND ACCION = 'MONTAR_PRELIMINAR' AND RESPONSABLE_TIPO = 'SOLICITANTE' AND ORDEN > (\d+)/", $sql1, $m)) {
            foreach (self::$pasos as $p) {
                if ($p['ESTADO'] === 'PENDIENTE' && $p['ACCION'] === 'MONTAR_PRELIMINAR' && $p['RESPONSABLE_TIPO'] === 'SOLICITANTE' && $p['ORDEN'] > (int) $m[1]) {
                    return array($p);
                }
            }
            return array();
        }
        if (preg_match("/FROM T_GAS_ARCHIVOS WHERE ID = (\d+) AND SOLICITUD_ID = \d+ AND CATEGORIA = '(\w+)'/", $sql1, $m)) {
            $a = isset(self::$archivos[(int) $m[1] - 1]) ? self::$archivos[(int) $m[1] - 1] : null;
            return array(array('N' => $a && $a['CATEGORIA'] === $m[2] ? 1 : 0));
        }
        if (preg_match("/FROM T_GAS_ARCHIVOS WHERE SOLICITUD_ID = \d+ AND CATEGORIA = '(\w+)'/", $sql1, $m)) {
            $n = 0;
            foreach (self::$archivos as $a) {
                $n += $a['CATEGORIA'] === $m[1] ? 1 : 0;
            }
            return array(array('N' => $n));
        }
        if (preg_match("/FROM T_CENTROS_COSTOS WHERE LTRIM\(RTRIM\(CENTRO\)\) = '([^']*)'/", $sql1, $m)) {
            return array(array('N' => in_array($m[1], self::$centrosCatalogo, true) ? 1 : 0));
        }
        if (preg_match('/FROM T_CAL_PROCESOS WHERE ID = (\d+)/', $sql1, $m)) {
            return array(array('ID' => $m[1], 'PROCESO' => 'Proceso ' . $m[1]));
        }
        if (preg_match('/FROM T_ROLES WHERE ID = (\d+)/', $sql1, $m)) {
            return array(array('TITULO' => $m[1] === '0' ? 'AUXILIAR DE VENTAS' : 'OTRO ROL'));
        }
        if (preg_match('/^SET NOCOUNT ON; INSERT INTO T_GAS_ARCHIVOS \(.*\) VALUES \((.*)\); SELECT/', $sql1, $m)) {
            $v = self::partir($m[1]);
            self::$archivos[] = array('SOLICITUD_ID' => (int) $v[0], 'PASO_ORDEN' => (int) $v[1], 'CATEGORIA' => self::valor($v[2]), 'DESCRIPCION' => self::valor($v[7]));
            return array(array('id' => count(self::$archivos)));
        }
        throw new Exception('SELECT no soportado por la BD falsa: ' . $sql1);
    }

    // row/scalar/insert del padre llaman a self::all (el del padre): se redefinen para usar el falso.
    public static function row($sql)    { $r = static::all($sql); return $r ? $r[0] : null; }
    public static function scalar($sql) { $r = static::row($sql); return $r ? reset($r) : null; }
    public static function insert($sql) { $id = static::scalar('SET NOCOUNT ON; ' . $sql . '; SELECT CAST(SCOPE_IDENTITY() AS int) AS id'); return $id === null ? 0 : (int) $id; }
}

/* GasArchivos falso: no hay subidas reales (is_uploaded_file); se aceptan los archivos simulados. */
class GasArchivos
{
    private static function check($file, $etiqueta, $que)
    {
        if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw new GasError("Falta el $que: $etiqueta.");
        }
        return array('ruta' => bin2hex(openssl_random_pseudo_bytes(4)), 'nombre' => $file['name'], 'tamano' => 10);
    }
    public static function guardarPdf($file, $etiqueta)     { return self::check($file, $etiqueta, 'PDF'); }
    public static function guardarSoporte($file, $etiqueta) { return self::check($file, $etiqueta, 'archivo'); }
    public static function guardarDocumento($file, $etiqueta) { return self::check($file, $etiqueta, 'archivo'); }
    public static function desdeFtp($meta, $etiqueta, $grupo) { return array('ruta' => $meta['ruta'], 'nombre' => $meta['nombre'], 'tamano' => 10); }
    public static function recibir($in, $files, $campo, $etiqueta, $grupo, $obligatorio)
    {
        if (!empty($in['ftp'][$campo]['ruta'])) {
            return self::desdeFtp($in['ftp'][$campo], $etiqueta, $grupo);
        }
        $f = isset($files[$campo]) ? $files[$campo] : null;
        if (!$f || !isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            if ($obligatorio) {
                throw new GasError("Adjunta $etiqueta.");
            }
            return null;
        }
        return self::check($f, $etiqueta, 'archivo');
    }
    public static function borrar($rutas) {}
}

foreach (array('GasListasModel', 'GasSolicitudModel', 'GasMotor') as $c) {
    require_once $base . $c . '.php';
}

/* ------------------------------------------------------------------------------------------ */

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

/** Ejecuta el paso en curso; devuelve '' si pasó o el mensaje de error. */
function paso($u, $in, $files = array())
{
    try {
        GasMotor::ejecutar(1, $u, $in, $files);
        return '';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

function contiene($txt, $aguja) { return strpos($txt, $aguja) !== false; }
function archivo($n) { return array('name' => $n, 'type' => 'application/pdf', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10); }
function actual() { foreach (GasDb::$pasos as $p) { if ($p['ESTADO'] === 'ACTUAL') { return $p['ACCION'] . '#' . $p['ORDEN']; } } return null; }

/** Crea la solicitud falsa con los pasos dados: array(ACCION, RESPONSABLE_TIPO, ROL_ID, CONDICION). */
function nueva($tipo, $org, $pasos, $extra = array())
{
    GasDb::$sol = array_merge(array(
        'ID' => 1, 'TIPO' => $tipo, 'USUARIO_ID' => 10, 'ORGANIZACION_VENTA' => $org, 'ESTADO' => 'EN_CURSO',
        'REQUIERE_ANTICIPO' => $tipo === 'ANTICIPO' ? 1 : 0, 'TIPO_ANTICIPO' => null, 'REQUIERE_SOPORTE_PAGO' => null,
        'VALOR_ANTICIPO' => null, 'SALDO_LEGALIZACION' => null, 'NUM_CONTABILIZACION' => null, 'PROCESO_ID' => null,
        'DESCRIPCION' => null, 'CENTRO_COSTOS' => null,
    ), $extra);
    GasDb::$pasos = array();
    GasDb::$archivos = array();
    GasDb::$centros = array();
    GasDb::$legal = array();
    $i = 0;
    foreach ($pasos as $p) {
        $i++;
        GasDb::$pasos[$i] = array('ID' => $i, 'ORDEN' => $i, 'ACCION' => $p[0], 'NOMBRE' => $p[0], 'RESPONSABLE_TIPO' => $p[1],
            'ROL_ID' => $p[2], 'USUARIO_ID' => null, 'CONDICION' => $p[3], 'ESTADO' => 'PENDIENTE');
    }
    GasMotor::activarSiguiente(1, 0);
}

$SOL = array('id' => 10, 'rolId' => 0);
$GA = array('id' => 20, 'rolId' => 5);
$CONT = array('id' => 30, 'rolId' => 6);
$TES = array('id' => 40, 'rolId' => 7);

$tercero = array('tercero_nit' => '1020304050', 'tercero_nombre' => 'Ana Pérez', 'tercero_celular' => '3001234567',
    'tercero_email' => 'ana@example.com', 'centro_costos' => 'CC100', 'cargo' => 'Analista');
$hoy = date('Y-m-d');
$hoyTxt = date('d/m/Y');
$viaje = array_merge($tercero, array('tipo_anticipo' => 'VIATICOS', 'proceso_id' => '3', 'requiere_soporte' => 'NO',
    'viaticos_motivo' => 'Visita a cliente', 'viaticos_fecha_inicio' => $hoy, 'viaticos_fecha_fin' => $hoy,
    'presupuesto' => array('ALIMENTACION' => '100000'), 'acepta_descuento' => 'SI'));

$FLUJO_VIATICOS = array(
    array('SOLICITAR_ANTICIPO', 'SOLICITANTE', null, 'SIEMPRE'),
    array('APROBAR', 'ROL', 5, 'SIEMPRE'),
    array('CONTABILIZAR', 'ROL', 6, 'SIEMPRE'),
    array('PAGAR', 'ROL', 7, 'SIEMPRE'),
    array('MONTAR_PRELIMINAR', 'SOLICITANTE', null, 'SIEMPRE'),
    array('APROBAR', 'ROL', 5, 'SIEMPRE'),
    array('CONTABILIZAR', 'ROL', 6, 'SIEMPRE'),            // compensación (cruce)
    array('PAGAR_SALDO', 'ROL', 7, 'SALDO_EMPLEADO'),     // Tesorería devuelve el saldo al empleado
);

echo "Anticipo por viáticos (org. 1000) con saldo a favor del empleado\n";
nueva('ANTICIPO', '1000', $FLUJO_VIATICOS);
igual('SOLICITAR_ANTICIPO#1', actual(), 'arranca en la solicitud');
$sinSoporte = $viaje; unset($sinSoporte['requiere_soporte']);
igual('Indica si requiere soporte de pago.', paso($SOL, $sinSoporte), 'sin indicar soporte de pago: error');
igual('Adjunta el formato F-FR-0137 impreso y firmado (PDF o imagen).', paso($SOL, $viaje), 'sin formato firmado: error (código de la org. 1000)');
igual('Adjunta el finiquito (PDF o imagen).', paso($SOL, $viaje, array('formato_firmado' => archivo('firmado.pdf'))), 'sin finiquito: error');
igual(0, count(GasDb::$archivos), 'el error deshace los archivos registrados');
igual('', paso($SOL, $viaje, array('formato_firmado' => archivo('firmado.pdf'), 'finiquito' => archivo('finiquito.pdf'))), 'con formato firmado y finiquito: pasa');
igual('0', GasDb::$sol['REQUIERE_SOPORTE_PAGO'], 'guarda «no requiere soporte de pago»');
igual('100000.00', GasDb::$sol['VALOR_ANTICIPO'], 'valor del anticipo = total del formato');
igual('', paso($GA, array('decision' => 'APROBAR')), 'GA aprueba el anticipo');
igual(true, contiene(paso($CONT, array('num_contabilizacion' => '1800000001')), 'empezar por 19 o por 17'), 'contabilización con 18: error');
igual('', paso($CONT, array('num_contabilizacion' => '1712345678')), 'contabilización con 17 (10 dígitos): pasa');
igual('', paso($TES, array('num_comprobante_zp' => 'ZP1', 'fecha_pago' => $hoy)), 'Tesorería paga sin soporte (el solicitante dijo que no)');
igual(0, count(array_filter(GasDb::$archivos, function ($a) { return $a['CATEGORIA'] === 'COMPROBANTE'; })), 'no se registra soporte de pago');
$legal = array('legal' => array(array('fecha' => $hoy, 'detalle' => 'Bogotá almuerzos', 'centro_costo' => 'CC200', 'v' => array('ALIMENT' => '50000', 'TRANSP' => '80000'))));
igual('F-FR-0138, fila 1: escribe la ciudad y el detalle.', paso($SOL, array('legal' => array(array('fecha' => $hoy, 'detalle' => '', 'v' => array('TRANSP' => '1000'))))), 'errores de la legalización con el código F-FR-0138');
igual('Adjunta los soportes de la legalización (1 fila(s) no tienen soporte propio).', paso($SOL, $legal), 'sin soporte en la fila: se exige el adjunto general de soportes');
igual('', paso($SOL, $legal, array('legal_soporte_0' => archivo('almuerzo.pdf'))), 'legalización con soporte en la fila (el adjunto general pasa a opcional): gasta 130.000 con anticipo de 100.000');
igual(array('SOPORTE_GASTO', $hoyTxt . ' · Bogotá almuerzos'), array(GasDb::$archivos[2]['CATEGORIA'], GasDb::$archivos[2]['DESCRIPCION']), 'el soporte queda etiquetado con la fecha y el detalle del gasto');
igual(array(3, 3), array(GasDb::$legal[0]['ARCHIVO_ID'], GasDb::$legal[1]['ARCHIVO_ID']), 'las líneas del ítem quedan enlazadas a su soporte');
igual('AUXILIAR DE VENTAS', GasDb::$sol['CARGO'], 'el cargo del formato es el nombre del rol del solicitante');
igual('-30000.00', GasDb::$sol['SALDO_LEGALIZACION'], 'saldo a favor del empleado: −30.000');
igual('', paso($GA, array('decision' => 'APROBAR')), 'GA aprueba la legalización');
igual('', paso($CONT, array('num_compensacion' => '1712345678')), 'Contabilidad hace el cruce (compensación)');
igual('PAGAR_SALDO#8', actual(), 'después del cruce sigue Tesorería: devolver saldo al empleado');
igual('EN_CURSO', GasDb::$sol['ESTADO'], 'la solicitud aún no finaliza');
igual('Falta el archivo: Soporte de la devolución al empleado.', paso($TES, array('num_comprobante_saldo' => 'TR-9', 'fecha_pago_saldo' => $hoy)), 'devolución sin soporte: error');
igual('Este paso no te corresponde.', paso($CONT, array('num_comprobante_saldo' => 'TR-9', 'fecha_pago_saldo' => $hoy), array('soporte_saldo' => archivo('tr.png'))), 'otro rol no puede devolver el saldo');
igual('', paso($TES, array('num_comprobante_saldo' => 'TR-9', 'fecha_pago_saldo' => $hoy), array('soporte_saldo' => archivo('tr.png'))), 'Tesorería evidencia la devolución');
igual('FINALIZADA', GasDb::$sol['ESTADO'], 'con la devolución finaliza la solicitud');
igual('TR-9', GasDb::$sol['NUM_COMPROBANTE_SALDO'], 'guarda el comprobante de la devolución');

echo "Anticipo por viáticos (org. 2000) sin saldo a favor del empleado\n";
nueva('ANTICIPO', '2000', $FLUJO_VIATICOS);
igual(true, contiene(paso($SOL, $viaje), 'F-FR-023'), 'org. 2000 usa el código F-FR-023');
paso($SOL, array_merge($viaje, array('requiere_soporte' => 'SI')), array('formato_firmado' => archivo('f.pdf'), 'finiquito' => archivo('q.pdf')));
paso($GA, array('decision' => 'APROBAR'));
paso($CONT, array('num_contabilizacion' => '1900000001'));
igual('Falta el archivo: Soporte de pago.', paso($TES, array('num_comprobante_zp' => 'ZP1', 'fecha_pago' => $hoy)), 'requiere soporte de pago: Tesorería debe subirlo');
igual('', paso($TES, array('num_comprobante_zp' => 'ZP1', 'fecha_pago' => $hoy), array('comprobante' => archivo('zp.pdf'))), 'Tesorería paga con soporte');
$legal2 = array('legal' => array(array('fecha' => $hoy, 'detalle' => 'Cali', 'centro_costo' => 'CC100', 'v' => array('TRANSP' => '60000'))));
igual('Adjunta el comprobante de la transferencia a la empresa (hay saldo a favor de la empresa).', paso($SOL, $legal2, array('legal_soporte_0' => archivo('s.pdf'))), 'saldo a favor de la empresa pide el comprobante del reintegro');
igual('', paso($SOL, $legal2, array('legal_soporte_0' => archivo('s.pdf'), 'comprobante_reintegro' => archivo('r.pdf'))), 'legalización con reintegro');
$idSop = GasDb::$legal[0]['ARCHIVO_ID'];
paso($GA, array('decision' => 'DEVOLVER', 'comentario' => 'Revisa'));
$legal2['legal'][0]['archivo_id'] = (string) $idSop;
igual('', paso($SOL, $legal2, array('comprobante_reintegro' => archivo('r.pdf'))), 'al corregir, el ítem conserva su soporte anterior');
igual($idSop, GasDb::$legal[0]['ARCHIVO_ID'], 'mismo soporte enlazado');
paso($GA, array('decision' => 'APROBAR'));
igual('', paso($CONT, array('num_compensacion' => '1900000001')), 'compensación');
igual('FINALIZADA', GasDb::$sol['ESTADO'], 'sin saldo a favor del empleado finaliza tras el cruce');
igual('OMITIDO', GasDb::$pasos[8]['ESTADO'], 'el paso de devolución se omite');

echo "Devolución para corrección: el formato firmado y el finiquito no se vuelven a pedir\n";
nueva('ANTICIPO', '1000', $FLUJO_VIATICOS);
paso($SOL, $viaje, array('formato_firmado' => archivo('f.pdf'), 'finiquito' => archivo('q.pdf')));
igual('', paso($GA, array('decision' => 'DEVOLVER', 'comentario' => 'Corrige el motivo')), 'GA devuelve');
igual('SOLICITAR_ANTICIPO#1', actual(), 'vuelve al solicitante');
igual('', paso($SOL, $viaje), 'reenvía sin volver a adjuntar (ya estaban)');

echo "Factura: tarjeta, reparto por centros de costo y soporte de pago\n";
$FLUJO_FACTURA = array(
    array('MONTAR_PRELIMINAR', 'SOLICITANTE', null, 'SIEMPRE'),
    array('APROBAR', 'ROL', 5, 'SIEMPRE'),
    array('CONTABILIZAR', 'ROL', 6, 'SIEMPRE'),
    array('PAGAR', 'ROL', 7, 'SIEMPRE'),
);
nueva('FACTURA', '2000', $FLUJO_FACTURA);
igual('El número de preliminar debe tener máximo 10 caracteres.', paso($SOL, array_merge($tercero, array('num_preliminar' => '12345678901', 'requiere_soporte' => 'NO'))), 'número de preliminar de 11 caracteres: error');
$fac = array_merge($tercero, array('num_preliminar' => 'P-1', 'valor_total' => '1000000', 'fecha_factura' => $hoy, 'proceso_id' => '3',
    'fondo' => 'TARJETA', 'tarjeta_ultimos4' => '4321', 'tarjeta_titular' => 'Ana Pérez', 'tarjeta_banco' => 'Davivienda',
    'centros' => array(array('centro' => 'CC100', 'valor' => '600000'), array('centro' => 'CC200', 'valor' => '400000'))));
unset($fac['centro_costos']);
$f = array('factura_pdf' => archivo('fac.pdf'));
igual('Indica si requiere soporte de pago.', paso($SOL, $fac, $f), 'factura: hay que indicar si requiere soporte de pago');
$fac['requiere_soporte'] = 'SI';
igual('Tarjeta: indica el banco.', paso($SOL, array_merge($fac, array('tarjeta_banco' => '')), $f), 'tarjeta sin banco: error');
igual(true, contiene(paso($SOL, array_merge($fac, array('centros' => array(array('centro' => 'CC100', 'valor' => '600000')))), $f), 'debe ser igual al valor de la factura'), 'centros que no suman la factura: error');
igual('', paso($SOL, $fac, $f), 'legalización con tarjeta y dos centros de costo');
igual(array('4321', 'Ana Pérez', 'Davivienda', 'TARJETA'), array(GasDb::$sol['TARJETA_ULTIMOS4'], GasDb::$sol['TARJETA_TITULAR'], GasDb::$sol['TARJETA_BANCO'], GasDb::$sol['FONDO']), 'guarda los datos de la tarjeta');
igual(array(array('CENTRO' => 'CC100', 'VALOR' => 600000.0, 'ETAPA' => 'LEGALIZACION'), array('CENTRO' => 'CC200', 'VALOR' => 400000.0, 'ETAPA' => 'LEGALIZACION')), GasDb::$centros, 'guarda el reparto por centros');
igual('CC100', GasDb::$sol['CENTRO_COSTOS'], 'el centro principal es el primero del reparto');
igual('1', GasDb::$sol['REQUIERE_SOPORTE_PAGO'], 'guarda «requiere soporte de pago»');
paso($GA, array('decision' => 'APROBAR'));
igual('', paso($CONT, array('num_contabilizacion' => '19')), 'contabilización «19»');
igual('Falta el archivo: Soporte de pago.', paso($TES, array('num_comprobante_zp' => 'ZP2', 'fecha_pago' => $hoy)), 'Tesorería debe subir el soporte (se pidió)');
igual('', paso($TES, array('num_comprobante_zp' => 'ZP2', 'fecha_pago' => $hoy), array('comprobante' => archivo('zp.png'))), 'paga con soporte');
igual('FINALIZADA', GasDb::$sol['ESTADO'], 'finaliza');

echo "Factura pagada desde Roma: no pide datos de tarjeta\n";
nueva('FACTURA', '2000', $FLUJO_FACTURA);
$roma = array_merge($fac, array('fondo' => 'ROMA', 'tarjeta_ultimos4' => '', 'requiere_soporte' => 'NO',
    'centros' => array(array('centro' => 'CC300', 'valor' => '1.000.000'))));
igual('', paso($SOL, $roma, $f), 'Roma con un solo centro de costo');
igual(null, GasDb::$sol['TARJETA_ULTIMOS4'], 'sin datos de tarjeta');
igual('CC300', GasDb::$sol['CENTRO_COSTOS'], 'centro de costo del reparto');

echo "Anticipo por factura: varios centros de costo\n";
nueva('ANTICIPO', '1000', array(
    array('SOLICITAR_ANTICIPO', 'SOLICITANTE', null, 'SIEMPRE'),
    array('APROBAR', 'ROL', 5, 'SIEMPRE'),
    array('MONTAR_PRELIMINAR', 'SOLICITANTE', null, 'SIEMPRE'),
));
$antFac = array_merge($tercero, array('tipo_anticipo' => 'FACTURA', 'proceso_id' => '3', 'requiere_soporte' => 'NO', 'descripcion' => 'Repuestos',
    'valor_anticipo' => '300.000', 'centros' => array(array('centro' => 'CC100', 'valor' => '100000'), array('centro' => 'CC300', 'valor' => '100000'))));
unset($antFac['centro_costos']);
igual('La suma de los centros de costo (200.000,00) debe ser igual al valor del anticipo (300.000,00).', paso($SOL, $antFac), 'los centros deben sumar el valor del anticipo');
$antFac['centros'][1]['valor'] = '200000';
igual('', paso($SOL, $antFac), 'anticipo por factura con dos centros de costo');
igual(array('ANTICIPO', 'ANTICIPO'), array(GasDb::$centros[0]['ETAPA'], GasDb::$centros[1]['ETAPA']), 'el reparto queda en la etapa ANTICIPO');
paso($GA, array('decision' => 'APROBAR'));
$legFac = array_merge($tercero, array('num_preliminar' => 'P-2', 'valor_total' => '280000', 'fecha_factura' => $hoy, 'fondo' => 'ROMA',
    'centros' => array(array('centro' => 'CC200', 'valor' => '280000'))));
igual('', paso($SOL, $legFac, array('factura_pdf' => archivo('f.pdf'))), 'la legalización tiene su propio reparto');
igual(array('ANTICIPO', 'ANTICIPO', 'LEGALIZACION'), array_map(function ($c) { return $c['ETAPA']; }, GasDb::$centros), 'se conservan el reparto del anticipo y el de la legalización');
igual('Elige de dónde sale el pago: MULTIDROGAS, Fondo, Nota, Tarjeta.', 'Elige de dónde sale el pago: ' . implode(', ', GasCatalogo::fondos('1000')) . '.', 'org. 1000: la opción por defecto se llama MULTIDROGAS');

echo "Cotización: legalizar directo reparte por centros de costo y no pide el centro del tercero\n";
nueva('COTIZACION', '1000', array(
    array('DECISION_ANTICIPO', 'SOLICITANTE', null, 'SIEMPRE'),
    array('APROBAR', 'ROL', 5, 'ANTICIPO_SI'),
    array('MONTAR_PRELIMINAR', 'SOLICITANTE', null, 'SIEMPRE'),
    array('APROBAR', 'ROL', 5, 'SIEMPRE'),
    array('PAGAR', 'ROL', 7, 'ANTICIPO_NO'),
), array('REQUIERE_SOPORTE_PAGO' => 0, 'PROCESO_ID' => 3, 'DESCRIPCION' => 'Compra', 'REQUIERE_ANTICIPO' => null));
$leg = array_merge($tercero, array('requiere_anticipo' => 'NO', 'num_preliminar' => 'P-9', 'valor_total' => '500.000', 'fecha_factura' => $hoy,
    'fondo' => 'NOTA', 'fondo_numero' => 'N-1', 'centros' => array(array('centro' => 'CC200', 'valor' => '200000'), array('centro' => 'CC300', 'valor' => '300000'))));
unset($leg['centro_costos']);
igual('', paso($SOL, $leg, array('factura_pdf' => archivo('f.pdf'))), 'legaliza con dos centros de costo');
igual('APROBAR#4', actual(), 'pasa a la aprobación (la legalización queda hecha)');
igual(2, count(GasDb::$centros), 'guarda los dos centros');
igual('', paso($GA, array('decision' => 'APROBAR')), 'GA aprueba');
igual('', paso($TES, array('num_comprobante_zp' => 'ZP3', 'fecha_pago' => $hoy)), 'Tesorería paga sin soporte (no se pidió en la cotización)');
igual('FINALIZADA', GasDb::$sol['ESTADO'], 'finaliza');

echo $fallos ? "\n$fallos prueba(s) fallaron\n" : "\nTodo bien\n";
exit($fallos ? 1 : 0);
