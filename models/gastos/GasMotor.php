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

            if ($decision === 'DEVUELTO') {
                self::devolver($sol, $paso, $u, $comentario);
            } elseif ($decision === 'RECHAZADO') {
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

    /**
     * Devuelve la solicitud al último paso del solicitante para que corrija (p. ej. Gerencia no acepta
     * un valor). Ese paso vuelve a quedar en curso con sus datos prellenados; los pasos posteriores
     * (incluido el actual y los omitidos) vuelven a PENDIENTE y se re-evalúan al avanzar. Se deja
     * constancia en T_GAS_SOLICITUD_EVENTOS con el comentario. Debe llamarse dentro de una transacción.
     */
    private static function devolver($sol, $paso, $u, $comentario)
    {
        $solId = (int) $sol['ID'];
        $dest  = GasDb::row("SELECT TOP 1 ID, ORDEN, NOMBRE FROM T_GAS_SOLICITUD_PASOS
                              WHERE SOLICITUD_ID = $solId AND ORDEN < " . (int) $paso['ORDEN'] . "
                                AND ESTADO = 'COMPLETADO' AND RESPONSABLE_TIPO = 'SOLICITANTE' ORDER BY ORDEN DESC");
        if (!$dest) {
            throw new GasError('No hay un paso del solicitante al cual devolver la solicitud.');
        }
        GasDb::query("UPDATE T_GAS_SOLICITUD_PASOS SET ESTADO = 'PENDIENTE', EJECUTADO_POR = NULL, DECISION = NULL, COMENTARIO = NULL,
                             FECHA_INICIO = NULL, FECHA_FIN = NULL
                       WHERE SOLICITUD_ID = $solId AND ORDEN > " . (int) $dest['ORDEN'] . ' AND ORDEN <= ' . (int) $paso['ORDEN']);
        GasDb::query("UPDATE T_GAS_SOLICITUD_PASOS SET ESTADO = 'ACTUAL', DECISION = NULL, FECHA_INICIO = GETDATE(), FECHA_FIN = NULL
                       WHERE ID = " . (int) $dest['ID']);
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET PASO_ACTUAL = ' . (int) $dest['ORDEN'] . ', FECHA_MODIFICACION = GETDATE() WHERE ID = ' . $solId);
        GasDb::query('INSERT INTO T_GAS_SOLICITUD_EVENTOS (SOLICITUD_ID, TIPO, PASO_ORDEN, PASO_NOMBRE, DESTINO_ORDEN, DESTINO_NOMBRE, USUARIO_ID, COMENTARIO) VALUES ('
            . $solId . ", 'DEVUELTO', " . (int) $paso['ORDEN'] . ', ' . GasDb::str($paso['NOMBRE'], 120) . ', ' . (int) $dest['ORDEN'] . ', '
            . GasDb::str($dest['NOMBRE'], 120) . ', ' . (int) $u['id'] . ', ' . GasDb::str($comentario, 500) . ')');
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

    /** SUBIR_COTIZACIONES: 1 a 3 PDF (proveedor y valor obligatorios por cada PDF) + proceso + si requiere soporte de pago. */
    private static function hSubirCotizaciones($sol, $paso, $in, $files, $u)
    {
        $proceso = self::proceso($in, true);
        $soporte = self::siNo($in, 'requiere_soporte', 'Indica si requiere soporte de pago.');
        $desc    = self::texto($in, 'descripcion', 500, 'Describe brevemente el gasto.');

        // Solo una cotización es obligatoria. Por cada PDF adjunto, el proveedor y el valor son obligatorios.
        $cots  = isset($in['cot']) && is_array($in['cot']) ? $in['cot'] : array();
        $datos = array();
        for ($n = 1; $n <= 3; $n++) {
            $f    = isset($files['cot_' . $n]) ? $files['cot_' . $n] : null;
            $hay  = $f && isset($f['error']) && $f['error'] !== UPLOAD_ERR_NO_FILE;
            $c    = isset($cots[$n]) && is_array($cots[$n]) ? $cots[$n] : array();
            $prov = isset($c['proveedor']) ? trim($c['proveedor']) : '';
            $valor = isset($c['valor']) ? GasDb::aNumero($c['valor']) : 0;
            if (!$hay) {
                if ($prov !== '' || $valor > 0) {
                    throw new GasError("Cotización $n: escribiste proveedor o valor pero no adjuntaste el PDF.");
                }
                continue;
            }
            if ($prov === '') {
                throw new GasError("Cotización $n: el proveedor es obligatorio cuando adjuntas el archivo.");
            }
            if ($valor <= 0) {
                throw new GasError("Cotización $n: el valor es obligatorio cuando adjuntas el archivo.");
            }
            $g = GasArchivos::guardarPdf($f, "Cotización $n");
            self::$rutasNuevas[] = $g['ruta'];
            $datos[$n] = array('proveedor' => $prov, 'valor' => $valor,
                'archivo' => self::registrarArchivo($sol['ID'], $paso['ORDEN'], 'COTIZACION', $g, $u));
        }
        if (!$datos) {
            throw new GasError('Adjunta al menos una cotización en PDF.');
        }
        // Si la solicitud fue devuelta para corrección, las cotizaciones anteriores se reemplazan.
        GasDb::query('DELETE FROM T_GAS_COTIZACIONES WHERE SOLICITUD_ID = ' . (int) $sol['ID']);
        foreach ($datos as $n => $d) {
            GasDb::query('INSERT INTO T_GAS_COTIZACIONES (SOLICITUD_ID, NUMERO, PROVEEDOR, VALOR, ARCHIVO_ID) VALUES ('
                . (int) $sol['ID'] . ', ' . $n . ', ' . GasDb::str($d['proveedor'], 150) . ', ' . GasDb::num($d['valor']) . ', ' . (int) $d['archivo'] . ')');
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET PROCESO_ID = ' . (int) $proceso['ID'] . ', PROCESO = ' . GasDb::str($proceso['PROCESO'], 100)
            . ', REQUIERE_SOPORTE_PAGO = ' . GasDb::bit($soporte) . ', DESCRIPCION = ' . GasDb::str($desc, 500) . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'COMPLETADO');
    }

    /** AUTORIZAR_COTIZACION: elige 1 de las 3 (el comentario es opcional), o devuelve / rechaza. */
    private static function hAutorizarCotizacion($sol, $paso, $in, $files, $u)
    {
        $d          = self::decision($in);
        $comentario = self::textoOpcional($in, 'comentario', 500);
        if ($d !== 'APROBAR') {
            return array('decision' => $d === 'DEVOLVER' ? 'DEVUELTO' : 'RECHAZADO', 'comentario' => $comentario);
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
     *    factura, factura en PDF) y el paso «Legalización» queda realizado, de modo que sigue la
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

        // El paso "Legalización" (si es del solicitante) ya quedó hecho: se marca completado para que no se repita.
        $pendiente = GasDb::row("SELECT TOP 1 ID FROM T_GAS_SOLICITUD_PASOS
                                  WHERE SOLICITUD_ID = " . (int) $sol['ID'] . " AND ESTADO = 'PENDIENTE' AND ACCION = 'MONTAR_PRELIMINAR'
                                    AND RESPONSABLE_TIPO = 'SOLICITANTE' AND ORDEN > " . (int) $paso['ORDEN'] . ' ORDER BY ORDEN');
        if ($pendiente) {
            GasDb::query("UPDATE T_GAS_SOLICITUD_PASOS SET ESTADO = 'COMPLETADO', DECISION = 'COMPLETADO', EJECUTADO_POR = " . (int) $u['id']
                . ", COMENTARIO = 'Registrado al definir que iba a legalizar', FECHA_INICIO = GETDATE(), FECHA_FIN = GETDATE() WHERE ID = " . (int) $pendiente['ID']);
        }
        return array('decision' => 'COMPLETADO');
    }

    /**
     * SOLICITAR_ANTICIPO (flujo de anticipo): por factura (valor) o por viáticos (formato de solicitud
     * de anticipo de viáticos). Siempre se indica si requiere soporte de pago. En viáticos, el formato
     * impreso y firmado y el finiquito son obligatorios.
     */
    private static function hSolicitarAnticipo($sol, $paso, $in, $files, $u)
    {
        $tipo = isset($in['tipo_anticipo']) ? $in['tipo_anticipo'] : '';
        if ($tipo !== 'FACTURA' && $tipo !== 'VIATICOS') {
            throw new GasError('Elige el tipo de anticipo: por factura o por viáticos.');
        }
        $proceso = self::proceso($in, true);
        $soporte = self::siNo($in, 'requiere_soporte', 'Indica si requiere soporte de pago.');
        // En viáticos la descripción es el motivo del viaje (formato de solicitud de viáticos).
        $desc    = $tipo === 'VIATICOS'
            ? self::texto($in, 'viaticos_motivo', 500, 'Indica el motivo de la solicitud.')
            : self::texto($in, 'descripcion', 500, 'Describe brevemente el gasto.');

        if ($tipo === 'FACTURA') {
            // El anticipo por factura se reparte entre uno o más centros de costo (la suma = valor del anticipo).
            $valor   = self::valorPositivo($in, 'valor_anticipo', 'Indica el valor del anticipo.');
            $centros = GasCatalogo::validarCentros(isset($in['centros']) ? $in['centros'] : array(), $valor,
                array('GasListasModel', 'centroExiste'), 'al valor del anticipo');
            self::guardarTercero($sol['ID'], $in, false);
            self::guardarCentros($sol['ID'], $centros, 'ANTICIPO');
        } else {
            // En el formato de viáticos el cargo es el nombre del rol del solicitante.
            $rol = GasDb::scalar('SELECT TITULO FROM T_ROLES WHERE ID = ' . (int) $u['rolId']);
            if ($rol !== null && trim($rol) !== '') {
                $in['cargo'] = trim($rol);
            }
            self::guardarTercero($sol['ID'], $in);
            GasDb::query("DELETE FROM T_GAS_CENTROS_SOLICITUD WHERE SOLICITUD_ID = " . (int) $sol['ID'] . " AND ETAPA = 'ANTICIPO'");
            $valor = self::guardarViaticos($sol, $in);
            $cod   = GasConfig::formatosViaticos($sol['ORGANIZACION_VENTA']);
            self::archivoObligatorio($sol, $paso, $files, 'formato_firmado', 'FORMATO_FIRMADO', 'el formato ' . $cod['solicitud'] . ' impreso y firmado', $u);
            self::archivoObligatorio($sol, $paso, $files, 'finiquito', 'FINIQUITO', 'el finiquito', $u);
        }
        GasDb::query("UPDATE T_GAS_SOLICITUDES SET REQUIERE_ANTICIPO = 1, TIPO_ANTICIPO = " . GasDb::str($tipo) . ', VALOR_ANTICIPO = ' . GasDb::num($valor)
            . ', PROCESO_ID = ' . (int) $proceso['ID'] . ', PROCESO = ' . GasDb::str($proceso['PROCESO'], 100)
            . ', REQUIERE_SOPORTE_PAGO = ' . GasDb::bit($soporte)
            . ', DESCRIPCION = ' . GasDb::str($desc, 500) . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'COMPLETADO');
    }

    /**
     * Reemplaza el reparto por centros de costo de una etapa (ANTICIPO o LEGALIZACION) y deja el primer
     * centro como centro de costos principal de la solicitud.
     */
    private static function guardarCentros($solId, $centros, $etapa)
    {
        GasDb::query('DELETE FROM T_GAS_CENTROS_SOLICITUD WHERE SOLICITUD_ID = ' . (int) $solId . ' AND ETAPA = ' . GasDb::str($etapa));
        foreach ($centros as $c) {
            GasDb::query('INSERT INTO T_GAS_CENTROS_SOLICITUD (SOLICITUD_ID, CENTRO_COSTO, VALOR, ETAPA) VALUES ('
                . (int) $solId . ', ' . GasDb::str($c['centro'], 40) . ', ' . GasDb::num($c['valor']) . ', ' . GasDb::str($etapa) . ')');
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET CENTRO_COSTOS = ' . GasDb::str($centros[0]['centro'], 40) . ' WHERE ID = ' . (int) $solId);
    }

    /**
     * Adjunto obligatorio (PDF o imagen). Si la solicitud fue devuelta para corrección y ya tiene un
     * archivo de esa categoría, no hace falta subirlo de nuevo (si se sube, queda también el nuevo).
     */
    private static function archivoObligatorio($sol, $paso, $files, $campo, $categoria, $etiqueta, $u)
    {
        $f   = isset($files[$campo]) ? $files[$campo] : null;
        $hay = $f && isset($f['error']) && $f['error'] !== UPLOAD_ERR_NO_FILE;
        if (!$hay) {
            $previo = (int) GasDb::scalar('SELECT COUNT(*) FROM T_GAS_ARCHIVOS WHERE SOLICITUD_ID = ' . (int) $sol['ID'] . ' AND CATEGORIA = ' . GasDb::str($categoria));
            if ($previo > 0) {
                return;
            }
            throw new GasError('Adjunta ' . $etiqueta . ' (PDF o imagen).');
        }
        $g = GasArchivos::guardarSoporte($f, ucfirst($etiqueta));
        self::$rutasNuevas[] = $g['ruta'];
        self::registrarArchivo($sol['ID'], $paso['ORDEN'], $categoria, $g, $u);
    }

    /**
     * ¿Requiere soporte de pago? Toda solicitud debe indicarlo. Si el formulario trae la respuesta se
     * guarda; si no la trae, la solicitud ya debe tenerla (se respondió en un paso anterior).
     */
    private static function soportePago($sol, $in)
    {
        if (isset($in['requiere_soporte']) && $in['requiere_soporte'] !== '') {
            $v = self::siNo($in, 'requiere_soporte', 'Indica si requiere soporte de pago.');
            GasDb::query('UPDATE T_GAS_SOLICITUDES SET REQUIERE_SOPORTE_PAGO = ' . GasDb::bit($v) . ' WHERE ID = ' . (int) $sol['ID']);
            return $v;
        }
        if (!isset($sol['REQUIERE_SOPORTE_PAGO']) || $sol['REQUIERE_SOPORTE_PAGO'] === null) {
            throw new GasError('Indica si requiere soporte de pago.');
        }
        return (bool) $sol['REQUIERE_SOPORTE_PAGO'];
    }

    /** MONTAR_PRELIMINAR: número de preliminar, fecha y valor de la factura, tercero y PDF de la factura. */
    private static function hMontarPreliminar($sol, $paso, $in, $files, $u)
    {
        self::registrarPreliminar($sol, $paso, $in, $files, $u);
        return array('decision' => 'COMPLETADO');
    }

    /**
     * Registra el preliminar y la factura. Lo usan el paso «Legalización» y la decisión
     * «legalizar» (DECISION_ANTICIPO). $paso es el paso que se está ejecutando.
     * Con $soloFactura solo se recibe la factura en PDF (sin soporte de pago ni preliminar en PDF).
     */
    private static function registrarPreliminar($sol, $paso, $in, $files, $u, $soloFactura = false)
    {
        // Toda solicitud indica si requiere soporte de pago (si no se respondió antes, se pide aquí).
        self::soportePago($sol, $in);

        // Anticipo por viáticos: en lugar de la factura se diligencia el formato de legalización.
        if (isset($sol['TIPO_ANTICIPO']) && $sol['TIPO_ANTICIPO'] === 'VIATICOS') {
            self::registrarLegalizacion($sol, $paso, $in, $files, $u);
            return;
        }
        $num   = self::texto($in, 'num_preliminar', 30, 'Indica el número de preliminar.');
        if ((function_exists('mb_strlen') ? mb_strlen($num, 'UTF-8') : strlen($num)) > GasConfig::MAX_PRELIMINAR) {
            throw new GasError('El número de preliminar debe tener máximo ' . GasConfig::MAX_PRELIMINAR . ' caracteres.');
        }
        $valor = self::valorPositivo($in, 'valor_total', 'Indica el valor de la factura.');
        $fechaF = isset($in['fecha_factura']) ? trim($in['fecha_factura']) : '';
        if (GasDb::fecha($fechaF) === 'NULL') {
            throw new GasError('Indica la fecha de la factura.');
        }
        // ¿De dónde sale el pago? Roma (por defecto), Fondo, Nota o Tarjeta.
        // Fondo y Nota llevan su número; Tarjeta, los últimos 4 dígitos, el titular y el banco.
        $fondos = GasCatalogo::fondos($sol['ORGANIZACION_VENTA']);
        $fondo  = isset($in['fondo']) && $in['fondo'] !== '' ? $in['fondo'] : 'ROMA';
        if (!isset($fondos[$fondo])) {
            throw new GasError('Elige de dónde sale el pago: ' . implode(', ', $fondos) . '.');
        }
        $fondoNum = null;
        if (in_array($fondo, GasCatalogo::fondosConNumero(), true)) {
            $fondoNum = self::texto($in, 'fondo_numero', 30, $fondo === 'NOTA' ? 'Indica el número de la nota.' : 'Indica el número del fondo.');
        }
        $tarjeta = $fondo === 'TARJETA' ? GasCatalogo::validarTarjeta($in) : array(null, null, null);

        // El valor de la factura se reparte entre uno o más centros de costo (la suma debe cuadrar).
        $centros = GasCatalogo::validarCentros(isset($in['centros']) ? $in['centros'] : array(), $valor, array('GasListasModel', 'centroExiste'));
        self::guardarTercero($sol['ID'], $in, false);

        // Si el flujo no pidió proceso antes (p. ej. flujo de factura), se pide aquí.
        if (empty($sol['PROCESO_ID'])) {
            $proceso = self::proceso($in, true);
            GasDb::query('UPDATE T_GAS_SOLICITUDES SET PROCESO_ID = ' . (int) $proceso['ID'] . ', PROCESO = ' . GasDb::str($proceso['PROCESO'], 100)
                . ' WHERE ID = ' . (int) $sol['ID']);
        }
        if (empty($sol['DESCRIPCION']) && !empty($in['descripcion'])) {
            GasDb::query('UPDATE T_GAS_SOLICITUDES SET DESCRIPCION = ' . GasDb::str($in['descripcion'], 500) . ' WHERE ID = ' . (int) $sol['ID']);
        }

        // La factura en PDF es obligatoria. El soporte de pago lo sube Tesorería al pagar (si se pidió);
        // aquí el solicitante puede adjuntar, si quiere, un soporte del proveedor.
        $archivos = array(
            'factura_pdf'    => array('FACTURA', 'Factura (PDF)', true),
            'soporte_pago'   => array('SOPORTE_PAGO', 'Soporte de pago', false),
            'preliminar_pdf' => array('PRELIMINAR', 'Preliminar (PDF)', false),
            'contrato_pdf'   => array('CONTRATO', 'Contrato u otro soporte (PDF)', false), // para que GA contraste la factura con lo pactado
        );
        if ($soloFactura) {
            $archivos = array('factura_pdf' => $archivos['factura_pdf'], 'contrato_pdf' => $archivos['contrato_pdf']);
        }
        foreach ($archivos as $campo => $meta) {
            $f   = isset($files[$campo]) ? $files[$campo] : null;
            $hay = $f && isset($f['error']) && $f['error'] !== UPLOAD_ERR_NO_FILE;
            if (!$hay && $meta[2]) {
                throw new GasError('Adjunta la factura en PDF.');
            }
            if ($hay) {
                $g = GasArchivos::guardarPdf($f, $meta[1]);
                self::$rutasNuevas[] = $g['ruta'];
                self::registrarArchivo($sol['ID'], $paso['ORDEN'], $meta[0], $g, $u);
            }
        }
        self::guardarCentros($sol['ID'], $centros, 'LEGALIZACION');
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET NUM_PRELIMINAR = ' . GasDb::str($num, 30) . ', VALOR_TOTAL = ' . GasDb::num($valor)
            . ', FECHA_FACTURA = ' . GasDb::fecha($fechaF) . ', FONDO = ' . GasDb::str($fondo) . ', FONDO_NUMERO = ' . GasDb::str($fondoNum, 30)
            . ', TARJETA_ULTIMOS4 = ' . GasDb::str($tarjeta[0], 4) . ', TARJETA_TITULAR = ' . GasDb::str($tarjeta[1], 150)
            . ', TARJETA_BANCO = ' . GasDb::str($tarjeta[2], 80) . ' WHERE ID = ' . (int) $sol['ID']);
    }

    /** APROBAR: aprueba o rechaza (anticipo o preliminar, según cómo se llame el paso). */
    private static function hAprobar($sol, $paso, $in, $files, $u)
    {
        $d   = self::decision($in);
        $com = self::textoOpcional($in, 'comentario', 500); // el comentario es opcional en todas las decisiones
        $map = array('APROBAR' => 'APROBADO', 'DEVOLVER' => 'DEVUELTO', 'RECHAZAR' => 'RECHAZADO');
        return array('decision' => $map[$d], 'comentario' => $com);
    }

    /**
     * CONTABILIZAR. Primera vez: número de contabilización (empieza por 19 o 17, máx. 10 dígitos) y, si el
     * flujo lo junta, la compensación opcional. Si la solicitud ya tiene número de contabilización
     * (p. ej. el del anticipo), este paso es la COMPENSACIÓN: el número de compensación es obligatorio
     * y el formulario lo trae por defecto con el de contabilización. Puede adjuntar un archivo o
     * una imagen pegada (pantallazo).
     */
    private static function hContabilizar($sol, $paso, $in, $files, $u)
    {
        $comp = isset($in['num_compensacion']) ? trim($in['num_compensacion']) : '';
        if (!empty($sol['NUM_CONTABILIZACION'])) {
            if ($comp === '') {
                throw new GasError('Indica el número de compensación.');
            }
            $num = $sol['NUM_CONTABILIZACION'];
        } else {
            $num = self::numeroContabilizacion($in);
        }
        self::guardarSoporteOpcional($sol, $paso, $files, 'soporte', 'SOPORTE_CONTAB', 'Soporte de la contabilización', $u);
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET NUM_CONTABILIZACION = ' . GasDb::str($num, 30) . ', NUM_COMPENSACION = ' . GasDb::str($comp, 30)
            . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'COMPLETADO', 'comentario' => self::textoOpcional($in, 'comentario', 500));
    }

    /** Número de contabilización: solo dígitos, empieza por 19 o por 17 y tiene máximo 10. */
    private static function numeroContabilizacion($in)
    {
        $num = isset($in['num_contabilizacion']) ? trim($in['num_contabilizacion']) : '';
        if ($num === '') {
            throw new GasError('Indica el número de contabilización.');
        }
        if (!GasCatalogo::contabilizacionValida($num)) {
            throw new GasError('El número de contabilización debe empezar por 19 o por 17, ser solo dígitos y tener máximo 10.');
        }
        return $num;
    }

    /** Guarda un soporte (PDF o imagen) si vino; no hace nada si no se adjuntó. */
    private static function guardarSoporteOpcional($sol, $paso, $files, $campo, $categoria, $etiqueta, $u)
    {
        $f = isset($files[$campo]) ? $files[$campo] : null;
        if (!$f || !isset($f['error']) || $f['error'] === UPLOAD_ERR_NO_FILE) {
            return;
        }
        $g = GasArchivos::guardarSoporte($f, $etiqueta);
        self::$rutasNuevas[] = $g['ruta'];
        self::registrarArchivo($sol['ID'], $paso['ORDEN'], $categoria, $g, $u);
    }

    /**
     * PAGAR: número de comprobante ZP y fecha de pago. El soporte de pago (PDF o imagen) es obligatorio
     * solo si el solicitante indicó que lo requiere; si dijo que no, Tesorería no lo sube.
     * (Solicitudes antiguas sin respuesta: el soporte es opcional.)
     */
    private static function hPagar($sol, $paso, $in, $files, $u)
    {
        $zp    = self::texto($in, 'num_comprobante_zp', 30, 'Indica el número de comprobante ZP.');
        $fecha = isset($in['fecha_pago']) ? trim($in['fecha_pago']) : '';
        if (GasDb::fecha($fecha) === 'NULL') {
            throw new GasError('Indica una fecha de pago válida.');
        }
        $req = isset($sol['REQUIERE_SOPORTE_PAGO']) ? $sol['REQUIERE_SOPORTE_PAGO'] : null;
        if ($req !== null && (int) $req === 1) {
            $g = GasArchivos::guardarSoporte(isset($files['comprobante']) ? $files['comprobante'] : null, 'Soporte de pago'); // PDF o imagen pegada
            self::$rutasNuevas[] = $g['ruta'];
            self::registrarArchivo($sol['ID'], $paso['ORDEN'], 'COMPROBANTE', $g, $u);
        } elseif ($req === null) {
            self::guardarSoporteOpcional($sol, $paso, $files, 'comprobante', 'COMPROBANTE', 'Soporte de pago', $u);
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET NUM_COMPROBANTE_ZP = ' . GasDb::str($zp, 30) . ', FECHA_PAGO = ' . GasDb::fecha($fecha)
            . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'COMPLETADO', 'comentario' => self::textoOpcional($in, 'comentario', 500));
    }

    /**
     * PAGAR_SALDO: legalización de viáticos con saldo a favor del empleado. Después de la compensación
     * de Contabilidad, Tesorería evidencia que le devolvió el dinero: número de comprobante, fecha y
     * soporte de la transferencia (PDF o imagen). Con este paso finaliza la solicitud.
     */
    private static function hPagarSaldo($sol, $paso, $in, $files, $u)
    {
        $saldo = isset($sol['SALDO_LEGALIZACION']) ? round((float) $sol['SALDO_LEGALIZACION'], 2) : 0.0;
        if ($saldo >= 0) {
            throw new GasError('Esta solicitud no tiene saldo a favor del empleado.');
        }
        $num   = self::texto($in, 'num_comprobante_saldo', 30, 'Indica el número del comprobante de la devolución.');
        $fecha = isset($in['fecha_pago_saldo']) ? trim($in['fecha_pago_saldo']) : '';
        if (GasDb::fecha($fecha) === 'NULL') {
            throw new GasError('Indica una fecha válida de la devolución.');
        }
        $g = GasArchivos::guardarSoporte(isset($files['soporte_saldo']) ? $files['soporte_saldo'] : null, 'Soporte de la devolución al empleado');
        self::$rutasNuevas[] = $g['ruta'];
        self::registrarArchivo($sol['ID'], $paso['ORDEN'], 'DEVOLUCION_SALDO', $g, $u);
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET NUM_COMPROBANTE_SALDO = ' . GasDb::str($num, 30) . ', FECHA_PAGO_SALDO = ' . GasDb::fecha($fecha)
            . ' WHERE ID = ' . (int) $sol['ID']);
        return array('decision' => 'COMPLETADO', 'comentario' => self::textoOpcional($in, 'comentario', 500));
    }

    /* =========================================================================
       AUXILIARES DE VALIDACIÓN Y GUARDADO
       ========================================================================= */

    /** true = APROBAR, false = RECHAZAR. */
    /** 'APROBAR' | 'DEVOLVER' (al solicitante para corrección) | 'RECHAZAR'. */
    private static function decision($in)
    {
        $d = isset($in['decision']) ? $in['decision'] : '';
        if (!in_array($d, array('APROBAR', 'DEVOLVER', 'RECHAZAR'), true)) {
            throw new GasError('Elige si apruebas, devuelves para corrección o rechazas.');
        }
        return $d;
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

    /** Texto opcional: devuelve null si viene vacío. */
    private static function textoOpcional($in, $campo, $max)
    {
        $v = isset($in[$campo]) ? trim($in[$campo]) : '';
        if ($v === '') {
            return null;
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

    /**
     * Valida y guarda los datos del tercero en la solicitud (nit, razón social, celular, correo, cargo y,
     * con $conCentro, el centro de costos; en la legalización el centro sale del reparto por centros).
     */
    private static function guardarTercero($solId, $in, $conCentro = true)
    {
        $nit    = preg_replace('/[^0-9A-Za-z\-]/', '', isset($in['tercero_nit']) ? $in['tercero_nit'] : '');
        $nombre = self::texto($in, 'tercero_nombre', 150, 'Indica la razón social del tercero.');
        $cel    = preg_replace('/\D/', '', isset($in['tercero_celular']) ? $in['tercero_celular'] : '');
        $mail   = isset($in['tercero_email']) ? trim($in['tercero_email']) : '';
        $cargo  = isset($in['cargo']) ? trim($in['cargo']) : ''; // el cargo ya no se pide (solo va en el F-FR-023)
        $cc     = $conCentro ? self::texto($in, 'centro_costos', 40, 'Selecciona el centro de costos.') : null;
        if ($conCentro && !GasListasModel::centroExiste($cc)) {
            throw new GasError('El centro de costos no existe: selecciónalo de la lista.');
        }
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
            . ', CARGO = ' . GasDb::str($cargo, 80) . ($conCentro ? ', CENTRO_COSTOS = ' . GasDb::str($cc, 40) : '') . ' WHERE ID = ' . (int) $solId);
    }

    /**
     * Solicitud de anticipo de viáticos (formato F-FR-023 en la org. 2000, F-FR-0137 en la 1000; ver
     * GasConfig::$FORMATOS_VIATICOS). Guarda motivo, fechas y el valor presupuestado por concepto, y
     * devuelve el total solicitado (recalculado en el servidor).
     * Validaciones: salida >= hoy, regreso >= salida, "Otros" con valor exige decir cuál,
     * total > 0 y autorización de descuento aceptada.
     */
    private static function guardarViaticos($sol, $in)
    {
        $solId = (int) $sol['ID'];
        $cod   = GasConfig::formatosViaticos($sol['ORGANIZACION_VENTA']);
        $F     = $cod['solicitud'];
        $motivo = self::texto($in, 'viaticos_motivo', 300, 'Indica el motivo de la solicitud.');
        $ini    = isset($in['viaticos_fecha_inicio']) ? trim($in['viaticos_fecha_inicio']) : '';
        $fin    = isset($in['viaticos_fecha_fin']) ? trim($in['viaticos_fecha_fin']) : '';
        if (GasDb::fecha($ini) === 'NULL' || GasDb::fecha($fin) === 'NULL') {
            throw new GasError("$F: indica la fecha de salida y la de regreso.");
        }
        if ($ini < date('Y-m-d')) {
            throw new GasError("$F: la fecha de salida no puede ser anterior a hoy (el anticipo se solicita antes del viaje).");
        }
        if ($fin < $ini) {
            throw new GasError("$F: la fecha de regreso no puede ser anterior a la fecha de salida.");
        }

        $conceptos = GasCatalogo::conceptosViaticos();
        $pres  = isset($in['presupuesto']) && is_array($in['presupuesto']) ? $in['presupuesto'] : array();
        $total = 0.0;
        $sql   = array();
        foreach ($conceptos as $cod => $nombre) {
            $v = isset($pres[$cod]) ? GasDb::aNumero($pres[$cod]) : 0;
            if ($v < 0) {
                throw new GasError("$F: el valor de «{$nombre}» no puede ser negativo.");
            }
            if ($v == 0) {
                continue;
            }
            $desc = $cod === 'OTROS' ? self::texto($in, 'presupuesto_otros', 200, "$F: indica cuál es el concepto «Otros».") : null;
            $total += round($v, 2);
            $sql[] = 'INSERT INTO T_GAS_VIATICOS_DETALLE (SOLICITUD_ID, CONCEPTO, DESCRIPCION, CANTIDAD, VALOR_UNITARIO, VALOR_TOTAL) VALUES ('
                . (int) $solId . ', ' . GasDb::str($cod) . ', ' . GasDb::str($desc, 200) . ', 1, ' . GasDb::num($v) . ', ' . GasDb::num($v) . ')';
        }
        if ($total <= 0) {
            throw new GasError("$F: indica al menos un valor presupuestado.");
        }
        if (!isset($in['acepta_descuento']) || $in['acepta_descuento'] !== 'SI') {
            throw new GasError("$F: debes aceptar la autorización de descuento (art. 150 y 151 CST).");
        }

        GasDb::query('DELETE FROM T_GAS_VIATICOS_DETALLE WHERE SOLICITUD_ID = ' . (int) $solId);
        foreach ($sql as $q) {
            GasDb::query($q);
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET VIATICOS_MOTIVO = ' . GasDb::str($motivo, 300)
            . ', VIATICOS_FECHA_INICIO = ' . GasDb::fecha($ini) . ', VIATICOS_FECHA_FIN = ' . GasDb::fecha($fin)
            . ', VIATICOS_TEL_FIJO = ' . GasDb::str(isset($in['viaticos_tel_fijo']) ? preg_replace('/[^0-9 ]/', '', $in['viaticos_tel_fijo']) : null, 20)
            . ', VIATICOS_ACEPTA_DESCUENTO = 1 WHERE ID = ' . (int) $solId);
        return $total;
    }

    /**
     * Legalización de viáticos, formato F-FR-024 / F-FR-0138 según la organización (en el paso del preliminar).
     * Cada fila del formato: fecha, centro de costo, doc, ciudad y detalles y un valor en una o más
     * columnas (Transp, Bus/taxis, Hotel, Aliment, Atención, Gasolina, Servicios, Otros). Cada valor
     * se guarda como una línea de T_GAS_LEGALIZACION_DETALLE (TIPO_GASTO = columna).
     * Validaciones: la fecha es la del consumo (no posterior a hoy), detalle obligatorio y al menos
     * un valor > 0 por fila. No se pide número de preliminar.
     * Retefuente: GasConfig::RETEFUENTE_TASA a cada valor de hotel/alimentación mayor al tope.
     * Total cuenta de gastos = suma - retefuente. Saldo = suma recibida (anticipo) - total cuenta:
     *   > 0 a favor de la empresa (el empleado reintegra; el comprobante va en el PDF de soportes)
     *   < 0 a favor del empleado (se le paga la diferencia)
     */
    private static function registrarLegalizacion($sol, $paso, $in, $files, $u)
    {
        // En la legalización no se pide número de preliminar: el soporte es el formato + el PDF.
        $num   = isset($in['num_preliminar']) ? trim($in['num_preliminar']) : '';
        $cod   = GasConfig::formatosViaticos($sol['ORGANIZACION_VENTA']);
        $F     = $cod['legalizacion'];
        $tipos = GasCatalogo::tiposGastoLegalizacion();
        $hoy   = date('Y-m-d');

        $filas = isset($in['legal']) && is_array($in['legal']) ? $in['legal'] : array();
        $suma = 0.0;
        $rete = 0.0;
        $sql  = array();
        $ccOk = array();
        $n    = 0;
        foreach ($filas as $k => $l) {
            if (!is_array($l)) {
                continue;
            }
            $fecha   = isset($l['fecha']) ? trim($l['fecha']) : '';
            $det     = isset($l['detalle']) ? trim($l['detalle']) : '';
            $valores = isset($l['v']) && is_array($l['v']) ? $l['v'] : array();
            $hayValor = false;
            foreach ($valores as $v) {
                if (GasDb::aNumero($v) != 0) {
                    $hayValor = true;
                }
            }
            if ($fecha === '' && $det === '' && !$hayValor) {
                continue; // fila vacía
            }
            $n++;
            if (GasDb::fecha($fecha) === 'NULL') {
                throw new GasError("$F, fila $n: fecha inválida.");
            }
            // Fecha de consumo del gasto: no puede ser posterior a hoy.
            if ($fecha > $hoy) {
                throw new GasError("$F, fila $n: la fecha es la del consumo y no puede ser posterior a hoy.");
            }
            if ($det === '') {
                throw new GasError("$F, fila $n: escribe la ciudad y el detalle.");
            }
            $cc = isset($l['centro_costo']) && trim($l['centro_costo']) !== '' ? trim($l['centro_costo']) : (string) $sol['CENTRO_COSTOS'];
            if ($cc === '') {
                throw new GasError("$F, fila $n: selecciona el centro de costo.");
            }
            if (!isset($ccOk[$cc])) {
                $ccOk[$cc] = GasListasModel::centroExiste($cc);
            }
            if (!$ccOk[$cc]) {
                throw new GasError("$F, fila $n: el centro de costo «{$cc}» no existe.");
            }
            $totFila = 0.0;
            $lineas  = array();
            foreach ($tipos as $tipo => $nombreTipo) {
                $valor = isset($valores[$tipo]) ? round(GasDb::aNumero($valores[$tipo]), 2) : 0;
                if ($valor < 0) {
                    throw new GasError("$F, fila $n: el valor de «{$nombreTipo}» no puede ser negativo.");
                }
                if ($valor == 0) {
                    continue;
                }
                $rf = (in_array($tipo, GasConfig::$RETEFUENTE_TIPOS, true) && $valor > GasConfig::RETEFUENTE_TOPE)
                    ? round($valor * GasConfig::RETEFUENTE_TASA) : 0;
                $totFila += $valor;
                $rete    += $rf;
                $lineas[] = array($tipo, $valor, $rf);
            }
            if ($totFila <= 0) {
                throw new GasError("$F, fila $n: escribe el valor en alguna columna (Transp, Bus/taxis, Hotel…).");
            }
            // Soporte del gasto (factura o recibo de este ítem, PDF o imagen): obligatorio por fila.
            $archivo = self::soporteGasto($sol, $paso, $files, $k, $l, $u, "$F, fila $n",
                implode('/', array_reverse(explode('-', $fecha))) . ' · ' . $det);
            foreach ($lineas as $li) {
                $sql[] = 'INSERT INTO T_GAS_LEGALIZACION_DETALLE (SOLICITUD_ID, FECHA, CENTRO_COSTO, NUM_DOCUMENTO, DETALLE, TIPO_GASTO, VALOR, RETEFUENTE, ARCHIVO_ID) VALUES ('
                    . (int) $sol['ID'] . ', ' . GasDb::fecha($fecha) . ', ' . GasDb::str($cc, 40) . ', '
                    . GasDb::str(isset($l['documento']) ? $l['documento'] : null, 30) . ', ' . GasDb::str($det, 200) . ', '
                    . GasDb::str($li[0]) . ', ' . GasDb::num($li[1]) . ', ' . GasDb::num($li[2]) . ', ' . (int) $archivo . ')';
            }
            $suma += $totFila;
        }
        if (!$sql) {
            throw new GasError("$F: agrega al menos un gasto.");
        }

        $totalCuenta = round($suma - $rete, 2);
        $recibido    = isset($sol['VALOR_ANTICIPO']) ? (float) $sol['VALOR_ANTICIPO'] : 0.0;
        $saldo       = round($recibido - $totalCuenta, 2);

        // Saldo a favor de la empresa: quien legaliza adjunta el comprobante de la transferencia (PDF o imagen).
        if ($saldo > 0) {
            $r = GasArchivos::guardarSoporte(isset($files['comprobante_reintegro']) ? $files['comprobante_reintegro'] : null,
                'Comprobante de la transferencia a la empresa (hay saldo a favor de la empresa)');
            self::$rutasNuevas[] = $r['ruta'];
            self::registrarArchivo($sol['ID'], $paso['ORDEN'], 'REINTEGRO', $r, $u);
        }

        GasDb::query('DELETE FROM T_GAS_LEGALIZACION_DETALLE WHERE SOLICITUD_ID = ' . (int) $sol['ID']);
        foreach ($sql as $q) {
            GasDb::query($q);
        }
        GasDb::query('UPDATE T_GAS_SOLICITUDES SET NUM_PRELIMINAR = ' . GasDb::str($num, 30) . ', VALOR_TOTAL = ' . GasDb::num($totalCuenta)
            . ', VALOR_LEGALIZADO = ' . GasDb::num($totalCuenta) . ', RETEFUENTE_LEGALIZACION = ' . GasDb::num($rete)
            . ', SALDO_LEGALIZACION = ' . GasDb::num($saldo) . ' WHERE ID = ' . (int) $sol['ID']);
    }

    /**
     * Soporte de un ítem de la legalización de viáticos (campo de archivo legal_sop_<fila>). Si no se
     * adjunta uno nuevo, se acepta el que ya tenía la fila (legal[fila][archivo_id]) cuando la solicitud
     * se devolvió para corrección. Devuelve el ID del archivo.
     */
    private static function soporteGasto($sol, $paso, $files, $k, $l, $u, $donde, $descripcion)
    {
        $campo = 'legal_sop_' . preg_replace('/[^0-9]/', '', (string) $k);
        $f     = isset($files[$campo]) ? $files[$campo] : null;
        if ($f && isset($f['error']) && $f['error'] !== UPLOAD_ERR_NO_FILE) {
            $g = GasArchivos::guardarSoporte($f, "$donde: soporte del gasto");
            self::$rutasNuevas[] = $g['ruta'];
            return self::registrarArchivo($sol['ID'], $paso['ORDEN'], 'SOPORTE_GASTO', $g, $u, $descripcion);
        }
        $previo = isset($l['archivo_id']) ? (int) $l['archivo_id'] : 0;
        if ($previo > 0 && (int) GasDb::scalar('SELECT COUNT(*) FROM T_GAS_ARCHIVOS WHERE ID = ' . $previo . ' AND SOLICITUD_ID = '
                . (int) $sol['ID'] . " AND CATEGORIA = 'SOPORTE_GASTO'") > 0) {
            return $previo;
        }
        throw new GasError("$donde: adjunta el soporte del gasto (PDF o imagen).");
    }

    /** Inserta el registro de un archivo ya guardado y devuelve su ID. $descripcion dice qué soporte es. */
    private static function registrarArchivo($solId, $pasoOrden, $categoria, $g, $u, $descripcion = null)
    {
        return GasDb::insert('INSERT INTO T_GAS_ARCHIVOS (SOLICITUD_ID, PASO_ORDEN, CATEGORIA, NOMBRE_ORIGINAL, RUTA, TAMANO, USUARIO_ID, DESCRIPCION) VALUES ('
            . (int) $solId . ', ' . (int) $pasoOrden . ', ' . GasDb::str($categoria) . ', ' . GasDb::str($g['nombre'], 200) . ', '
            . GasDb::str($g['ruta']) . ', ' . (int) $g['tamano'] . ', ' . (int) $u['id'] . ', ' . GasDb::str($descripcion, 200) . ')');
    }
}
