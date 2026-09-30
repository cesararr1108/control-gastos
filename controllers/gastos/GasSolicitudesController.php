<?php
/**
 * Endpoints de solicitudes. Cada método recibe ($u usuario, $in POST/GET, $files) y devuelve datos.
 */
class GasSolicitudesController
{
    /** Flujos disponibles para un tipo (para elegir al crear). */
    public static function variantes($u, $in, $files)
    {
        return GasSolicitudModel::variantes(isset($in['tipo']) ? $in['tipo'] : '', $u);
    }

    /** Crea una solicitud del tipo indicado (con el flujo elegido, si hay varios) y devuelve su ID. */
    public static function crear($u, $in, $files)
    {
        $id = GasSolicitudModel::crear(isset($in['tipo']) ? $in['tipo'] : '', $u, isset($in['flujo_id']) ? (int) $in['flujo_id'] : null);
        return array('id' => $id);
    }

    /** Listado paginado. vista: pendientes | mias | participadas | todas. */
    public static function listar($u, $in, $files)
    {
        $vista = isset($in['vista']) ? $in['vista'] : 'mias';
        return GasSolicitudModel::listar($vista, $in, $u);
    }

    /** Detalle + permisos del usuario sobre el paso en curso. */
    public static function obtener($u, $in, $files)
    {
        $s = GasSolicitudModel::obtener(isset($in['id']) ? $in['id'] : 0);
        if (!$s) {
            throw new GasError('Solicitud no encontrada.');
        }
        if (!GasSolicitudModel::puedeVer($s, $u)) {
            throw new GasError('No tienes acceso a esta solicitud.');
        }
        $actual = null;
        foreach ($s['pasos'] as $p) {
            if ($p['ESTADO'] === 'ACTUAL') {
                $actual = $p;
            }
        }
        $s['puedeActuar']   = $s['ESTADO'] === 'EN_CURSO' && $actual && GasSolicitudModel::esResponsable($actual, $s, $u);
        $s['puedeCancelar'] = $s['ESTADO'] === 'EN_CURSO' && ((int) $s['USUARIO_ID'] === (int) $u['id'] || GasSesion::esAdmin($u));
        $s['pasoActual']    = $actual;
        return $s;
    }

    /** Ejecuta el paso en curso (multipart: campos + PDF). */
    public static function ejecutar($u, $in, $files)
    {
        GasMotor::ejecutar(isset($in['id']) ? $in['id'] : 0, $u, $in, $files);
        return array('id' => (int) $in['id']);
    }

    public static function cancelar($u, $in, $files)
    {
        GasMotor::cancelar(isset($in['id']) ? $in['id'] : 0, $u, isset($in['motivo']) ? $in['motivo'] : '');
        return array('id' => (int) $in['id']);
    }

    /** Tarjetas de resumen de la bandeja. */
    public static function estadisticas($u, $in, $files)
    {
        return GasSolicitudModel::estadisticas($u);
    }

    /** Dashboard (solo administradores; ver api.php). */
    public static function dashboard($u, $in, $files)
    {
        return GasSolicitudModel::dashboard($in);
    }

    /** Historial para Excel (solo administradores; ver api.php). */
    public static function exportar($u, $in, $files)
    {
        return GasSolicitudModel::exportar($in);
    }

    /** Solo el número de pendientes (insignia del menú). */
    public static function contador($u, $in, $files)
    {
        return array('pendientes' => GasSolicitudModel::contarPendientes($u));
    }
}
