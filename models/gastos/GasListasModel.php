<?php
/**
 * Listas de apoyo para los formularios: roles, oficinas, procesos, terceros y usuarios.
 */
class GasListasModel
{
    public static function roles()
    {
        return GasDb::all('SELECT ID, TITULO FROM T_ROLES ORDER BY TITULO');
    }

    /** Organizaciones de venta (1000, 2000…) para asignar flujos por organización. */
    public static function organizaciones()
    {
        $rows = GasDb::all('SELECT DISTINCT LTRIM(RTRIM(ORGANIZACION_VENTAS)) AS ORG FROM T_OFICINAS_VENTAS
                             WHERE ORGANIZACION_VENTAS IS NOT NULL ORDER BY ORG');
        $out = array();
        foreach ($rows as $r) {
            $out[] = $r['ORG'];
        }
        return $out;
    }

    /** Departamentos/procesos (T_CAL_PROCESOS) de la organización del usuario; si no hay, todos. */
    public static function procesos($org)
    {
        $rows = array();
        if ($org !== '') {
            $rows = GasDb::all('SELECT ID, PROCESO FROM T_CAL_PROCESOS WHERE ORGANIZACION = ' . GasDb::str($org) . ' ORDER BY PROCESO');
        }
        return $rows ? $rows : GasDb::all('SELECT ID, PROCESO FROM T_CAL_PROCESOS ORDER BY PROCESO');
    }

    /**
     * Busca terceros en T_TERCEROS por NIT (solo dígitos) o por razón social/nombre.
     * Devuelve NIT, razón social, celular y correo listos para autocompletar.
     */
    public static function terceros($q)
    {
        $q = trim($q);
        if (strlen($q) < 3) {
            return array();
        }
        $esc = str_replace(array('[', '%', '_'), array('[[]', '[%]', '[_]'), $q);
        if (ctype_digit($q)) {
            $where = 'NIT LIKE ' . GasDb::str($esc . '%');
        } else {
            $lit   = GasDb::str('%' . $esc . '%');
            $where = "(RAZON_COMERCIAL LIKE $lit OR NOMBRES LIKE $lit)";
        }
        $rows = GasDb::all(
            "SELECT TOP 15 LTRIM(RTRIM(NIT)) AS NIT,
                    COALESCE(NULLIF(LTRIM(RTRIM(RAZON_COMERCIAL)), ''), LTRIM(RTRIM(NOMBRES))) AS NOMBRE,
                    COALESCE(NULLIF(LTRIM(RTRIM(TELEFONO1)), ''), LTRIM(RTRIM(TELEFONO2))) AS CELULAR,
                    LTRIM(RTRIM(EMAIL)) AS EMAIL
               FROM T_TERCEROS
              WHERE $where AND ISNULL(BORRADO, 0) = 0
              ORDER BY NIT"
        );
        return $rows;
    }

    /** Datos del usuario de la sesión para prellenar "datos de quien solicita" (F-FR-023). */
    public static function usuario($id)
    {
        return GasDb::row("SELECT LTRIM(RTRIM(NOMBRES)) + ' ' + LTRIM(RTRIM(APELLIDOS)) AS NOMBRE, LTRIM(RTRIM(IDENTIFICACION)) AS IDENTIFICACION,
                                  LTRIM(RTRIM(CELULAR)) AS CELULAR, LTRIM(RTRIM(EMAIL)) AS EMAIL, LTRIM(RTRIM(EXT)) AS EXT
                             FROM T_USUARIOS WHERE ID = " . (int) $id);
    }

    /** Usuarios activos por login o nombre (para asignar un paso a una persona concreta). */
    public static function usuarios($q)
    {
        $q = trim($q);
        if (strlen($q) < 3) {
            return array();
        }
        $esc = str_replace(array('[', '%', '_'), array('[[]', '[%]', '[_]'), $q);
        $lit = GasDb::str('%' . $esc . '%');
        return GasDb::all(
            "SELECT TOP 20 ID, LOGIN, LTRIM(RTRIM(NOMBRES)) + ' ' + LTRIM(RTRIM(APELLIDOS)) AS NOMBRE
               FROM T_USUARIOS
              WHERE ESTADO = 'A' AND (LOGIN LIKE $lit OR NOMBRES LIKE $lit OR APELLIDOS LIKE $lit)
              ORDER BY NOMBRES"
        );
    }
}
