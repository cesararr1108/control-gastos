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
        s.NUM_PRELIMINAR, s.NUM_CONTABILIZACION, s.NUM_COMPENSACION, s.NUM_COMPROBANTE_ZP,
        CONVERT(varchar(10), s.FECHA_PAGO, 120) AS FECHA_PAGO,
        CONVERT(varchar(19), s.FECHA_CREACION, 120) AS FECHA_CREACION,
        CONVERT(varchar(19), s.FECHA_MODIFICACION, 120) AS FECHA_MODIFICACION,
        CONVERT(varchar(19), s.FECHA_FIN, 120) AS FECHA_FIN";

    /** Campos bit que deben llegar al navegador como 0/1/null (no como "0" texto). */
    private static $BITS = array('REQUIERE_SOPORTE_PAGO', 'REQUIERE_ANTICIPO');

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

    /**
     * Listado paginado.
     * $vista: 'pendientes' (me toca actuar) | 'mias' | 'participadas' | 'todas' (solo admin)
     * $f: estado, tipo, q (código / NIT / tercero), pagina, porPagina
     * Devuelve array(filas, total, pagina, porPagina).
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
            case 'participadas':
                $w[] = "EXISTS (SELECT 1 FROM T_GAS_SOLICITUD_PASOS x WHERE x.SOLICITUD_ID = s.ID AND x.EJECUTADO_POR = $uid)";
                break;
            case 'todas':
                if (!GasSesion::esAdmin($u)) {
                    throw new GasError('No tienes permiso para ver todas las solicitudes.');
                }
                break;
            default: // mias
                $w[] = "s.USUARIO_ID = $uid";
        }
        if (!empty($f['estado']) && in_array($f['estado'], array('EN_CURSO', 'FINALIZADA', 'RECHAZADA', 'CANCELADA'), true)) {
            $w[] = 's.ESTADO = ' . GasDb::str($f['estado']);
        }
        $tipos = GasCatalogo::tipos();
        if (!empty($f['tipo']) && isset($tipos[$f['tipo']])) {
            $w[] = 's.TIPO = ' . GasDb::str($f['tipo']);
        }
        if (!empty($f['q'])) {
            $q   = str_replace(array('[', '%', '_'), array('[[]', '[%]', '[_]'), trim($f['q']));
            $lit = GasDb::str('%' . $q . '%');
            $w[] = "(s.CODIGO LIKE $lit OR s.TERCERO_NIT LIKE $lit OR s.TERCERO_NOMBRE LIKE $lit OR s.NUM_PRELIMINAR LIKE $lit)";
        }
        $where = implode(' AND ', $w);
        $from  = "FROM T_GAS_SOLICITUDES s
                  LEFT JOIN T_GAS_SOLICITUD_PASOS ps ON ps.SOLICITUD_ID = s.ID AND ps.ESTADO = 'ACTUAL'
                  LEFT JOIN T_ROLES pr ON pr.ID = ps.ROL_ID
                  LEFT JOIN T_USUARIOS su ON su.ID = s.USUARIO_ID";

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
