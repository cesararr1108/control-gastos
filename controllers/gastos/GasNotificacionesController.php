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
}
