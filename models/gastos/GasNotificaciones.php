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
        $ch = curl_init(self::url($ruta));
        $op = array(
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => GasConfig::PUSH_TIMEOUT,
            CURLOPT_TIMEOUT        => GasConfig::PUSH_TIMEOUT,
        );
        if ($datos !== null) {
            $op[CURLOPT_POSTFIELDS] = json_encode($datos);
        }
        curl_setopt_array($ch, $op);
        $cuerpo = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = $cuerpo === false ? curl_error($ch) : '';
        curl_close($ch);
        return array('codigo' => $codigo === 0 ? 599 : $codigo, 'cuerpo' => $cuerpo === false ? $error : (string) $cuerpo, 'error' => $error);
    }

    /** URL completa de un endpoint de la API. */
    private static function url($ruta)
    {
        return rtrim(GasConfig::PUSH_URL, '/') . $ruta;
    }

    /* ------------------------------------------------------------- diagnóstico */

    /**
     * Revisa la configuración paso a paso (pantalla views/gastos/notificaciones.php, administradores).
     * Devuelve una lista de array(titulo, estado ok|aviso|error, detalle). Nunca muestra la llave completa.
     */
    public static function diagnostico($u)
    {
        $r = array();
        $key = GasConfig::pushApiKey();
        $r[] = array('URL de la API (PUSH_URL)', GasConfig::PUSH_URL !== '' ? 'ok' : 'error',
            GasConfig::PUSH_URL !== '' ? 'Se llama a ' . self::url('/api/v1/notificaciones') : 'Vacía: las notificaciones están desactivadas.');
        $r[] = array('Llave de API', $key !== '' ? 'ok' : 'error',
            $key !== '' ? 'Configurada (' . substr($key, 0, 7) . '…, ' . (getenv('GASTOS_PUSH_API_KEY') ? 'variable de entorno' : 'GasConfig::PUSH_API_KEY') . ').'
                        : 'Falta: define GASTOS_PUSH_API_KEY o GasConfig::PUSH_API_KEY.');
        $curl = function_exists('curl_init');
        $r[] = array('Extensión curl de PHP', $curl ? 'ok' : 'error', $curl ? 'Disponible.' : 'No está instalada: PHP no puede llamar a la API.');
        if ($curl && GasConfig::PUSH_URL !== '') {
            $h = self::llamar('GET', '/api/v1/health', null, null);
            if ($h['codigo'] === 200) {
                $r[] = array('Conexión con la API (/api/v1/health)', 'ok', 'Responde: ' . substr($h['cuerpo'], 0, 120));
            } elseif ($h['codigo'] === 404) {
                $r[] = array('Conexión con la API (/api/v1/health)', 'error', 'HTTP 404 en ' . self::url('/api/v1/health')
                    . '. La URL base no apunta a la carpeta public/ de la API: prueba agregando /public o /index.php al final de PUSH_URL, o revisa el document root del servidor.');
            } else {
                $r[] = array('Conexión con la API (/api/v1/health)', 'error', 'HTTP ' . $h['codigo'] . ($h['error'] ? ' · ' . $h['error'] : '')
                    . ' · ' . substr(strip_tags($h['cuerpo']), 0, 200) . ($h['codigo'] === 599 ? ' (sin respuesta: DNS, firewall, certificado SSL o tiempo de espera)' : ''));
            }
        }
        $email = self::emailUsuario($u);
        $r[] = array('Tu correo en T_USUARIOS', $email ? 'ok' : 'error',
            $email ? $email . ' — las notificaciones se envían a los dispositivos registrados con este correo en la API.' : 'Tu usuario no tiene un correo válido.');
        $r[] = array('Enlace de la notificación (URL_MODULO)', stripos(GasConfig::URL_MODULO, 'https://') === 0 ? 'ok' : 'aviso',
            stripos(GasConfig::URL_MODULO, 'https://') === 0 ? 'Abre ' . self::urlSolicitud(123) : 'Vacía o sin https: la notificación llega, pero al tocarla no abre la solicitud.');
        $r[] = array('Registro de dispositivos desde este módulo ($FIREBASE_WEB)', GasConfig::$FIREBASE_WEB ? 'ok' : 'aviso',
            GasConfig::$FIREBASE_WEB ? 'Configurado: cada usuario ve «Activar notificaciones» y su navegador o celular queda registrado.'
                : 'Vacío: este módulo no registra dispositivos. Solo llegarán avisos a quien ya tenga su navegador o celular registrado en la API con su correo (p. ej. desde otra aplicación).');
        return $r;
    }

    /**
     * Envía una notificación de prueba al correo del usuario y devuelve lo que respondió la API
     * con su interpretación (total = dispositivos encontrados para ese correo).
     */
    public static function probar($u)
    {
        if (!self::activa()) {
            throw new GasError('Configura PUSH_URL y la llave de API primero.');
        }
        $email = self::emailUsuario($u);
        if (!$email) {
            throw new GasError('Tu usuario no tiene un correo válido en T_USUARIOS.');
        }
        $r = self::llamar('POST', '/api/v1/notificaciones', array(
            'destinatarios' => array('emails' => array($email)),
            'notificacion'  => array('titulo' => 'Prueba · Control de gastos', 'cuerpo' => 'Si ves este mensaje, las notificaciones funcionan.'),
            'data'          => array('tipo' => 'gastos-prueba'),
            'prioridad'     => 'high',
        ), 'gas-prueba-' . date('YmdHis') . '-' . mt_rand(100, 999));
        $json = json_decode($r['cuerpo'], true);
        $d = is_array($json) && isset($json['data']) ? $json['data'] : null;
        $explicacion = array(
            401 => 'La llave de API no es válida (revisa que esté completa y activa).',
            403 => 'La llave no tiene el permiso «notificaciones», o la IP de este servidor no está en la lista permitida de la llave.',
            404 => 'La URL no es correcta: no se encontró /api/v1/notificaciones bajo PUSH_URL.',
            429 => 'Se superó el límite de envíos de la llave; espera un minuto.',
            599 => 'No hubo respuesta (DNS, firewall, certificado SSL o tiempo de espera).',
        );
        if ($r['codigo'] < 300 && $d) {
            if ((int) $d['total'] === 0) {
                $msg = 'La API respondió bien, pero tu correo no tiene ningún dispositivo registrado: por eso no llega nada. '
                    . 'Registra tu navegador o celular (configura $FIREBASE_WEB para que el módulo lo haga, o regístralo desde la otra aplicación con el mismo correo).';
                $estado = 'aviso';
            } elseif ((int) $d['exitosos'] > 0) {
                $msg = 'Enviada a ' . (int) $d['exitosos'] . ' de ' . (int) $d['total'] . ' dispositivo(s). Si no la ves, revisa el permiso de notificaciones del navegador o del sistema.';
                $estado = 'ok';
            } elseif ($d['estado'] === 'pendiente' || $d['estado'] === 'procesando') {
                $msg = 'Quedó en cola (' . $d['estado'] . '): necesita que el worker de la API esté corriendo (php spark push:worker).';
                $estado = 'aviso';
            } else {
                $msg = 'Firebase rechazó el envío (' . (int) $d['fallidos'] . ' fallidos, ' . (int) $d['invalidos'] . ' tokens vencidos). Revisa la cuenta de servicio de Firebase en la API y vuelve a registrar el dispositivo.';
                $estado = 'error';
            }
        } else {
            $msg = isset($explicacion[$r['codigo']]) ? $explicacion[$r['codigo']] : 'La API respondió con un error.';
            $estado = 'error';
        }
        return array('estado' => $estado, 'mensaje' => $msg, 'email' => $email, 'http' => $r['codigo'],
            'respuesta' => is_array($json) ? $json : substr(strip_tags($r['cuerpo']), 0, 500));
    }

    private static function emailUsuario($u)
    {
        $e = GasDb::scalar('SELECT LTRIM(RTRIM(EMAIL)) FROM T_USUARIOS WHERE ID = ' . (int) $u['id']);
        $e = strtolower(trim((string) ($e ? $e : $u['email'])));
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    private static function corto($t, $max)
    {
        $t = trim(strip_tags((string) $t));
        return function_exists('mb_substr') ? mb_substr($t, 0, $max, 'UTF-8') : substr($t, 0, $max);
    }
}
