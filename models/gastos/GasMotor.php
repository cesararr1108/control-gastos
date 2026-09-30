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
     *  - Sí: valor del anticipo + datos del tercero; sigue la aprobación del anticipo.
     *  - No (ya tiene el preliminar): se registra aquí mismo el preliminar (número, fecha y valor de la
     *    factura, factura en PDF) y el paso «Montar preliminar» queda realizado, de modo que sigue la
     *    aprobación del preliminar por GA. Los pasos de anticipo se omiten por su condición.
     */
    private static function hDecisionAnticipo($sol, $paso, $in, $files, $u)
    {
        $necesita = self::siNo($in, 'requiere_anticipo', 'Indica si necesitas anticipo.');
        if ($necesita) {
            self::guardarTercero($sol['ID'], $in);
            $valor = self::valorPositivo($in, 'valor_anticipo', 'Indica el valor del anticipo.');
            GasDb::query("UPDATE T_GAS_SOLICITUDES SET REQUIERE_ANTICIPO = 1, TIPO_ANTICIPO = 'COTIZACION', VALOR_ANTICIPO = "
                . GasDb::num($valor) . ' WHERE ID = ' . (int) $sol['ID']);
            return array('decision' => 'COMPLETADO');
        }

        GasDb::query('UPDATE T_GAS_SOLICITUDES SET REQUIERE_ANTICIPO = 0, TIPO_ANTICIPO = NULL, VALOR_ANTICIPO = NULL WHERE ID = ' . (int) $sol['ID']);
        self::registrarPreliminar($sol, $paso, $in, $files, $u, true); // aquí solo se adjunta la factura

        // El paso "Montar preliminar" (si es del solicitante) ya quedó hecho: se marca completado para que no se repita.
        $pendiente = GasDb::row("SELECT TOP 1 ID FROM T_GAS_SOLICITUD_PASOS
                                  WHERE SOLICITUD_ID = " . (int) $sol['ID'] . " AND ESTADO = 'PENDIENTE' AND ACCION = 'MONTAR_PRELIMINAR'
                                    AND RESPONSABLE_TIPO = 'SOLICITANTE' AND ORDEN > " . (int) $paso['ORDEN'] . ' ORDER BY ORDEN');
        if ($pendiente) {
            GasDb::query("UPDATE T_GAS_SOLICITUD_PASOS SET ESTADO = 'COMPLETADO', DECISION = 'COMPLETADO', EJECUTADO_POR = " . (int) $u['id']
                . ", COMENTARIO = 'Registrado al definir que ya tenía el preliminar', FECHA_INICIO = GETDATE(), FECHA_FIN = GETDATE() WHERE ID = " . (int) $pendiente['ID']);
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
        // En viáticos la descripción es el motivo del viaje (formato F-FR-023).
        $desc    = $tipo === 'VIATICOS'
            ? self::texto($in, 'viaticos_motivo', 500, 'Indica el motivo de la solicitud.')
            : self::texto($in, 'descripcion', 500, 'Describe brevemente el gasto.');
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

    /** MONTAR_PRELIMINAR: número de preliminar, fecha y valor de la factura, tercero y PDF de la factura. */
    private static function hMontarPreliminar($sol, $paso, $in, $files, $u)
    {
        self::registrarPreliminar($sol, $paso, $in, $files, $u);
        return array('decision' => 'COMPLETADO');
    }

    /**
     * Registra el preliminar y la factura. Lo usan el paso «Montar preliminar» y la decisión
     * «ya tengo el preliminar» (DECISION_ANTICIPO). $paso es el paso que se está ejecutando.
     * Con $soloFactura solo se recibe la factura en PDF (sin soporte de pago ni preliminar en PDF).
     */
    private static function registrarPreliminar($sol, $paso, $in, $files, $u, $soloFactura = false)
    {
        // Anticipo por viáticos: en lugar de la factura se diligencia el formato de legalización.
        if (isset($sol['TIPO_ANTICIPO']) && $sol['TIPO_ANTICIPO'] === 'VIATICOS') {
            self::registrarLegalizacion($sol, $paso, $in, $files, $u);
            return;
        }
        $num   = self::texto($in, 'num_preliminar', 30, 'Indica el número de preliminar.');
        $valor = self::valorPositivo($in, 'valor_total', 'Indica el valor del preliminar.');
        $fechaF = isset($in['fecha_factura']) ? trim($in['fecha_factura']) : '';
        if (GasDb::fecha($fechaF) === 'NULL') {
            throw new GasError('Indica la fecha de la factura.');
        }
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

        // La factura en PDF es obligatoria. El soporte de pago lo es solo si la cotización lo exigió.
        $exigeSoporte = !empty($sol['REQUIERE_SOPORTE_PAGO']);
        $archivos = array(
            'factura_pdf'    => array('FACTURA', 'Factura (PDF)', true),
            'soporte_pago'   => array('SOPORTE_PAGO', 'Soporte de pago', $exigeSoporte),
            'preliminar_pdf' => array('PRELIMINAR', 'Preliminar (PDF)', false),
        );
        if ($soloFactura) {
            $archivos = array('factura_pdf' => $archivos['factura_pdf']);
        }
        foreach ($archivos as $campo => $meta) {
            $f   = isset($files[$campo]) ? $files[$campo] : null;
            $hay = $f && isset($f['error']) && $f['error'] !== UPLOAD_ERR_NO_FILE;
            if (!$hay && $meta[2]) {
                throw new GasError($campo === 'soporte_pago' ? 'Esta solicitud requiere soporte de pago en PDF.' : 'Adjunta la factura en PDF.');
            }
            if ($hay) {
                $g = GasArchivos::guardarPdf($f, $meta[1]);
                self::$rutasNuevas[] = $g['ruta'];
                self::registrarArchivo($sol['ID'], $paso['ORDEN'], $meta[0], $g, $u);
            }
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET NUM_PRELIMINAR = ' . GasDb::str($num, 30) . ', VALOR_TOTAL = ' . GasDb::num($valor)
            . ', FECHA_FACTURA = ' . GasDb::fecha($fechaF) . ' WHERE ID = ' . (int) $sol['ID']);
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
     * Solicitud de viáticos, formato F-FR-023. Guarda datos del viaje, pasajes y el valor presupuestado
     * por concepto, y devuelve el total solicitado (recalculado en el servidor).
     * Validaciones: salida >= hoy, regreso >= salida, si hay tiquetes aéreos/terrestres debe haber una
     * ruta de ese tipo, "Otros" con valor exige decir cuál, total > 0 y autorización de descuento aceptada.
     */
    private static function guardarViaticos($solId, $in)
    {
        $lugar  = self::texto($in, 'viaticos_destino', 100, 'Indica el lugar o destino del viaje.');
        $motivo = self::texto($in, 'viaticos_motivo', 300, 'Indica el motivo de la solicitud.');
        $ini    = isset($in['viaticos_fecha_inicio']) ? trim($in['viaticos_fecha_inicio']) : '';
        $fin    = isset($in['viaticos_fecha_fin']) ? trim($in['viaticos_fecha_fin']) : '';
        if (GasDb::fecha($ini) === 'NULL' || GasDb::fecha($fin) === 'NULL') {
            throw new GasError('Indica la fecha de salida y la de regreso.');
        }
        if ($ini < date('Y-m-d')) {
            throw new GasError('La fecha de salida no puede ser anterior a hoy: el anticipo se solicita antes del viaje.');
        }
        if ($fin < $ini) {
            throw new GasError('La fecha de regreso no puede ser anterior a la fecha de salida.');
        }

        // Solicitud de pasajes
        $tiposRuta = array('AEREO', 'TERRESTRE');
        $rutaS = isset($in['ruta_salida']) ? trim($in['ruta_salida']) : '';
        $rutaR = isset($in['ruta_regreso']) ? trim($in['ruta_regreso']) : '';
        $tipoS = isset($in['tipo_salida']) && in_array($in['tipo_salida'], $tiposRuta, true) ? $in['tipo_salida'] : '';
        $tipoR = isset($in['tipo_regreso']) && in_array($in['tipo_regreso'], $tiposRuta, true) ? $in['tipo_regreso'] : '';
        if (($rutaS !== '' && $tipoS === '') || ($rutaR !== '' && $tipoR === '')) {
            throw new GasError('Indica si cada ruta de pasajes es aérea o terrestre.');
        }
        if (($tipoS !== '' && $rutaS === '') || ($tipoR !== '' && $rutaR === '')) {
            throw new GasError('Escribe la ruta de los pasajes que marcaste como aéreos o terrestres.');
        }

        // Valor presupuestado
        $conceptos = GasCatalogo::conceptosViaticos();
        $pres = isset($in['presupuesto']) && is_array($in['presupuesto']) ? $in['presupuesto'] : array();
        $total = 0.0;
        $sql = array();
        foreach ($conceptos as $cod => $nombre) {
            $v = isset($pres[$cod]) ? GasDb::aNumero($pres[$cod]) : 0;
            if ($v < 0) {
                throw new GasError("El valor de «$nombre» no puede ser negativo.");
            }
            if ($v == 0) {
                continue;
            }
            $desc = null;
            if ($cod === 'OTROS') {
                $desc = self::texto($in, 'presupuesto_otros', 200, 'Indica cuál es el concepto «Otros».');
            }
            $total += round($v, 2);
            $sql[] = 'INSERT INTO T_GAS_VIATICOS_DETALLE (SOLICITUD_ID, CONCEPTO, DESCRIPCION, CANTIDAD, VALOR_UNITARIO, VALOR_TOTAL) VALUES ('
                . (int) $solId . ', ' . GasDb::str($cod) . ', ' . GasDb::str($desc, 200) . ', 1, ' . GasDb::num($v) . ', ' . GasDb::num($v) . ')';
        }
        if ($total <= 0) {
            throw new GasError('Indica al menos un valor presupuestado.');
        }
        $aereos     = isset($pres['TIQUETES_AEREOS']) ? GasDb::aNumero($pres['TIQUETES_AEREOS']) : 0;
        $terrestres = isset($pres['TIQUETES_TERRESTRES']) ? GasDb::aNumero($pres['TIQUETES_TERRESTRES']) : 0;
        if ($aereos > 0 && $tipoS !== 'AEREO' && $tipoR !== 'AEREO') {
            throw new GasError('Presupuestaste tiquetes aéreos: indica la ruta aérea en «Solicitud de pasajes».');
        }
        if ($terrestres > 0 && $tipoS !== 'TERRESTRE' && $tipoR !== 'TERRESTRE') {
            throw new GasError('Presupuestaste tiquetes terrestres: indica la ruta terrestre en «Solicitud de pasajes».');
        }
        if (!isset($in['acepta_descuento']) || $in['acepta_descuento'] !== 'SI') {
            throw new GasError('Debes aceptar la autorización de descuento por falta de legalización (art. 150 y 151 CST).');
        }

        GasDb::query('DELETE FROM T_GAS_VIATICOS_DETALLE WHERE SOLICITUD_ID = ' . (int) $solId);
        foreach ($sql as $q) {
            GasDb::query($q);
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET VIATICOS_DESTINO = ' . GasDb::str($lugar, 100) . ', VIATICOS_MOTIVO = ' . GasDb::str($motivo, 300)
            . ', VIATICOS_FECHA_INICIO = ' . GasDb::fecha($ini) . ', VIATICOS_FECHA_FIN = ' . GasDb::fecha($fin)
            . ', VIATICOS_TEL_FIJO = ' . GasDb::str(isset($in['viaticos_tel_fijo']) ? preg_replace('/[^0-9 ]/', '', $in['viaticos_tel_fijo']) : null, 20)
            . ', VIATICOS_RUTA_SALIDA = ' . GasDb::str($rutaS, 150) . ', VIATICOS_TIPO_SALIDA = ' . GasDb::str($tipoS)
            . ', VIATICOS_RUTA_REGRESO = ' . GasDb::str($rutaR, 150) . ', VIATICOS_TIPO_REGRESO = ' . GasDb::str($tipoR)
            . ', VIATICOS_ACEPTA_DESCUENTO = 1 WHERE ID = ' . (int) $solId);
        return $total;
    }

    /**
     * Legalización de viáticos, formato F-FR-024 (en el paso del preliminar).
     * Cada línea: fecha, centro de costo, doc (n.º factura), ciudad y detalles, tipo de gasto (columna
     * del formato) y valor. Validaciones: fecha dentro del viaje (se admite un día antes y uno después
     * por traslados) y no futura, detalle y valor > 0.
     * Retefuente: GasConfig::RETEFUENTE_TASA a cada gasto de hotel/alimentación mayor al tope.
     * Total cuenta de gastos = suma - retefuente. Saldo = suma recibida (anticipo) - total cuenta:
     *   > 0 a favor de la empresa (el empleado reintegra; el comprobante va en el PDF de soportes)
     *   < 0 a favor del empleado (se le paga la diferencia)
     */
    private static function registrarLegalizacion($sol, $paso, $in, $files, $u)
    {
        $num   = self::texto($in, 'num_preliminar', 30, 'Indica el número de preliminar.');
        $tipos = GasCatalogo::tiposGastoLegalizacion();
        $hoy   = date('Y-m-d');
        $desde = !empty($sol['VIATICOS_FECHA_INICIO']) ? date('Y-m-d', strtotime($sol['VIATICOS_FECHA_INICIO'] . ' -1 day')) : null;
        $hasta = !empty($sol['VIATICOS_FECHA_FIN']) ? date('Y-m-d', strtotime($sol['VIATICOS_FECHA_FIN'] . ' +1 day')) : null;

        $lineas = isset($in['legal']) && is_array($in['legal']) ? $in['legal'] : array();
        $suma = 0.0;
        $rete = 0.0;
        $sql  = array();
        $n    = 0;
        foreach ($lineas as $l) {
            if (!is_array($l)) {
                continue;
            }
            $fecha = isset($l['fecha']) ? trim($l['fecha']) : '';
            $det   = isset($l['detalle']) ? trim($l['detalle']) : '';
            $tipo  = isset($l['tipo']) ? $l['tipo'] : '';
            $valor = isset($l['valor']) ? GasDb::aNumero($l['valor']) : 0;
            if ($fecha === '' && $det === '' && $valor == 0) {
                continue; // fila vacía
            }
            $n++;
            if (GasDb::fecha($fecha) === 'NULL') {
                throw new GasError("Legalización, línea $n: fecha inválida.");
            }
            if ($fecha > $hoy) {
                throw new GasError("Legalización, línea $n: la fecha no puede ser futura.");
            }
            if (($desde && $fecha < $desde) || ($hasta && $fecha > $hasta)) {
                throw new GasError("Legalización, línea $n: la fecha debe estar dentro del viaje ("
                    . $sol['VIATICOS_FECHA_INICIO'] . ' a ' . $sol['VIATICOS_FECHA_FIN'] . ', se admite un día antes y uno después).');
            }
            if (!isset($tipos[$tipo])) {
                throw new GasError("Legalización, línea $n: elige el tipo de gasto.");
            }
            if ($det === '') {
                throw new GasError("Legalización, línea $n: escribe la ciudad y el detalle.");
            }
            if ($valor <= 0) {
                throw new GasError("Legalización, línea $n: el valor debe ser mayor que cero.");
            }
            $cc = isset($l['centro_costo']) && trim($l['centro_costo']) !== '' ? trim($l['centro_costo']) : (string) $sol['CENTRO_COSTOS'];
            if ($cc === '') {
                throw new GasError("Legalización, línea $n: indica el centro de costo.");
            }
            $valor = round($valor, 2);
            $rf = (in_array($tipo, GasConfig::$RETEFUENTE_TIPOS, true) && $valor > GasConfig::RETEFUENTE_TOPE)
                ? round($valor * GasConfig::RETEFUENTE_TASA) : 0;
            $suma += $valor;
            $rete += $rf;
            $sql[] = 'INSERT INTO T_GAS_LEGALIZACION_DETALLE (SOLICITUD_ID, FECHA, CENTRO_COSTO, NUM_DOCUMENTO, DETALLE, TIPO_GASTO, VALOR, RETEFUENTE) VALUES ('
                . (int) $sol['ID'] . ', ' . GasDb::fecha($fecha) . ', ' . GasDb::str($cc, 40) . ', '
                . GasDb::str(isset($l['documento']) ? $l['documento'] : null, 30) . ', ' . GasDb::str($det, 200) . ', '
                . GasDb::str($tipo) . ', ' . GasDb::num($valor) . ', ' . GasDb::num($rf) . ')';
        }
        if (!$sql) {
            throw new GasError('Agrega al menos un gasto a la legalización.');
        }

        $g = GasArchivos::guardarPdf(isset($files['soportes_legalizacion']) ? $files['soportes_legalizacion'] : null,
            'Soportes de la legalización (un solo PDF)');
        self::$rutasNuevas[] = $g['ruta'];
        self::registrarArchivo($sol['ID'], $paso['ORDEN'], 'LEGALIZACION', $g, $u);

        GasDb::query('DELETE FROM T_GAS_LEGALIZACION_DETALLE WHERE SOLICITUD_ID = ' . (int) $sol['ID']);
        foreach ($sql as $q) {
            GasDb::query($q);
        }
        $totalCuenta = round($suma - $rete, 2);
        $recibido    = isset($sol['VALOR_ANTICIPO']) ? (float) $sol['VALOR_ANTICIPO'] : 0.0;
        $saldo       = round($recibido - $totalCuenta, 2);
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET NUM_PRELIMINAR = ' . GasDb::str($num, 30) . ', VALOR_TOTAL = ' . GasDb::num($totalCuenta)
            . ', VALOR_LEGALIZADO = ' . GasDb::num($totalCuenta) . ', RETEFUENTE_LEGALIZACION = ' . GasDb::num($rete)
            . ', SALDO_LEGALIZACION = ' . GasDb::num($saldo) . ' WHERE ID = ' . (int) $sol['ID']);
    }

    /** Inserta el registro de un PDF ya guardado y devuelve su ID. */
    private static function registrarArchivo($solId, $pasoOrden, $categoria, $g, $u)
    {
        return GasDb::insert('INSERT INTO T_GAS_ARCHIVOS (SOLICITUD_ID, PASO_ORDEN, CATEGORIA, NOMBRE_ORIGINAL, RUTA, TAMANO, USUARIO_ID) VALUES ('
            . (int) $solId . ', ' . (int) $pasoOrden . ', ' . GasDb::str($categoria) . ', ' . GasDb::str($g['nombre'], 200) . ', '
            . GasDb::str($g['ruta']) . ', ' . (int) $g['tamano'] . ', ' . (int) $u['id'] . ')');
    }
}
