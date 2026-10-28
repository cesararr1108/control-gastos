<?php
/**
 * Notificaciones push a través de la API notificaciones-push-roma (POST /api/v1/notificaciones).
 *
 * Cuando una solicitud avanza se avisa a quien debe hacer el paso en curso (el solicitante, los
 * usuarios del rol o el usuario asignado), identificados por su correo en T_USUARIOS. Si la solicitud
 * se cerró (finalizada o rechazada) se avisa al solicitante. Una notificación nunca frena el flujo:
 * cualquier error se registra con error_log y se sigue.
 */
class GasNotificaciones
{
    /** Máximo de correos por envío (límite de listas de la API). */
    const MAX_EMAILS = 1000;

    public static function activa()
    {
        return GasConfig::PUSH_URL !== '' && GasConfig::pushApiKey() !== '';
    }

    /**
     * Avisa el estado actual de la solicitud. Llamar DESPUÉS del commit.
     * @param int    $solId
     * @param int    $actorId  usuario que hizo el cambio (no se le notifica a sí mismo)
     * @param string $evento   'avance' | 'devuelta'
     */
    public static function solicitud($solId, $actorId, $evento = 'avance')
    {
        if (!self::activa()) {
            return;
        }
        try {
            $sol = GasSolicitudModel::fila($solId);
            if (!$sol) {
                return;
            }
            $codigo = $sol['CODIGO'];
            $quien  = trim((string) GasDb::scalar("SELECT LTRIM(RTRIM(NOMBRES)) + ' ' + LTRIM(RTRIM(APELLIDOS)) FROM T_USUARIOS WHERE ID = " . (int) $sol['USUARIO_ID']));
            $detalle = implode(' · ', array_filter(array($quien, $sol['PROCESO'])));

            if ($sol['ESTADO'] === 'EN_CURSO') {
                $paso = GasDb::row("SELECT p.NOMBRE, p.RESPONSABLE_TIPO, p.ROL_ID, p.USUARIO_ID FROM T_GAS_SOLICITUD_PASOS p
                                     WHERE p.SOLICITUD_ID = " . (int) $solId . " AND p.ESTADO = 'ACTUAL'");
                if (!$paso) {
                    return;
                }
                $emails = self::emailsResponsables($paso, $sol, $actorId);
                $titulo = $evento === 'devuelta' ? "$codigo devuelta para corrección" : "$codigo: te toca actuar";
                $cuerpo = '«' . $paso['NOMBRE'] . '»' . ($detalle !== '' ? ' — ' . $detalle : '');
            } elseif (in_array($sol['ESTADO'], array('FINALIZADA', 'RECHAZADA'), true)) {
                $emails = self::emailsUsuarios('ID = ' . (int) $sol['USUARIO_ID'], $actorId);
                $titulo = $codigo . ($sol['ESTADO'] === 'FINALIZADA' ? ' finalizada' : ' rechazada');
                $cuerpo = $sol['ESTADO'] === 'FINALIZADA'
                    ? 'Tu solicitud completó todos los pasos.'
                    : 'Tu solicitud fue rechazada' . ($sol['MOTIVO_CIERRE'] ? ': ' . $sol['MOTIVO_CIERRE'] : '.');
            } else {
                return;
            }
            if (!$emails) {
                return;
            }
            self::enviar($emails, $titulo, $cuerpo, $solId, 'gas-' . (int) $solId . '-' . date('YmdHis') . '-' . mt_rand(100, 999));
        } catch (Exception $e) {
            error_log('[gastos] notificación de la solicitud ' . (int) $solId . ': ' . $e->getMessage());
        }
    }

    /** Correos de quien debe ejecutar el paso. */
    private static function emailsResponsables($paso, $sol, $actorId)
    {
        switch ($paso['RESPONSABLE_TIPO']) {
            case 'SOLICITANTE':
                return self::emailsUsuarios('ID = ' . (int) $sol['USUARIO_ID'], $actorId);
            case 'USUARIO':
                return self::emailsUsuarios('ID = ' . (int) $paso['USUARIO_ID'], $actorId);
            case 'ROL':
                return $paso['ROL_ID'] ? self::emailsUsuarios("ESTADO = 'A' AND ROLES_ID = " . (int) $paso['ROL_ID'], $actorId) : array();
        }
        return array();
    }

    private static function emailsUsuarios($condicion, $actorId)
    {
        $rows = GasDb::all('SELECT TOP ' . self::MAX_EMAILS . ' LTRIM(RTRIM(EMAIL)) AS EMAIL FROM T_USUARIOS WHERE ' . $condicion
            . ' AND ID <> ' . (int) $actorId . " AND EMAIL LIKE '%_@_%'");
        $out = array();
        foreach ($rows as $r) {
            $e = strtolower($r['EMAIL']);
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $out[$e] = true;
            }
        }
        return array_keys($out);
    }

    /** POST /api/v1/notificaciones. */
    private static function enviar($emails, $titulo, $cuerpo, $solId, $idempotencia)
    {
        $notif = array('titulo' => self::corto($titulo, 150), 'cuerpo' => self::corto($cuerpo, 1000));
        $url = self::urlSolicitud($solId);
        if ($url !== '') {
            $notif['url'] = $url;
        }
        $r = self::llamar('POST', '/api/v1/notificaciones', array(
            'destinatarios' => array('emails' => array_values($emails)),
            'notificacion'  => $notif,
            'data'          => array('tipo' => 'gastos', 'solicitud' => (string) (int) $solId),
            'prioridad'     => 'high',
        ), $idempotencia);
        if ($r['codigo'] >= 300) {
            error_log('[gastos] la API de notificaciones respondió ' . $r['codigo'] . ': ' . substr($r['cuerpo'], 0, 300));
        }
    }

    /**
     * Registra el navegador/teléfono del usuario (token FCM) en la API, con su correo y oficina.
     * La llave de API nunca sale del servidor. Lanza GasError si la API lo rechaza.
     */
    public static function registrarToken($u, $token, $navegador, $dispositivo)
    {
        if (!self::activa()) {
            throw new GasError('Las notificaciones no están configuradas.');
        }
        if (!preg_match('/^[A-Za-z0-9_:\-]{20,4096}$/', (string) $token)) {
            throw new GasError('Token de notificaciones inválido.');
        }
        $usr = GasDb::row("SELECT LTRIM(RTRIM(EMAIL)) AS EMAIL, LTRIM(RTRIM(NOMBRES)) + ' ' + LTRIM(RTRIM(APELLIDOS)) AS NOMBRE
                             FROM T_USUARIOS WHERE ID = " . (int) $u['id']);
        $email = $usr && $usr['EMAIL'] ? $usr['EMAIL'] : $u['email'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new GasError('Tu usuario no tiene un correo válido: no se pueden activar las notificaciones.');
        }
        $oficina = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $u['oficina']));
        $datos = array(
            'oficina'        => preg_match('/^[A-Z0-9]{4}$/', $oficina) ? $oficina : '0000',
            'nombre_usuario' => self::corto($usr && trim($usr['NOMBRE']) !== '' ? $usr['NOMBRE'] : $u['nombre'], 150),
            'email'          => strtolower($email),
            'token'          => $token,
            'navegador'      => self::corto($navegador, 100),
            'dispositivo'    => self::corto($dispositivo, 100),
        );
        if (preg_match('/^[A-Za-z0-9._@\-]{1,190}$/', (string) $u['login'])) {
            $datos['usuario'] = $u['login'];
        }
        $r = self::llamar('POST', '/api/v1/tokens', $datos, null);
        if ($r['codigo'] >= 300) {
            error_log('[gastos] registro de token rechazado (' . $r['codigo'] . '): ' . substr($r['cuerpo'], 0, 300));
            throw new GasError('No se pudieron activar las notificaciones en este dispositivo.');
        }
        return true;
    }

    /** https://…/views/gastos/solicitud.php?id=N, o '' si URL_MODULO no está configurada con https. */
    public static function urlSolicitud($solId)
    {
        $base = GasConfig::URL_MODULO;
        if (stripos($base, 'https://') !== 0) {
            return '';
        }
        return rtrim($base, '/') . '/solicitud.php?id=' . (int) $solId;
    }

    /** Llamada JSON a la API. Devuelve array(codigo, cuerpo); codigo 0 = sin respuesta. */
    private static function llamar($metodo, $ruta, $datos, $idempotencia)
    {
        if (!function_exists('curl_init')) {
            throw new Exception('Falta la extensión curl de PHP para las notificaciones.');
        }
        $h = array('Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . GasConfig::pushApiKey());
        if ($idempotencia) {
            $h[] = 'Idempotency-Key: ' . $idempotencia;
        }
        $ch = curl_init(rtrim(GasConfig::PUSH_URL, '/') . $ruta);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_POSTFIELDS     => json_encode($datos),
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => GasConfig::PUSH_TIMEOUT,
            CURLOPT_TIMEOUT        => GasConfig::PUSH_TIMEOUT,
        ));
        $cuerpo = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($cuerpo === false) {
            $cuerpo = curl_error($ch);
        }
        curl_close($ch);
        return array('codigo' => $codigo === 0 ? 599 : $codigo, 'cuerpo' => (string) $cuerpo);
    }

    private static function corto($t, $max)
    {
        $t = trim(strip_tags((string) $t));
        return function_exists('mb_substr') ? mb_substr($t, 0, $max, 'UTF-8') : substr($t, 0, $max);
    }
}
