<?php
/**
 * Motor del flujo: ejecuta el paso en curso de una solicitud y la mueve al siguiente.
 *
 * Reglas:
 *  1. Solo el responsable del paso en curso puede ejecutarlo (solicitante, rol o usuario).
 *  2. Cada ACCION tiene un manejador (h*) que valida el formulario y guarda los datos.
 *  3. Un rechazo cierra la solicitud (RECHAZADA).
 *  4. Al terminar un paso se busca el siguiente cuya CONDICION se cumpla; los que no
 *     aplican quedan OMITIDOS. Si no hay más pasos, la solicitud queda FINALIZADA.
 *  5. Todo ocurre en una transacción; si algo falla se deshacen la BD y los PDF subidos.
 */
class GasMotor
{
    /** PDF guardados en esta ejecución, para borrarlos si se hace rollback. */
    private static $rutasNuevas = array();

    /**
     * Ejecuta el paso en curso.
     * @param int   $solId  ID de la solicitud
     * @param array $u      usuario (GasSesion::actual())
     * @param array $in     campos del formulario ($_POST)
     * @param array $files  archivos ($_FILES)
     */
    public static function ejecutar($solId, $u, $in, $files)
    {
        self::$rutasNuevas = array();
        GasDb::begin();
        try {
            $sol = GasSolicitudModel::fila($solId, true); // bloqueo: evita doble ejecución simultánea
            if (!$sol) {
                throw new GasError('Solicitud no encontrada.');
            }
            if ($sol['ESTADO'] !== 'EN_CURSO') {
                throw new GasError('La solicitud ya no está en curso.');
            }
            $paso = GasDb::row("SELECT ID, ORDEN, ACCION, NOMBRE, RESPONSABLE_TIPO, ROL_ID, USUARIO_ID
                                  FROM T_GAS_SOLICITUD_PASOS WHERE SOLICITUD_ID = " . (int) $solId . " AND ESTADO = 'ACTUAL'");
            if (!$paso) {
                throw new GasError('La solicitud no tiene un paso en curso.');
            }
            if (!GasSolicitudModel::esResponsable($paso, $sol, $u)) {
                throw new GasError('Este paso no te corresponde.');
            }

            $h = 'h' . str_replace(' ', '', ucwords(strtolower(str_replace('_', ' ', $paso['ACCION']))));
            if (!method_exists(__CLASS__, $h)) {
                throw new GasError('Acción no soportada: ' . $paso['ACCION']);
            }
            $r = self::$h($sol, $paso, $in, $files, $u);
            $decision   = $r['decision'];
            $comentario = isset($r['comentario']) ? $r['comentario'] : null;

            if ($decision === 'RECHAZADO') {
                GasDb::query("UPDATE T_GAS_SOLICITUD_PASOS SET ESTADO = 'RECHAZADO', DECISION = 'RECHAZADO', EJECUTADO_POR = " . (int) $u['id']
                    . ', COMENTARIO = ' . GasDb::str($comentario, 500) . ', FECHA_FIN = GETDATE() WHERE ID = ' . (int) $paso['ID']);
                GasDb::query("UPDATE T_GAS_SOLICITUDES SET ESTADO = 'RECHAZADA', PASO_ACTUAL = NULL, MOTIVO_CIERRE = " . GasDb::str($comentario, 500)
                    . ', FECHA_FIN = GETDATE(), FECHA_MODIFICACION = GETDATE() WHERE ID = ' . (int) $solId);
            } else {
                GasDb::query("UPDATE T_GAS_SOLICITUD_PASOS SET ESTADO = 'COMPLETADO', DECISION = " . GasDb::str($decision)
                    . ', EJECUTADO_POR = ' . (int) $u['id'] . ', COMENTARIO = ' . GasDb::str($comentario, 500)
                    . ', FECHA_FIN = GETDATE() WHERE ID = ' . (int) $paso['ID']);
                GasDb::query('UPDATE T_GAS_SOLICITUDES SET FECHA_MODIFICACION = GETDATE() WHERE ID = ' . (int) $solId);
                self::activarSiguiente($solId, (int) $paso['ORDEN']);
            }
            GasDb::commit();
        } catch (Exception $e) {
            GasDb::rollback();
            GasArchivos::borrar(self::$rutasNuevas);
            throw $e;
        }
    }

    /** El solicitante cancela mientras la solicitud sigue en curso. */
    public static function cancelar($solId, $u, $motivo)
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new GasError('Indica el motivo de la cancelación.');
        }
        GasDb::begin();
        try {
            $sol = GasSolicitudModel::fila($solId, true);
            if (!$sol) {
                throw new GasError('Solicitud no encontrada.');
            }
            if ((int) $sol['USUARIO_ID'] !== (int) $u['id'] && !GasSesion::esAdmin($u)) {
                throw new GasError('Solo el solicitante puede cancelar.');
            }
            if ($sol['ESTADO'] !== 'EN_CURSO') {
                throw new GasError('La solicitud ya no está en curso.');
            }
            GasDb::query("UPDATE T_GAS_SOLICITUD_PASOS SET ESTADO = 'OMITIDO' WHERE SOLICITUD_ID = " . (int) $solId . " AND ESTADO IN ('ACTUAL','PENDIENTE')");
            GasDb::query("UPDATE T_GAS_SOLICITUDES SET ESTADO = 'CANCELADA', PASO_ACTUAL = NULL, MOTIVO_CIERRE = " . GasDb::str($motivo, 500)
                . ', FECHA_FIN = GETDATE(), FECHA_MODIFICACION = GETDATE() WHERE ID = ' . (int) $solId);
            GasDb::commit();
        } catch (Exception $e) {
            GasDb::rollback();
            throw $e;
        }
    }

    /**
     * Deja en curso el próximo paso cuyo ORDEN sea mayor a $despuesDe y cuya condición se cumpla.
     * Los pasos intermedios que no aplican se marcan OMITIDO. Sin más pasos => FINALIZADA.
     * Debe llamarse dentro de una transacción.
     */
    public static function activarSiguiente($solId, $despuesDe)
    {
        $sol   = GasSolicitudModel::fila($solId);
        $pasos = GasDb::all("SELECT ID, ORDEN, CONDICION FROM T_GAS_SOLICITUD_PASOS
                              WHERE SOLICITUD_ID = " . (int) $solId . " AND ESTADO = 'PENDIENTE' AND ORDEN > " . (int) $despuesDe . ' ORDER BY ORDEN');
        foreach ($pasos as $p) {
            if (GasCatalogo::cumpleCondicion($p['CONDICION'], $sol)) {
                GasDb::query("UPDATE T_GAS_SOLICITUD_PASOS SET ESTADO = 'ACTUAL', FECHA_INICIO = GETDATE() WHERE ID = " . (int) $p['ID']);
                GasDb::query('UPDATE T_GAS_SOLICITUDES SET PASO_ACTUAL = ' . (int) $p['ORDEN'] . ' WHERE ID = ' . (int) $solId);
                return;
            }
            GasDb::query("UPDATE T_GAS_SOLICITUD_PASOS SET ESTADO = 'OMITIDO' WHERE ID = " . (int) $p['ID']);
        }
        GasDb::query("UPDATE T_GAS_SOLICITUDES SET ESTADO = 'FINALIZADA', PASO_ACTUAL = NULL, FECHA_FIN = GETDATE(), FECHA_MODIFICACION = GETDATE() WHERE ID = " . (int) $solId);
    }

    /* =========================================================================
       MANEJADORES DE ACCIONES (uno por GasCatalogo::acciones())
       Reciben ($sol, $paso, $in, $files, $u) y devuelven array(decision, comentario?).
       ========================================================================= */

    /** SUBIR_COTIZACIONES: 3 PDF + proceso + si requiere soporte de pago. */
    private static function hSubirCotizaciones($sol, $paso, $in, $files, $u)
    {
        $proceso = self::proceso($in, true);
        $soporte = self::siNo($in, 'requiere_soporte', 'Indica si requiere soporte de pago.');
        $desc    = self::texto($in, 'descripcion', 500, 'Describe brevemente el gasto.');

        $cots = isset($in['cot']) && is_array($in['cot']) ? $in['cot'] : array();
        $ids  = array();
        for ($n = 1; $n <= 3; $n++) {
            $f = isset($files['cot_' . $n]) ? $files['cot_' . $n] : null;
            $g = GasArchivos::guardarPdf($f, "Cotización $n");
            self::$rutasNuevas[] = $g['ruta'];
            $ids[$n] = self::registrarArchivo($sol['ID'], $paso['ORDEN'], 'COTIZACION', $g, $u);
        }
        for ($n = 1; $n <= 3; $n++) {
            $c = isset($cots[$n]) && is_array($cots[$n]) ? $cots[$n] : array();
            GasDb::query('INSERT INTO T_GAS_COTIZACIONES (SOLICITUD_ID, NUMERO, PROVEEDOR, VALOR, ARCHIVO_ID) VALUES ('
                . (int) $sol['ID'] . ', ' . $n . ', ' . GasDb::str(isset($c['proveedor']) ? $c['proveedor'] : null, 150) . ', '
                . GasDb::num(isset($c['valor']) ? $c['valor'] : null) . ', ' . $ids[$n] . ')');
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET PROCESO_ID = ' . (int) $proceso['ID'] . ', PROCESO = ' . GasDb::str($proceso['PROCESO'], 100)
            . ', REQUIERE_SOPORTE_PAGO = ' . GasDb::bit($soporte) . ', DESCRIPCION = ' . GasDb::str($desc, 500) . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'COMPLETADO');
    }

    /** AUTORIZAR_COTIZACION: elige 1 de las 3 y comenta, o rechaza. */
    private static function hAutorizarCotizacion($sol, $paso, $in, $files, $u)
    {
        $aprueba    = self::decision($in);
        $comentario = self::texto($in, 'comentario', 500, 'El comentario es obligatorio.');
        if (!$aprueba) {
            return array('decision' => 'RECHAZADO', 'comentario' => $comentario);
        }
        $n = isset($in['cotizacion_elegida']) ? (int) $in['cotizacion_elegida'] : 0;
        $existe = GasDb::scalar('SELECT COUNT(*) FROM T_GAS_COTIZACIONES WHERE SOLICITUD_ID = ' . (int) $sol['ID'] . ' AND NUMERO = ' . $n);
        if ($n < 1 || $n > 3 || !$existe) {
            throw new GasError('Elige una de las tres cotizaciones.');
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET COTIZACION_ELEGIDA = ' . $n . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'APROBADO', 'comentario' => $comentario);
    }

    /**
     * DECISION_ANTICIPO (flujo de cotización): ¿necesita anticipo?
     *  - Sí: valor del anticipo + datos del tercero, y sigue la aprobación del anticipo.
     *  - No: pasa directo a montar el preliminar (allí se piden el tercero y la factura).
     */
    private static function hDecisionAnticipo($sol, $paso, $in, $files, $u)
    {
        $necesita = self::siNo($in, 'requiere_anticipo', 'Indica si necesitas anticipo.');
        if ($necesita) {
            self::guardarTercero($sol['ID'], $in);
            $valor = self::valorPositivo($in, 'valor_anticipo', 'Indica el valor del anticipo.');
            GasDb::query("UPDATE T_GAS_SOLICITUDES SET REQUIERE_ANTICIPO = 1, TIPO_ANTICIPO = 'COTIZACION', VALOR_ANTICIPO = "
                . GasDb::num($valor) . ' WHERE ID = ' . (int) $sol['ID']);
        } else {
            GasDb::query('UPDATE T_GAS_SOLICITUDES SET REQUIERE_ANTICIPO = 0, TIPO_ANTICIPO = NULL, VALOR_ANTICIPO = NULL WHERE ID = ' . (int) $sol['ID']);
        }
        return array('decision' => 'COMPLETADO');
    }

    /** SOLICITAR_ANTICIPO (flujo de anticipo): por factura (valor) o por viáticos (formulario de viaje). */
    private static function hSolicitarAnticipo($sol, $paso, $in, $files, $u)
    {
        $tipo = isset($in['tipo_anticipo']) ? $in['tipo_anticipo'] : '';
        if ($tipo !== 'FACTURA' && $tipo !== 'VIATICOS') {
            throw new GasError('Elige el tipo de anticipo: por factura o por viáticos.');
        }
        $proceso = self::proceso($in, true);
        $desc    = self::texto($in, 'descripcion', 500, 'Describe brevemente el gasto.');
        self::guardarTercero($sol['ID'], $in);

        if ($tipo === 'FACTURA') {
            $valor = self::valorPositivo($in, 'valor_anticipo', 'Indica el valor del anticipo.');
        } else {
            $valor = self::guardarViaticos($sol['ID'], $in);
        }
        GasDb::query("UPDATE T_GAS_SOLICITUDES SET REQUIERE_ANTICIPO = 1, TIPO_ANTICIPO = " . GasDb::str($tipo) . ', VALOR_ANTICIPO = ' . GasDb::num($valor)
            . ', PROCESO_ID = ' . (int) $proceso['ID'] . ', PROCESO = ' . GasDb::str($proceso['PROCESO'], 100)
            . ', DESCRIPCION = ' . GasDb::str($desc, 500) . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'COMPLETADO');
    }

    /** MONTAR_PRELIMINAR: número de preliminar, valor, tercero y soporte de pago si aplica. */
    private static function hMontarPreliminar($sol, $paso, $in, $files, $u)
    {
        $num   = self::texto($in, 'num_preliminar', 30, 'Indica el número de preliminar.');
        $valor = self::valorPositivo($in, 'valor_total', 'Indica el valor del preliminar.');
        self::guardarTercero($sol['ID'], $in);

        // Si el flujo no pidió proceso antes (p. ej. flujo de factura), se pide aquí.
        if (empty($sol['PROCESO_ID'])) {
            $proceso = self::proceso($in, true);
            GasDb::query('UPDATE T_GAS_SOLICITUDES SET PROCESO_ID = ' . (int) $proceso['ID'] . ', PROCESO = ' . GasDb::str($proceso['PROCESO'], 100)
                . ' WHERE ID = ' . (int) $sol['ID']);
        }
        if (empty($sol['DESCRIPCION']) && !empty($in['descripcion'])) {
            GasDb::query('UPDATE T_GAS_SOLICITUDES SET DESCRIPCION = ' . GasDb::str($in['descripcion'], 500) . ' WHERE ID = ' . (int) $sol['ID']);
        }

        // Soporte de pago: obligatorio si la cotización lo exigió; opcional en los demás casos.
        $exigeSoporte = !empty($sol['REQUIERE_SOPORTE_PAGO']);
        foreach (array('soporte_pago' => array('SOPORTE_PAGO', 'Soporte de pago'), 'preliminar_pdf' => array('PRELIMINAR', 'Preliminar (PDF)')) as $campo => $meta) {
            $f = isset($files[$campo]) ? $files[$campo] : null;
            $hay = $f && isset($f['error']) && $f['error'] !== UPLOAD_ERR_NO_FILE;
            if (!$hay && $campo === 'soporte_pago' && $exigeSoporte) {
                throw new GasError('Esta solicitud requiere soporte de pago en PDF.');
            }
            if ($hay) {
                $g = GasArchivos::guardarPdf($f, $meta[1]);
                self::$rutasNuevas[] = $g['ruta'];
                self::registrarArchivo($sol['ID'], $paso['ORDEN'], $meta[0], $g, $u);
            }
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET NUM_PRELIMINAR = ' . GasDb::str($num, 30) . ', VALOR_TOTAL = ' . GasDb::num($valor)
            . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'COMPLETADO');
    }

    /** APROBAR: aprueba o rechaza (anticipo o preliminar, según cómo se llame el paso). */
    private static function hAprobar($sol, $paso, $in, $files, $u)
    {
        $aprueba = self::decision($in);
        $com     = isset($in['comentario']) ? trim($in['comentario']) : '';
        if (!$aprueba && $com === '') {
            throw new GasError('Explica el motivo del rechazo.');
        }
        return array('decision' => $aprueba ? 'APROBADO' : 'RECHAZADO', 'comentario' => $com);
    }

    /** CONTABILIZAR: número de contabilización y, opcional, causación de compensación. */
    private static function hContabilizar($sol, $paso, $in, $files, $u)
    {
        $num  = self::texto($in, 'num_contabilizacion', 30, 'Indica el número de contabilización.');
        $comp = isset($in['num_compensacion']) ? trim($in['num_compensacion']) : '';
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET NUM_CONTABILIZACION = ' . GasDb::str($num, 30) . ', NUM_COMPENSACION = ' . GasDb::str($comp, 30)
            . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'COMPLETADO', 'comentario' => isset($in['comentario']) ? trim($in['comentario']) : null);
    }

    /** PAGAR: número de comprobante ZP, fecha de pago y comprobante en PDF. */
    private static function hPagar($sol, $paso, $in, $files, $u)
    {
        $zp    = self::texto($in, 'num_comprobante_zp', 30, 'Indica el número de comprobante ZP.');
        $fecha = isset($in['fecha_pago']) ? trim($in['fecha_pago']) : '';
        if (GasDb::fecha($fecha) === 'NULL') {
            throw new GasError('Indica una fecha de pago válida.');
        }
        $g = GasArchivos::guardarPdf(isset($files['comprobante']) ? $files['comprobante'] : null, 'Comprobante de pago');
        self::$rutasNuevas[] = $g['ruta'];
        self::registrarArchivo($sol['ID'], $paso['ORDEN'], 'COMPROBANTE', $g, $u);
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET NUM_COMPROBANTE_ZP = ' . GasDb::str($zp, 30) . ', FECHA_PAGO = ' . GasDb::fecha($fecha)
            . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'COMPLETADO', 'comentario' => isset($in['comentario']) ? trim($in['comentario']) : null);
    }

    /* =========================================================================
       AUXILIARES DE VALIDACIÓN Y GUARDADO
       ========================================================================= */

    /** true = APROBAR, false = RECHAZAR. */
    private static function decision($in)
    {
        $d = isset($in['decision']) ? $in['decision'] : '';
        if ($d !== 'APROBAR' && $d !== 'RECHAZAR') {
            throw new GasError('Elige si apruebas o rechazas.');
        }
        return $d === 'APROBAR';
    }

    private static function siNo($in, $campo, $mensaje)
    {
        $v = isset($in[$campo]) ? $in[$campo] : '';
        if ($v !== 'SI' && $v !== 'NO') {
            throw new GasError($mensaje);
        }
        return $v === 'SI';
    }

    private static function texto($in, $campo, $max, $mensaje)
    {
        $v = isset($in[$campo]) ? trim($in[$campo]) : '';
        if ($v === '') {
            throw new GasError($mensaje);
        }
        return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    }

    private static function valorPositivo($in, $campo, $mensaje)
    {
        $v = isset($in[$campo]) ? GasDb::aNumero($in[$campo]) : 0;
        if ($v <= 0) {
            throw new GasError($mensaje);
        }
        return $v;
    }

    /** Valida que el proceso exista en T_CAL_PROCESOS y devuelve array(ID, PROCESO). */
    private static function proceso($in, $obligatorio)
    {
        $id = isset($in['proceso_id']) ? (int) $in['proceso_id'] : 0;
        if ($id <= 0) {
            if ($obligatorio) {
                throw new GasError('Selecciona el departamento o proceso.');
            }
            return null;
        }
        $p = GasDb::row('SELECT ID, PROCESO FROM T_CAL_PROCESOS WHERE ID = ' . $id);
        if (!$p) {
            throw new GasError('El departamento o proceso no existe.');
        }
        return $p;
    }

    /** Valida y guarda los datos del tercero en la solicitud (nit, razón social, celular, correo, cargo, centro de costos). */
    private static function guardarTercero($solId, $in)
    {
        $nit    = preg_replace('/[^0-9A-Za-z\-]/', '', isset($in['tercero_nit']) ? $in['tercero_nit'] : '');
        $nombre = self::texto($in, 'tercero_nombre', 150, 'Indica la razón social del tercero.');
        $cel    = preg_replace('/\D/', '', isset($in['tercero_celular']) ? $in['tercero_celular'] : '');
        $mail   = isset($in['tercero_email']) ? trim($in['tercero_email']) : '';
        $cargo  = self::texto($in, 'cargo', 80, 'Indica el cargo.');
        $cc     = self::texto($in, 'centro_costos', 40, 'Indica el centro de costos.');
        if ($nit === '') {
            throw new GasError('Indica el NIT del tercero.');
        }
        if (strlen($cel) < 7 || strlen($cel) > 15) {
            throw new GasError('El celular del tercero no es válido.');
        }
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            throw new GasError('El correo del tercero no es válido.');
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET TERCERO_NIT = ' . GasDb::str($nit, 16) . ', TERCERO_NOMBRE = ' . GasDb::str($nombre, 150)
            . ', TERCERO_CELULAR = ' . GasDb::str($cel, 20) . ', TERCERO_EMAIL = ' . GasDb::str($mail, 100)
            . ', CARGO = ' . GasDb::str($cargo, 80) . ', CENTRO_COSTOS = ' . GasDb::str($cc, 40) . ' WHERE ID = ' . (int) $solId);
    }

    /**
     * Guarda cabecera y líneas del formulario de viáticos y devuelve el total.
     * Total de cada línea = cantidad x valor unitario (se recalcula en el servidor).
     */
    private static function guardarViaticos($solId, $in)
    {
        $destino = self::texto($in, 'viaticos_destino', 100, 'Indica el destino del viaje.');
        $motivo  = self::texto($in, 'viaticos_motivo', 300, 'Indica el motivo del viaje.');
        $ini     = isset($in['viaticos_fecha_inicio']) ? $in['viaticos_fecha_inicio'] : '';
        $fin     = isset($in['viaticos_fecha_fin']) ? $in['viaticos_fecha_fin'] : '';
        if (GasDb::fecha($ini) === 'NULL' || GasDb::fecha($fin) === 'NULL') {
            throw new GasError('Indica las fechas de inicio y fin del viaje.');
        }
        if ($fin < $ini) {
            throw new GasError('La fecha final no puede ser anterior a la inicial.');
        }
        $lineas = isset($in['lineas']) && is_array($in['lineas']) ? $in['lineas'] : array();
        $conceptos = GasCatalogo::conceptosViaticos();
        $total = 0.0;
        $sql   = array();
        foreach ($lineas as $l) {
            if (!is_array($l)) {
                continue;
            }
            $concepto = isset($l['concepto']) ? $l['concepto'] : '';
            $cant     = isset($l['cantidad']) ? GasDb::aNumero($l['cantidad']) : 0;
            $unit     = isset($l['valor_unitario']) ? GasDb::aNumero($l['valor_unitario']) : 0;
            if ($concepto === '' && $cant == 0 && $unit == 0) {
                continue; // fila vacía
            }
            if (!isset($conceptos[$concepto]) || $cant <= 0 || $unit <= 0) {
                throw new GasError('Revisa las líneas de gastos: concepto, cantidad y valor unitario son obligatorios.');
            }
            $sub    = round($cant * $unit, 2);
            $total += $sub;
            $sql[]  = 'INSERT INTO T_GAS_VIATICOS_DETALLE (SOLICITUD_ID, CONCEPTO, DESCRIPCION, CANTIDAD, VALOR_UNITARIO, VALOR_TOTAL) VALUES ('
                . (int) $solId . ', ' . GasDb::str($concepto) . ', ' . GasDb::str(isset($l['descripcion']) ? $l['descripcion'] : null, 200)
                . ', ' . GasDb::num($cant) . ', ' . GasDb::num($unit) . ', ' . GasDb::num($sub) . ')';
        }
        if (!$sql) {
            throw new GasError('Agrega al menos una línea de gastos de viaje.');
        }
        foreach ($sql as $q) {
            GasDb::query($q);
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET VIATICOS_DESTINO = ' . GasDb::str($destino, 100) . ', VIATICOS_FECHA_INICIO = ' . GasDb::fecha($ini)
            . ', VIATICOS_FECHA_FIN = ' . GasDb::fecha($fin) . ', VIATICOS_MOTIVO = ' . GasDb::str($motivo, 300) . ' WHERE ID = ' . (int) $solId);
        return $total;
    }

    /** Inserta el registro de un PDF ya guardado y devuelve su ID. */
    private static function registrarArchivo($solId, $pasoOrden, $categoria, $g, $u)
    {
        return GasDb::insert('INSERT INTO T_GAS_ARCHIVOS (SOLICITUD_ID, PASO_ORDEN, CATEGORIA, NOMBRE_ORIGINAL, RUTA, TAMANO, USUARIO_ID) VALUES ('
            . (int) $solId . ', ' . (int) $pasoOrden . ', ' . GasDb::str($categoria) . ', ' . GasDb::str($g['nombre'], 200) . ', '
            . GasDb::str($g['ruta']) . ', ' . (int) $g['tamano'] . ', ' . (int) $u['id'] . ')');
    }
}
