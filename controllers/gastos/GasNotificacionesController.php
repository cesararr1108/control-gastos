<?php
/**
 * Registro del dispositivo del usuario en la API de notificaciones push (la llave no sale del servidor).
 */
class GasNotificacionesController
{
    public static function registrar($u, $in, $files)
    {
        GasNotificaciones::registrarToken($u, isset($in['token']) ? $in['token'] : '',
            isset($in['navegador']) ? $in['navegador'] : '', isset($in['dispositivo']) ? $in['dispositivo'] : '');
        return array('registrado' => true);
    }

    /** Revisión de la configuración (administradores). */
    public static function diagnostico($u, $in, $files)
    {
        return GasNotificaciones::diagnostico($u);
    }

    /** Notificación de prueba al propio usuario (administradores). */
    public static function probar($u, $in, $files)
    {
        return GasNotificaciones::probar($u);
    }
}
