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
        return GasNotificaciones::probar($u, isset($in['email']) ? $in['email'] : '', isset($in['org']) ? $in['org'] : '');
    }

    /** A quién le llegaría el aviso del paso en curso de una solicitud (administradores). */
    public static function destinatarios($u, $in, $files)
    {
        return GasNotificaciones::destinatarios(isset($in['codigo']) ? $in['codigo'] : '');
    }

    /** Últimos avisos enviados y lo que respondió la API (administradores). */
    public static function ultimos($u, $in, $files)
    {
        return GasNotificaciones::ultimos();
    }
}
