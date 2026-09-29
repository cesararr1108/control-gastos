<?php
/**
 * Endpoints del armador de flujos. Solo administradores (ver api.php).
 */
class GasFlujosController
{
    public static function listar($u, $in, $files)
    {
        return GasFlujoModel::listar();
    }

    /** Un flujo por ID, o una plantilla vacía si no se envía ID. */
    public static function obtener($u, $in, $files)
    {
        if (empty($in['id'])) {
            return array('ID' => 0, 'TIPO' => 'FACTURA', 'NOMBRE' => '', 'ORGANIZACION_VENTA' => '', 'OFICINA_VENTAS' => '', 'ACTIVO' => 1, 'pasos' => array());
        }
        $f = GasFlujoModel::obtener($in['id']);
        if (!$f) {
            throw new GasError('Flujo no encontrado.');
        }
        return $f;
    }

    /** Guarda cabecera y pasos. Los pasos llegan como pasos[i][campo]. */
    public static function guardar($u, $in, $files)
    {
        $id = GasFlujoModel::guardar($in, $u['id']);
        return array('id' => $id);
    }

    public static function estado($u, $in, $files)
    {
        GasFlujoModel::cambiarEstado(isset($in['id']) ? $in['id'] : 0, !empty($in['activo']) && $in['activo'] !== '0');
        return array('id' => (int) $in['id']);
    }
}
