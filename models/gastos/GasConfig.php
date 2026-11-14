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

    /**
     * Códigos de los formatos de viáticos por organización de venta (ses_NumOrg):
     *  - solicitud:    solicitud de anticipo de viáticos
     *  - legalizacion: legalización de la solicitud de anticipo de viáticos
     * Las organizaciones que no estén aquí usan los de la 2000.
     */
    public static $FORMATOS_VIATICOS = array(
        '1000' => array('solicitud' => 'F-FR-0137', 'legalizacion' => 'F-FR-0138'),
        '2000' => array('solicitud' => 'F-FR-023',  'legalizacion' => 'F-FR-024'),
    );

    /** Códigos de los formatos de viáticos de una organización (ver $FORMATOS_VIATICOS). */
    public static function formatosViaticos($org)
    {
        $org = trim((string) $org);
        return isset(self::$FORMATOS_VIATICOS[$org]) ? self::$FORMATOS_VIATICOS[$org] : self::$FORMATOS_VIATICOS['2000'];
    }

    /**
     * Nombre con el que aparece la opción por defecto de «¿De dónde sale el pago?» según la organización
     * de la solicitud. Las organizaciones que no estén aquí muestran «Roma».
     */
    public static $NOMBRE_EMPRESA = array(
        '1000' => 'MULTIDROGAS',
        '2000' => 'Roma',
    );

    public static function nombreEmpresa($org)
    {
        $org = trim((string) $org);
        return isset(self::$NOMBRE_EMPRESA[$org]) ? self::$NOMBRE_EMPRESA[$org] : 'Roma';
    }

    /** Máximo de caracteres del número de preliminar. */
    const MAX_PRELIMINAR = 10;

    /** Dias que tiene el colaborador para legalizar despues del viaje (clausula del formato de solicitud). */
    const DIAS_LEGALIZACION = 30;

    /** Tamano maximo por PDF, en MB. */
    const MAX_PDF_MB = 10;

    /**
     * true  = la conexion mssql trabaja en Latin1/cp1252 (lo normal con collation Modern_Spanish):
     *         se convierte UTF-8 -> Latin1 al escribir y Latin1 -> UTF-8 al leer.
     * false = la conexion ya trabaja en UTF-8.
     */
    const DB_LATIN1 = true;
}
