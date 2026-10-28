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

    /**
     * Roles cuyo TÍTULO (T_ROLES.TITULO) da permisos de administrador, además de $ROLES_ADMIN.
     * Por defecto Gerencia administrativa tiene los mismos permisos que el administrador.
     */
    public static $ROLES_ADMIN_TITULOS = array('GERENCIA ADMINISTRATIVA');

    /**
     * Tema de color por organización de venta (ses_NumOrg): colorea letras, botones, pestañas y
     * gráficas (no el fondo ni la barra superior). Las organizaciones que no estén aquí usan el tema por defecto.
     */
    public static $TEMAS = array(
        '1000' => 'amarillo', // amarillo suave
        '2000' => 'turquesa', // verde azulado (#279ca2)
    );

    /**
     * Función de tu proyecto que abre la conexión mssql (se prueba en este orden) y el
     * archivo, relativo a models/, donde está definida.
     */
    public static $FUNCIONES_CONEXION = array('conectar', 'conexion');
    const ARCHIVO_CONEXION = 'funciones.php';

    /** Carpeta fisica de los PDF (no debe ser accesible por URL; ver uploads/gastos/.htaccess). */
    public static function dirUploads()
    {
        return dirname(dirname(dirname(__FILE__))) . '/uploads/gastos';
    }

    /**
     * Retefuente de la legalizacion de viaticos (formato F-FR-024): a cada gasto de hotel o
     * alimentacion superior al tope se le descuenta la tasa.
     */
    const RETEFUENTE_TOPE = 110000;
    const RETEFUENTE_TASA = 0.025;
    public static $RETEFUENTE_TIPOS = array('HOTEL', 'ALIMENT');

    /** Dias que tiene el colaborador para legalizar despues del viaje (clausula del F-FR-023). */
    const DIAS_LEGALIZACION = 30;

    /** Tamano maximo por PDF, en MB. */
    const MAX_PDF_MB = 10;

    /**
     * FTP de soportes. El navegador sube cada archivo con subirArchivos() de lib/js/servicios.js
     * (models/ServiceUploadFile.php) a la carpeta FTP_RUTA y la solicitud solo guarda la ruta.
     *  - FTP_ACTIVO = false: se usa la carpeta local uploads/gastos/ como antes.
     *  - FTP_URL: URL pública de esa carpeta, para abrir los archivos. Compruébala abriendo un archivo
     *    subido: si la carpeta «web» del FTP es la raíz del sitio, quita «/web» de la URL.
     */
    const FTP_ACTIVO = true;
    const FTP_RUTA   = '/web/gastos';
    const FTP_URL    = 'https://app.pwmultiroma.com/web/gastos';

    /**
     * Tipos de archivo permitidos por grupo (extensiones). El servidor vuelve a validar la extensión
     * de cada archivo que llega del FTP antes de registrarlo.
     */
    public static $EXT_ARCHIVOS = array(
        'pdf'       => array('pdf'),
        'imagen'    => array('pdf', 'png', 'jpg', 'jpeg', 'webp'),
        'documento' => array('pdf', 'png', 'jpg', 'jpeg', 'webp', 'xls', 'xlsx', 'csv', 'doc', 'docx', 'txt'),
    );

    /**
     * Notificaciones push (proyecto notificaciones-push-roma). Al pasar una solicitud a un paso se
     * avisa a quien debe actuar (por su correo de T_USUARIOS). PUSH_URL vacío = desactivado.
     * La llave de API (scope «notificaciones» y «tokens») se lee de la variable de entorno
     * GASTOS_PUSH_API_KEY; si no existe, de PUSH_API_KEY. Nunca la publiques en el repositorio.
     */
    const PUSH_URL     = '';            // p. ej. 'https://api.pwmultiroma.com/push' (sin /api/v1)
    const PUSH_API_KEY = '';
    const PUSH_TIMEOUT = 15;            // segundos: si la API no responde, el paso se registra igual
    /**
     * true = se envía la organización (ses_NumOrg del usuario / organización de venta de la solicitud),
     * como en tus otros sistemas. Es obligatoria si la llave es global.
     */
    const PUSH_ENVIAR_ORGANIZACION = true;
    /** Ruta a cacert.pem si el servidor no reconoce el certificado de la API ('' = el del sistema). */
    const PUSH_CAINFO  = '';
    /** URL pública (https) de views/gastos/, para que la notificación abra la solicitud. */
    const URL_MODULO   = '';            // p. ej. 'https://app.pwmultiroma.com/views/gastos/'

    /**
     * Configuración web de Firebase y llave VAPID para registrar el navegador de cada usuario en la
     * API de notificaciones. Vacía = el módulo no registra dispositivos (si tu intranet ya registra
     * los tokens con el correo del usuario, déjala vacía).
     */
    public static $FIREBASE_WEB = array(
        // 'apiKey' => '', 'authDomain' => '', 'projectId' => '', 'storageBucket' => '',
        // 'messagingSenderId' => '', 'appId' => '', 'vapidKey' => '',
    );

    /** Llave de la API de notificaciones (entorno primero). */
    public static function pushApiKey()
    {
        $k = getenv('GASTOS_PUSH_API_KEY');
        return $k ? $k : self::PUSH_API_KEY;
    }

    /**
     * true  = la conexion mssql trabaja en Latin1/cp1252 (lo normal con collation Modern_Spanish):
     *         se convierte UTF-8 -> Latin1 al escribir y Latin1 -> UTF-8 al leer.
     * false = la conexion ya trabaja en UTF-8.
     */
    const DB_LATIN1 = true;
}
