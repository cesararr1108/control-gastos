<?php
/**
 * Flujos configurables: cabecera (tipo + organización/oficina) y sus pasos ordenados.
 */
class GasFlujoModel
{
    /** Lista los flujos con la cantidad de pasos y cuántos no tienen responsable. */
    public static function listar()
    {
        $rows = GasDb::all(
            "SELECT f.ID, f.TIPO, f.NOMBRE, f.ORGANIZACION_VENTA, f.OFICINA_VENTAS, f.ACTIVO,
                    (SELECT COUNT(*) FROM T_GAS_FLUJO_PASOS p WHERE p.FLUJO_ID = f.ID) AS PASOS,
                    (SELECT COUNT(*) FROM T_GAS_FLUJO_PASOS p WHERE p.FLUJO_ID = f.ID
                        AND ((p.RESPONSABLE_TIPO = 'ROL' AND p.ROL_ID IS NULL)
                          OR (p.RESPONSABLE_TIPO = 'USUARIO' AND p.USUARIO_ID IS NULL))) AS SIN_RESPONSABLE,
                    o.DESCRIPCION AS OFICINA_NOMBRE
               FROM T_GAS_FLUJOS f
               LEFT JOIN (SELECT OFICINA_VENTAS, MAX(DESCRIPCION) AS DESCRIPCION
                            FROM T_OFICINAS_VENTAS GROUP BY OFICINA_VENTAS) o ON o.OFICINA_VENTAS = f.OFICINA_VENTAS
              ORDER BY f.TIPO, f.ORGANIZACION_VENTA, f.OFICINA_VENTAS"
        );
        return self::trimRows($rows, array('ORGANIZACION_VENTA', 'OFICINA_VENTAS'));
    }

    /** Un flujo con sus pasos, o null. */
    public static function obtener($id)
    {
        $f = GasDb::row('SELECT ID, TIPO, NOMBRE, ORGANIZACION_VENTA, OFICINA_VENTAS, ACTIVO
                           FROM T_GAS_FLUJOS WHERE ID = ' . GasDb::int($id));
        if (!$f) {
            return null;
        }
        $f = self::trimRow($f, array('ORGANIZACION_VENTA', 'OFICINA_VENTAS'));
        $f['pasos'] = GasDb::all(
            "SELECT p.ID, p.ORDEN, p.ACCION, p.NOMBRE, p.RESPONSABLE_TIPO, p.ROL_ID, p.USUARIO_ID, p.CONDICION,
                    u.LOGIN + ' - ' + LTRIM(RTRIM(u.NOMBRES)) + ' ' + LTRIM(RTRIM(u.APELLIDOS)) AS USUARIO_NOMBRE
               FROM T_GAS_FLUJO_PASOS p LEFT JOIN T_USUARIOS u ON u.ID = p.USUARIO_ID
              WHERE p.FLUJO_ID = " . (int) $f['ID'] . ' ORDER BY p.ORDEN'
        );
        return $f;
    }

    /**
     * Flujo activo que aplica a (tipo, organización, oficina): el más específico gana
     * (oficina > organización > general). Devuelve el flujo con pasos, o null.
     */
    public static function resolver($tipo, $organizacion, $oficina)
    {
        $id = GasDb::scalar(
            'SELECT TOP 1 ID FROM T_GAS_FLUJOS
              WHERE ACTIVO = 1 AND TIPO = ' . GasDb::str($tipo) . '
                AND (OFICINA_VENTAS IS NULL OR OFICINA_VENTAS = ' . GasDb::str($oficina) . ')
                AND (ORGANIZACION_VENTA IS NULL OR ORGANIZACION_VENTA = ' . GasDb::str($organizacion) . ')
              ORDER BY (CASE WHEN OFICINA_VENTAS IS NOT NULL THEN 2 ELSE 0 END)
                     + (CASE WHEN ORGANIZACION_VENTA IS NOT NULL THEN 1 ELSE 0 END) DESC, ID'
        );
        return $id ? self::obtener($id) : null;
    }

    /**
     * Crea o actualiza un flujo completo (cabecera + pasos) en una transacción.
     * $datos: id?, tipo, nombre, organizacion, oficina, activo, pasos[] (accion, nombre,
     * responsable_tipo, rol_id, usuario_id, condicion). El orden es la posición en el arreglo.
     * Devuelve el ID del flujo. Lanza Exception con mensaje claro si algo es inválido.
     */
    public static function guardar($datos, $usuarioId)
    {
        $tipos       = GasCatalogo::tipos();
        $acciones    = GasCatalogo::acciones();
        $condiciones = GasCatalogo::condiciones();
        $resp        = GasCatalogo::responsables();

        $id     = isset($datos['id']) ? (int) $datos['id'] : 0;
        $tipo   = isset($datos['tipo']) ? $datos['tipo'] : '';
        $nombre = trim(isset($datos['nombre']) ? $datos['nombre'] : '');
        $org    = trim(isset($datos['organizacion']) ? $datos['organizacion'] : '');
        $ofi    = trim(isset($datos['oficina']) ? $datos['oficina'] : '');
        $activo = !empty($datos['activo']);
        $pasos  = isset($datos['pasos']) && is_array($datos['pasos']) ? array_values($datos['pasos']) : array();

        if (!isset($tipos[$tipo])) {
            throw new GasError('Tipo de flujo inválido.');
        }
        if ($nombre === '') {
            throw new GasError('El flujo necesita un nombre.');
        }
        if ($ofi !== '' && $org === '') {
            throw new GasError('Si eliges una oficina debes elegir también su organización.');
        }
        if (!$pasos) {
            throw new GasError('El flujo necesita al menos un paso.');
        }

        // Un solo flujo activo por (tipo, organización, oficina).
        if ($activo) {
            $dup = GasDb::scalar(
                'SELECT TOP 1 ID FROM T_GAS_FLUJOS WHERE ACTIVO = 1 AND TIPO = ' . GasDb::str($tipo)
                . ' AND ID <> ' . $id
                . ' AND ISNULL(ORGANIZACION_VENTA, \'\') = ' . GasDb::lit($org)
                . ' AND ISNULL(OFICINA_VENTAS, \'\') = ' . GasDb::lit($ofi)
            );
            if ($dup) {
                throw new GasError('Ya existe un flujo activo de este tipo para esa organización/oficina.');
            }
        }

        // Validar pasos.
        foreach ($pasos as $i => $p) {
            $n = $i + 1;
            $accion = isset($p['accion']) ? $p['accion'] : '';
            $rt     = isset($p['responsable_tipo']) ? $p['responsable_tipo'] : '';
            $cond   = isset($p['condicion']) ? $p['condicion'] : 'SIEMPRE';
            if (!isset($acciones[$accion])) {
                throw new GasError("Paso $n: acción inválida.");
            }
            if (!in_array($tipo, $acciones[$accion]['tipos'], true)) {
                throw new GasError("Paso $n: la acción «" . $acciones[$accion]['nombre'] . '» no aplica a flujos de ' . $tipos[$tipo]['nombre'] . '.');
            }
            if (trim(isset($p['nombre']) ? $p['nombre'] : '') === '') {
                throw new GasError("Paso $n: escribe un nombre.");
            }
            if (!isset($resp[$rt])) {
                throw new GasError("Paso $n: responsable inválido.");
            }
            if (!isset($condiciones[$cond])) {
                throw new GasError("Paso $n: condición inválida.");
            }
        }
        // El primer paso lo hace el solicitante: es quien "arranca" la solicitud.
        if ($pasos[0]['responsable_tipo'] !== 'SOLICITANTE') {
            throw new GasError('El primer paso debe ser del solicitante.');
        }

        GasDb::begin();
        try {
            if ($id > 0) {
                GasDb::query(
                    'UPDATE T_GAS_FLUJOS SET TIPO = ' . GasDb::str($tipo) . ', NOMBRE = ' . GasDb::str($nombre, 120)
                    . ', ORGANIZACION_VENTA = ' . GasDb::str($org) . ', OFICINA_VENTAS = ' . GasDb::str($ofi)
                    . ', ACTIVO = ' . GasDb::bit($activo) . ', FECHA_MODIFICACION = GETDATE(), USUARIO_ID = ' . GasDb::int($usuarioId)
                    . ' WHERE ID = ' . $id
                );
                GasDb::query('DELETE FROM T_GAS_FLUJO_PASOS WHERE FLUJO_ID = ' . $id);
            } else {
                $id = GasDb::insert(
                    'INSERT INTO T_GAS_FLUJOS (TIPO, NOMBRE, ORGANIZACION_VENTA, OFICINA_VENTAS, ACTIVO, USUARIO_ID) VALUES ('
                    . GasDb::str($tipo) . ', ' . GasDb::str($nombre, 120) . ', ' . GasDb::str($org) . ', ' . GasDb::str($ofi)
                    . ', ' . GasDb::bit($activo) . ', ' . GasDb::int($usuarioId) . ')'
                );
            }
            foreach ($pasos as $i => $p) {
                $rt = $p['responsable_tipo'];
                GasDb::query(
                    'INSERT INTO T_GAS_FLUJO_PASOS (FLUJO_ID, ORDEN, ACCION, NOMBRE, RESPONSABLE_TIPO, ROL_ID, USUARIO_ID, CONDICION) VALUES ('
                    . $id . ', ' . ($i + 1) . ', ' . GasDb::str($p['accion']) . ', ' . GasDb::str($p['nombre'], 120)
                    . ', ' . GasDb::str($rt)
                    . ', ' . ($rt === 'ROL' ? GasDb::int(isset($p['rol_id']) ? $p['rol_id'] : null) : 'NULL')
                    . ', ' . ($rt === 'USUARIO' ? GasDb::int(isset($p['usuario_id']) ? $p['usuario_id'] : null) : 'NULL')
                    . ', ' . GasDb::str(isset($p['condicion']) ? $p['condicion'] : 'SIEMPRE') . ')'
                );
            }
            GasDb::commit();
        } catch (Exception $e) {
            GasDb::rollback();
            throw $e;
        }
        return $id;
    }

    /** Activa o desactiva un flujo (no se borra: las solicitudes históricas lo referencian). */
    public static function cambiarEstado($id, $activo)
    {
        if ($activo) {
            $f = self::obtener($id);
            if (!$f) {
                throw new GasError('Flujo no encontrado.');
            }
            $dup = GasDb::scalar(
                'SELECT TOP 1 ID FROM T_GAS_FLUJOS WHERE ACTIVO = 1 AND TIPO = ' . GasDb::str($f['TIPO']) . ' AND ID <> ' . (int) $id
                . ' AND ISNULL(ORGANIZACION_VENTA, \'\') = ' . GasDb::lit(isset($f['ORGANIZACION_VENTA']) ? $f['ORGANIZACION_VENTA'] : '')
                . ' AND ISNULL(OFICINA_VENTAS, \'\') = ' . GasDb::lit(isset($f['OFICINA_VENTAS']) ? $f['OFICINA_VENTAS'] : '')
            );
            if ($dup) {
                throw new GasError('Ya existe otro flujo activo para esa combinación.');
            }
        }
        GasDb::query('UPDATE T_GAS_FLUJOS SET ACTIVO = ' . GasDb::bit($activo) . ', FECHA_MODIFICACION = GETDATE() WHERE ID = ' . (int) $id);
    }

    /* -------------------------------------------------------------- utilidades */

    private static function trimRow($row, $campos)
    {
        foreach ($campos as $c) {
            if (isset($row[$c]) && $row[$c] !== null) {
                $row[$c] = trim($row[$c]);
            }
        }
        return $row;
    }

    private static function trimRows($rows, $campos)
    {
        foreach ($rows as $i => $r) {
            $rows[$i] = self::trimRow($r, $campos);
        }
        return $rows;
    }
}
