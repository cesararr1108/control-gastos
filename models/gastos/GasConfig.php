<?php
/**
 * Configuracion del modulo de control de gastos.
 * Ajusta estos valores a tu entorno; no hay nada mas que tocar en el codigo.
 */
class GasConfig
{
    /**
     * IDs de T_ROLES que pueden armar/editar flujos y ver todas las solicitudes.
     * IMPORTANTE: ajusta esta lista a los roles administradores reales de tu empresa.
     */
    public static $ROLES_ADMIN = array(1);

    /** Carpeta fisica de los PDF (no debe ser accesible por URL; ver uploads/gastos/.htaccess). */
    public static function dirUploads()
    {
        return dirname(dirname(dirname(__FILE__))) . '/uploads/gastos';
    }

    /** Tamano maximo por PDF, en MB. */
    const MAX_PDF_MB = 10;

    /**
     * true  = la conexion mssql trabaja en Latin1/cp1252 (lo normal con collation Modern_Spanish):
     *         se convierte UTF-8 -> Latin1 al escribir y Latin1 -> UTF-8 al leer.
     * false = la conexion ya trabaja en UTF-8.
     */
    const DB_LATIN1 = true;
}
