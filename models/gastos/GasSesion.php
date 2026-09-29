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

    public static function esAdmin($u)
    {
        return in_array((int) $u['rolId'], array_map('intval', GasConfig::$ROLES_ADMIN), true);
    }
}

/** Sesión inexistente o vencida: el controlador la responde con 401. */
class GasSesionException extends GasError
{
}
