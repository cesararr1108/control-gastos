<?php
/**
 * Lee al usuario logueado desde las variables de sesión existentes (ses_*).
 */
class GasSesion
{
    /** Devuelve el usuario actual como arreglo, o lanza Exception si no hay sesión. */
    public static function actual()
    {
        if (session_id() === '') {
            session_start();
        }
        if (empty($_SESSION['ses_Id'])) {
            throw new GasSesionException('Tu sesión expiró. Vuelve a iniciar sesión.');
        }
        return array(
            'id'      => (int) $_SESSION['ses_Id'],
            'login'   => isset($_SESSION['ses_Login']) ? $_SESSION['ses_Login'] : '',
            'nombre'  => isset($_SESSION['ses_Usuario']) ? $_SESSION['ses_Usuario'] : '',
            'email'   => isset($_SESSION['ses_Email']) ? $_SESSION['ses_Email'] : '',
            'rolId'   => isset($_SESSION['ses_RolesId']) ? (int) $_SESSION['ses_RolesId'] : 0,
            'dpto'    => isset($_SESSION['ses_DepId']) ? (int) $_SESSION['ses_DepId'] : 0,
            'org'     => isset($_SESSION['ses_NumOrg']) ? trim($_SESSION['ses_NumOrg']) : '',
            'oficina' => isset($_SESSION['ses_OfcVentas']) ? trim($_SESSION['ses_OfcVentas']) : '',
        );
    }

    /**
     * ¿Tiene permisos de administrador? Sí si su rol está en GasConfig::$ROLES_ADMIN o si el título
     * del rol coincide con GasConfig::$ROLES_ADMIN_TITULOS (p. ej. Gerencia administrativa).
     * El resultado de la consulta del título se guarda en la sesión.
     */
    public static function esAdmin($u)
    {
        $rol = (int) $u['rolId'];
        if (in_array($rol, array_map('intval', GasConfig::$ROLES_ADMIN), true)) {
            return true;
        }
        if (!GasConfig::$ROLES_ADMIN_TITULOS || $rol <= 0) {
            return false;
        }
        if (isset($_SESSION['gas_admin_rol']) && is_array($_SESSION['gas_admin_rol']) && $_SESSION['gas_admin_rol'][0] === $rol) {
            return $_SESSION['gas_admin_rol'][1];
        }
        try {
            $titulo = (string) GasDb::scalar('SELECT TITULO FROM T_ROLES WHERE ID = ' . $rol);
        } catch (Exception $e) {
            return false; // sin BD no se concede nada
        }
        $es = false;
        foreach (GasConfig::$ROLES_ADMIN_TITULOS as $t) {
            if ($t !== '' && stripos($titulo, $t) !== false) {
                $es = true;
                break;
            }
        }
        $_SESSION['gas_admin_rol'] = array($rol, $es);
        return $es;
    }

    /** Tema de color según la organización del usuario ('' = por defecto). */
    public static function tema($u)
    {
        return isset(GasConfig::$TEMAS[$u['org']]) ? GasConfig::$TEMAS[$u['org']] : '';
    }
}

/** Sesión inexistente o vencida: el controlador la responde con 401. */
class GasSesionException extends GasError
{
}
