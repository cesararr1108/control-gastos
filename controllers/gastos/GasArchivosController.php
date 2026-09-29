<?php
/**
 * Descarga de PDF: solo quien puede ver la solicitud dueña del archivo.
 */
class GasArchivosController
{
    /** Responde el PDF directamente (no JSON). */
    public static function ver($u, $in, $files)
    {
        $a = GasDb::row('SELECT ID, SOLICITUD_ID, NOMBRE_ORIGINAL, RUTA FROM T_GAS_ARCHIVOS WHERE ID = ' . GasDb::int(isset($in['id']) ? $in['id'] : 0));
        if (!$a) {
            throw new GasError('Archivo no encontrado.');
        }
        $s = GasSolicitudModel::fila($a['SOLICITUD_ID']);
        if (!$s || !GasSolicitudModel::puedeVer($s, $u)) {
            throw new GasError('No tienes acceso a este archivo.');
        }
        GasArchivos::enviar($a['RUTA'], $a['NOMBRE_ORIGINAL']);
        return null;
    }
}
