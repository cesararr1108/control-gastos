<?php
/**
 * Solicitudes de gasto: creación (con copia de los pasos del flujo), consulta y listados.
 * La lógica de avance entre pasos vive en GasMotor.
 */
class GasSolicitudModel
{
    /** Columnas de la solicitud; las fechas salen como texto AAAA-MM-DD [HH:MM:SS]. */
    const COLS = "s.ID, s.CODIGO, s.TIPO, s.FLUJO_ID, s.USUARIO_ID, s.ORGANIZACION_VENTA, s.OFICINA_VENTAS, s.ID_DPTO,
        s.ESTADO, s.PASO_ACTUAL, s.MOTIVO_CIERRE, s.PROCESO_ID, s.PROCESO, s.DESCRIPCION, s.REQUIERE_SOPORTE_PAGO,
        s.COTIZACION_ELEGIDA, s.REQUIERE_ANTICIPO, s.TIPO_ANTICIPO, s.VALOR_ANTICIPO, s.VALOR_TOTAL,
        s.TERCERO_NIT, s.TERCERO_NOMBRE, s.TERCERO_CELULAR, s.TERCERO_EMAIL, s.CARGO, s.CENTRO_COSTOS,
        s.VIATICOS_DESTINO, CONVERT(varchar(10), s.VIATICOS_FECHA_INICIO, 120) AS VIATICOS_FECHA_INICIO,
        CONVERT(varchar(10), s.VIATICOS_FECHA_FIN, 120) AS VIATICOS_FECHA_FIN, s.VIATICOS_MOTIVO,
        s.VIATICOS_TEL_FIJO, s.VIATICOS_RUTA_SALIDA, s.VIATICOS_TIPO_SALIDA, s.VIATICOS_RUTA_REGRESO, s.VIATICOS_TIPO_REGRESO, s.VIATICOS_ACEPTA_DESCUENTO,
        s.NUM_PRELIMINAR, CONVERT(varchar(10), s.FECHA_FACTURA, 120) AS FECHA_FACTURA, s.PAGO_FONDO, s.FONDO, s.VALOR_LEGALIZADO, s.RETEFUENTE_LEGALIZACION, s.SALDO_LEGALIZACION, s.NUM_CONTABILIZACION, s.NUM_COMPENSACION, s.NUM_COMPROBANTE_ZP,
        CONVERT(varchar(10), s.FECHA_PAGO, 120) AS FECHA_PAGO,
        CONVERT(varchar(19), s.FECHA_CREACION, 120) AS FECHA_CREACION,
        CONVERT(varchar(19), s.FECHA_MODIFICACION, 120) AS FECHA_MODIFICACION,
        CONVERT(varchar(19), s.FECHA_FIN, 120) AS FECHA_FIN";

    /** Campos bit que deben llegar al navegador como 0/1/null (no como "0" texto). */
    private static $BITS = array('REQUIERE_SOPORTE_PAGO', 'REQUIERE_ANTICIPO', 'VIATICOS_ACEPTA_DESCUENTO', 'PAGO_FONDO');

    /* ------------------------------------------------------------------ crear */

    /** Flujos entre los que puede elegir el usuario para un tipo (según su organización). */
    public static function variantes($tipo, $u)
    {
        if (!isset(GasCatalogo::tipos()[$tipo])) {
            throw new GasError('Tipo de solicitud inválido.');
        }
        return GasFlujoModel::disponibles($tipo, $u['org']);
    }

    /**
     * Crea una solicitud del tipo indicado con el flujo elegido (o el único disponible para la
     * organización del usuario) y deja el primer paso en curso. Devuelve el ID.
     */
    public static function crear($tipo, $u, $flujoId = null)
    {
        $tipos = GasCatalogo::tipos();
        if (!isset($tipos[$tipo])) {
            throw new GasError('Tipo de solicitud inválido.');
        }
        $flujo = GasFlujoModel::resolver($tipo, $u['org'], $flujoId);
        if (!$flujo) {
            throw new GasError('No hay un flujo activo de ' . $tipos[$tipo]['nombre'] . ' para tu organización. Pide al administrador que lo configure.');
        }
        foreach ($flujo['pasos'] as $p) {
            if (($p['RESPONSABLE_TIPO'] === 'ROL' && !$p['ROL_ID']) || ($p['RESPONSABLE_TIPO'] === 'USUARIO' && !$p['USUARIO_ID'])) {
                throw new GasError('El flujo tiene pasos sin responsable («' . $p['NOMBRE'] . '»). Pide al administrador que lo complete.');
            }
        }

        GasDb::begin();
        try {
            // FACTURA nunca tiene anticipo; ANTICIPO siempre; COTIZACION se decide en el flujo.
            $reqAnt = $tipo === GasCatalogo::TIPO_FACTURA ? '0' : ($tipo === GasCatalogo::TIPO_ANTICIPO ? '1' : 'NULL');
            $id = GasDb::insert(
                'INSERT INTO T_GAS_SOLICITUDES (TIPO, FLUJO_ID, USUARIO_ID, ORGANIZACION_VENTA, OFICINA_VENTAS, ID_DPTO, REQUIERE_ANTICIPO) VALUES ('
                . GasDb::str($tipo) . ', ' . (int) $flujo['ID'] . ', ' . (int) $u['id'] . ', ' . GasDb::str($u['org']) . ', '
                . GasDb::str($u['oficina']) . ', ' . GasDb::int($u['dpto']) . ', ' . $reqAnt . ')'
            );
            GasDb::query('UPDATE T_GAS_SOLICITUDES SET CODIGO = ' . GasDb::str($tipos[$tipo]['prefijo'] . '-' . str_pad($id, 6, '0', STR_PAD_LEFT)) . ' WHERE ID = ' . $id);

            // Copia de los pasos: la solicitud queda inmune a cambios posteriores del flujo.
            foreach ($flujo['pasos'] as $p) {
                GasDb::query(
                    'INSERT INTO T_GAS_SOLICITUD_PASOS (SOLICITUD_ID, ORDEN, ACCION, NOMBRE, RESPONSABLE_TIPO, ROL_ID, USUARIO_ID, CONDICION) VALUES ('
                    . $id . ', ' . (int) $p['ORDEN'] . ', ' . GasDb::str($p['ACCION']) . ', ' . GasDb::str($p['NOMBRE']) . ', '
                    . GasDb::str($p['RESPONSABLE_TIPO']) . ', ' . GasDb::int($p['ROL_ID']) . ', ' . GasDb::int($p['USUARIO_ID']) . ', '
                    . GasDb::str($p['CONDICION']) . ')'
                );
            }
            GasMotor::activarSiguiente($id, 0);
            GasDb::commit();
        } catch (Exception $e) {
            GasDb::rollback();
            throw $e;
        }
        return $id;
    }

    /* --------------------------------------------------------------- consultar */

    /** Fila de la solicitud (sin detalle) o null. */
    public static function fila($id, $bloquear = false)
    {
        $row = GasDb::row('SELECT ' . self::COLS . ' FROM T_GAS_SOLICITUDES s ' . ($bloquear ? 'WITH (UPDLOCK, ROWLOCK)' : '')
            . ' WHERE s.ID = ' . GasDb::int($id));
        return $row ? self::normalizar($row) : null;
    }

    /** Solicitud completa con pasos, archivos, cotizaciones y viáticos. */
    public static function obtener($id)
    {
        $s = self::fila($id);
        if (!$s) {
            return null;
        }
        $sid = (int) $s['ID'];

        $sol = GasDb::row("SELECT LTRIM(RTRIM(NOMBRES)) + ' ' + LTRIM(RTRIM(APELLIDOS)) AS N, EMAIL FROM T_USUARIOS WHERE ID = " . (int) $s['USUARIO_ID']);
        $s['SOLICITANTE']       = $sol ? $sol['N'] : '';
        $s['SOLICITANTE_EMAIL'] = $sol ? $sol['EMAIL'] : '';

        $fn = GasDb::scalar('SELECT NOMBRE FROM T_GAS_FLUJOS WHERE ID = ' . GasDb::int($s['FLUJO_ID']));
        $s['FLUJO_NOMBRE'] = $fn ? $fn : '';

        $ofi = GasDb::scalar('SELECT TOP 1 DESCRIPCION FROM T_OFICINAS_VENTAS WHERE OFICINA_VENTAS = ' . GasDb::str($s['OFICINA_VENTAS']));
        $s['OFICINA_NOMBRE'] = $ofi ? $ofi : '';

        $s['pasos'] = GasDb::all(
            "SELECT p.ID, p.ORDEN, p.ACCION, p.NOMBRE, p.RESPONSABLE_TIPO, p.ROL_ID, p.USUARIO_ID, p.CONDICION, p.ESTADO,
                    p.EJECUTADO_POR, p.DECISION, p.COMENTARIO,
                    CONVERT(varchar(19), p.FECHA_INICIO, 120) AS FECHA_INICIO, CONVERT(varchar(19), p.FECHA_FIN, 120) AS FECHA_FIN,
                    r.TITULO AS ROL_NOMBRE,
                    LTRIM(RTRIM(ur.NOMBRES)) + ' ' + LTRIM(RTRIM(ur.APELLIDOS)) AS USUARIO_NOMBRE,
                    LTRIM(RTRIM(ue.NOMBRES)) + ' ' + LTRIM(RTRIM(ue.APELLIDOS)) AS EJECUTOR_NOMBRE
               FROM T_GAS_SOLICITUD_PASOS p
               LEFT JOIN T_ROLES r ON r.ID = p.ROL_ID
               LEFT JOIN T_USUARIOS ur ON ur.ID = p.USUARIO_ID
               LEFT JOIN T_USUARIOS ue ON ue.ID = p.EJECUTADO_POR
              WHERE p.SOLICITUD_ID = " . $sid . ' ORDER BY p.ORDEN'
        );
        foreach ($s['pasos'] as $i => $p) {
            $s['pasos'][$i]['RESPONSABLE'] = self::etiquetaResponsable($p, $s);
        }

        $s['archivos'] = GasDb::all(
            "SELECT a.ID, a.PASO_ORDEN, a.CATEGORIA, a.NOMBRE_ORIGINAL, a.TAMANO, CONVERT(varchar(19), a.FECHA, 120) AS FECHA
               FROM T_GAS_ARCHIVOS a WHERE a.SOLICITUD_ID = " . $sid . ' ORDER BY a.ID'
        );
        $s['cotizaciones'] = GasDb::all(
            'SELECT NUMERO, PROVEEDOR, VALOR, ARCHIVO_ID FROM T_GAS_COTIZACIONES WHERE SOLICITUD_ID = ' . $sid . ' ORDER BY NUMERO'
        );
        $s['legalizacion'] = GasDb::all(
            'SELECT CONVERT(varchar(10), FECHA, 120) AS FECHA, CENTRO_COSTO, NUM_DOCUMENTO, DETALLE, TIPO_GASTO, VALOR, RETEFUENTE
               FROM T_GAS_LEGALIZACION_DETALLE WHERE SOLICITUD_ID = ' . $sid . ' ORDER BY FECHA, ID'
        );
        $s['viaticos'] = GasDb::all(
            'SELECT CONCEPTO, DESCRIPCION, CANTIDAD, VALOR_UNITARIO, VALOR_TOTAL FROM T_GAS_VIATICOS_DETALLE WHERE SOLICITUD_ID = ' . $sid . ' ORDER BY ID'
        );
        return $s;
    }

    /** Texto legible de quién debe ejecutar el paso. */
    public static function etiquetaResponsable($paso, $sol)
    {
        switch ($paso['RESPONSABLE_TIPO']) {
            case 'SOLICITANTE':
                return 'Solicitante' . (!empty($sol['SOLICITANTE']) ? ' (' . $sol['SOLICITANTE'] . ')' : '');
            case 'USUARIO':
                return isset($paso['USUARIO_NOMBRE']) ? $paso['USUARIO_NOMBRE'] : 'Usuario';
            default:
                return isset($paso['ROL_NOMBRE']) && $paso['ROL_NOMBRE'] !== null ? $paso['ROL_NOMBRE'] : 'Rol sin asignar';
        }
    }

    /** ¿El usuario debe ejecutar este paso? */
    public static function esResponsable($paso, $sol, $u)
    {
        switch ($paso['RESPONSABLE_TIPO']) {
            case 'SOLICITANTE': return (int) $sol['USUARIO_ID'] === (int) $u['id'];
            case 'ROL':         return $paso['ROL_ID'] !== null && (int) $paso['ROL_ID'] === (int) $u['rolId'];
            case 'USUARIO':     return $paso['USUARIO_ID'] !== null && (int) $paso['USUARIO_ID'] === (int) $u['id'];
        }
        return false;
    }

    /** ¿Puede ver la solicitud? Solicitante, administradores y cualquier responsable/ejecutor de sus pasos. */
    public static function puedeVer($sol, $u)
    {
        if ((int) $sol['USUARIO_ID'] === (int) $u['id'] || GasSesion::esAdmin($u)) {
            return true;
        }
        $pasos = isset($sol['pasos']) ? $sol['pasos'] : GasDb::all(
            'SELECT RESPONSABLE_TIPO, ROL_ID, USUARIO_ID, EJECUTADO_POR FROM T_GAS_SOLICITUD_PASOS WHERE SOLICITUD_ID = ' . (int) $sol['ID']
        );
        foreach ($pasos as $p) {
            if (self::esResponsable($p, $sol, $u) || (isset($p['EJECUTADO_POR']) && (int) $p['EJECUTADO_POR'] === (int) $u['id'])) {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------------------------------------------- listados */

    /** FROM común de listados, tarjetas y exportación: la solicitud y su paso en curso. */
    const FROM_LISTA = "FROM T_GAS_SOLICITUDES s
                  LEFT JOIN T_GAS_SOLICITUD_PASOS ps ON ps.SOLICITUD_ID = s.ID AND ps.ESTADO = 'ACTUAL'
                  LEFT JOIN T_ROLES pr ON pr.ID = ps.ROL_ID
                  LEFT JOIN T_USUARIOS su ON su.ID = s.USUARIO_ID
                  LEFT JOIN T_ROLES sr ON sr.ID = su.ROLES_ID
                  LEFT JOIN T_GAS_FLUJOS fl ON fl.ID = s.FLUJO_ID";

    /** Acciones que cuentan como "por aprobar", "en contabilidad" y "en tesorería". */
    const ACC_APROBAR = "'APROBAR','AUTORIZAR_COTIZACION'";

    /**
     * Condiciones de los filtros comunes (arreglo de SQL):
     *  estado, tipo, paso (nombre del paso en curso), q (código / NIT / tercero / preliminar / paso),
     *  desde / hasta (AAAA-MM-DD, fecha de creación) y org (organización de venta).
     */
    private static function filtros($f)
    {
        $w = array();
        if (!empty($f['estado']) && in_array($f['estado'], array('EN_CURSO', 'FINALIZADA', 'RECHAZADA', 'CANCELADA'), true)) {
            $w[] = 's.ESTADO = ' . GasDb::str($f['estado']);
        }
        $tipos = GasCatalogo::tipos();
        if (!empty($f['tipo']) && isset($tipos[$f['tipo']])) {
            $w[] = 's.TIPO = ' . GasDb::str($f['tipo']);
        }
        if (!empty($f['paso'])) {
            $w[] = "s.ESTADO = 'EN_CURSO' AND ps.NOMBRE = " . GasDb::str($f['paso'], 120);
        }
        if (!empty($f['q'])) {
            $q   = str_replace(array('[', '%', '_'), array('[[]', '[%]', '[_]'), trim($f['q']));
            $lit = GasDb::str('%' . $q . '%');
            $w[] = "(s.CODIGO LIKE $lit OR s.TERCERO_NIT LIKE $lit OR s.TERCERO_NOMBRE LIKE $lit OR s.NUM_PRELIMINAR LIKE $lit OR ps.NOMBRE LIKE $lit)";
        }
        if (!empty($f['desde']) && GasDb::fecha($f['desde']) !== 'NULL') {
            $w[] = 's.FECHA_CREACION >= ' . GasDb::fecha($f['desde']);
        }
        if (!empty($f['hasta']) && GasDb::fecha($f['hasta']) !== 'NULL') {
            $w[] = 's.FECHA_CREACION < DATEADD(day, 1, ' . GasDb::fecha($f['hasta']) . ')';
        }
        if (!empty($f['org'])) {
            $w[] = 's.ORGANIZACION_VENTA = ' . GasDb::str($f['org'], 4);
        }
        return $w;
    }

    /**
     * Listado paginado.
     * $vista: 'pendientes' (me toca actuar) | 'mias' | 'historial' (administradores; filtra por fechas)
     * $f: filtros de self::filtros() + pagina, porPagina
     * Devuelve array(filas, total, pagina, paginas, porPagina).
     */
    public static function listar($vista, $f, $u)
    {
        $uid = (int) $u['id'];
        $rid = (int) $u['rolId'];
        $w   = array('1 = 1');

        switch ($vista) {
            case 'pendientes':
                $w[] = "s.ESTADO = 'EN_CURSO' AND ps.ID IS NOT NULL AND ("
                     . "(ps.RESPONSABLE_TIPO = 'SOLICITANTE' AND s.USUARIO_ID = $uid)"
                     . " OR (ps.RESPONSABLE_TIPO = 'ROL' AND ps.ROL_ID = $rid)"
                     . " OR (ps.RESPONSABLE_TIPO = 'USUARIO' AND ps.USUARIO_ID = $uid))";
                break;
            case 'historial':
            case 'todas':
                if (!GasSesion::esAdmin($u)) {
                    throw new GasError('No tienes permiso para ver el historial.');
                }
                break;
            default: // mias
                $w[] = "s.USUARIO_ID = $uid";
        }
        $where = implode(' AND ', array_merge($w, self::filtros($f)));
        $from  = self::FROM_LISTA;

        $porPagina = max(5, min(50, (int) (isset($f['porPagina']) ? $f['porPagina'] : 15)));
        $total     = (int) GasDb::scalar("SELECT COUNT(*) $from WHERE $where");
        $paginas   = max(1, (int) ceil($total / $porPagina));
        $pagina    = max(1, min($paginas, (int) (isset($f['pagina']) ? $f['pagina'] : 1)));

        $rows = GasDb::all(
            "SELECT s.ID, s.CODIGO, s.TIPO, s.ESTADO, s.PROCESO, s.TERCERO_NIT, s.TERCERO_NOMBRE,
                    COALESCE(s.VALOR_TOTAL, s.VALOR_ANTICIPO) AS VALOR,
                    CONVERT(varchar(19), s.FECHA_CREACION, 120) AS FECHA_CREACION,
                    LTRIM(RTRIM(su.NOMBRES)) + ' ' + LTRIM(RTRIM(su.APELLIDOS)) AS SOLICITANTE,
                    ps.NOMBRE AS PASO_NOMBRE, ps.RESPONSABLE_TIPO AS PASO_RESP_TIPO, pr.TITULO AS PASO_ROL
               $from WHERE $where
              ORDER BY s.ID DESC
             OFFSET " . (($pagina - 1) * $porPagina) . " ROWS FETCH NEXT $porPagina ROWS ONLY"
        );
        return array('filas' => $rows, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'porPagina' => $porPagina);
    }

    /** Cuántas solicitudes esperan al usuario (para la insignia de la bandeja). */
    public static function contarPendientes($u)
    {
        $r = self::listar('pendientes', array('porPagina' => 5), $u);
        return $r['total'];
    }

    /**
     * Tarjetas de resumen. Los administradores ven todas las solicitudes; los demás, las suyas.
     * "Por aprobar", "Contabilidad" y "Tesorería" se cuentan por la ACCION del paso en curso,
     * así valen para cualquier flujo.
     */
    public static function estadisticas($u)
    {
        $w = GasSesion::esAdmin($u) ? '1 = 1' : 's.USUARIO_ID = ' . (int) $u['id'];
        $r = GasDb::row(
            "SELECT COUNT(*) AS TOTAL,
                    SUM(CASE WHEN s.ESTADO = 'EN_CURSO' THEN 1 ELSE 0 END) AS EN_CURSO,
                    SUM(CASE WHEN s.ESTADO = 'EN_CURSO' AND ps.ACCION IN (" . self::ACC_APROBAR . ") THEN 1 ELSE 0 END) AS POR_APROBAR,
                    SUM(CASE WHEN s.ESTADO = 'EN_CURSO' AND ps.ACCION = 'CONTABILIZAR' THEN 1 ELSE 0 END) AS CONTABILIDAD,
                    SUM(CASE WHEN s.ESTADO = 'EN_CURSO' AND ps.ACCION = 'PAGAR' THEN 1 ELSE 0 END) AS TESORERIA,
                    SUM(CASE WHEN s.ESTADO = 'FINALIZADA' THEN 1 ELSE 0 END) AS FINALIZADAS,
                    SUM(CASE WHEN s.ESTADO IN ('RECHAZADA', 'CANCELADA') THEN 1 ELSE 0 END) AS CERRADAS
               FROM T_GAS_SOLICITUDES s
               LEFT JOIN T_GAS_SOLICITUD_PASOS ps ON ps.SOLICITUD_ID = s.ID AND ps.ESTADO = 'ACTUAL'
              WHERE $w"
        );
        $out = array('todas' => GasSesion::esAdmin($u));
        foreach (array('TOTAL', 'EN_CURSO', 'POR_APROBAR', 'CONTABILIDAD', 'TESORERIA', 'FINALIZADAS', 'CERRADAS') as $k) {
            $out[$k] = $r && $r[$k] !== null ? (int) $r[$k] : 0;
        }
        return $out;
    }

    /**
     * Datos del dashboard (administradores): cantidad y valor por tipo, por rol del solicitante
     * y por rol + tipo, con los filtros de fecha / organización / estado.
     * Valor = valor del preliminar o, si aún no hay, el del anticipo.
     */
    public static function dashboard($f)
    {
        $w     = self::filtros(array_intersect_key($f, array_flip(array('desde', 'hasta', 'org', 'estado'))));
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        $from  = "FROM T_GAS_SOLICITUDES s
                  LEFT JOIN T_USUARIOS su ON su.ID = s.USUARIO_ID
                  LEFT JOIN T_ROLES sr ON sr.ID = su.ROLES_ID";
        $valor = 'SUM(COALESCE(s.VALOR_TOTAL, s.VALOR_ANTICIPO, 0))';
        $rol   = "COALESCE(sr.TITULO, 'Sin rol')";
        return array(
            'porTipo'    => GasDb::all("SELECT s.TIPO, COUNT(*) AS CANT, $valor AS VALOR $from $where GROUP BY s.TIPO ORDER BY CANT DESC"),
            'porRol'     => GasDb::all("SELECT $rol AS ROL, COUNT(*) AS CANT, $valor AS VALOR $from $where GROUP BY $rol ORDER BY CANT DESC"),
            'porRolTipo' => GasDb::all("SELECT $rol AS ROL, s.TIPO, COUNT(*) AS CANT, $valor AS VALOR $from $where GROUP BY $rol, s.TIPO ORDER BY 1, 2"),
            'porEstado'  => GasDb::all("SELECT s.ESTADO, COUNT(*) AS CANT $from $where GROUP BY s.ESTADO"),
        );
    }

    /** Filas del historial para descargar en Excel (máx. 20.000), con los mismos filtros del listado. */
    public static function exportar($f)
    {
        $w     = self::filtros($f);
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
        return GasDb::all(
            "SELECT TOP 20000 s.CODIGO, s.TIPO, fl.NOMBRE AS FLUJO, s.ESTADO, ps.NOMBRE AS PASO_ACTUAL,
                    CASE ps.RESPONSABLE_TIPO WHEN 'SOLICITANTE' THEN 'Solicitante' ELSE pr.TITULO END AS RESPONSABLE_ACTUAL,
                    LTRIM(RTRIM(su.NOMBRES)) + ' ' + LTRIM(RTRIM(su.APELLIDOS)) AS SOLICITANTE, sr.TITULO AS ROL_SOLICITANTE,
                    LTRIM(RTRIM(s.ORGANIZACION_VENTA)) AS ORGANIZACION, LTRIM(RTRIM(s.OFICINA_VENTAS)) AS OFICINA,
                    s.PROCESO, s.DESCRIPCION, s.TERCERO_NIT, s.TERCERO_NOMBRE, s.CARGO, s.CENTRO_COSTOS,
                    s.TIPO_ANTICIPO, s.VALOR_ANTICIPO, s.VALOR_TOTAL, s.VALOR_LEGALIZADO, s.RETEFUENTE_LEGALIZACION, s.SALDO_LEGALIZACION, s.NUM_PRELIMINAR,
                    CASE s.FONDO WHEN 'FONDO_ROMA' THEN 'Fondo Roma' WHEN 'FONDO_PROVEEDORES' THEN 'Fondo proveedores' ELSE '' END AS FONDO,
                    CONVERT(varchar(10), s.FECHA_FACTURA, 120) AS FECHA_FACTURA,
                    s.NUM_CONTABILIZACION, s.NUM_COMPENSACION, s.NUM_COMPROBANTE_ZP,
                    CONVERT(varchar(10), s.FECHA_PAGO, 120) AS FECHA_PAGO,
                    CONVERT(varchar(19), s.FECHA_CREACION, 120) AS FECHA_CREACION,
                    CONVERT(varchar(19), s.FECHA_FIN, 120) AS FECHA_FIN, s.MOTIVO_CIERRE
               " . self::FROM_LISTA . " $where
              ORDER BY s.ID DESC"
        );
    }

    /** Nombres de los pasos en curso (para filtrar por estado actual). */
    public static function pasosActuales()
    {
        $rows = GasDb::all("SELECT DISTINCT ps.NOMBRE FROM T_GAS_SOLICITUD_PASOS ps
                             JOIN T_GAS_SOLICITUDES s ON s.ID = ps.SOLICITUD_ID
                            WHERE ps.ESTADO = 'ACTUAL' AND s.ESTADO = 'EN_CURSO' ORDER BY ps.NOMBRE");
        $out = array();
        foreach ($rows as $r) {
            $out[] = $r['NOMBRE'];
        }
        return $out;
    }

    /* ---------------------------------------------------------------- internos */

    /** Fuerza los campos bit a 0/1/null y recorta CHAR de longitud fija. */
    public static function normalizar($row)
    {
        foreach (self::$BITS as $b) {
            if (array_key_exists($b, $row) && $row[$b] !== null) {
                $row[$b] = (int) $row[$b];
            }
        }
        foreach (array('ORGANIZACION_VENTA', 'OFICINA_VENTAS') as $c) {
            if (isset($row[$c])) {
                $row[$c] = trim($row[$c]);
            }
        }
        return $row;
    }
}
