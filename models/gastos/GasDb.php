<?php
/**
 * Acceso a SQL Server con la extension mssql_ (PHP 5.5).
 *
 * mssql_ no tiene consultas parametrizadas, asi que TODO valor que entre a un SQL debe
 * pasar por GasDb::str() / GasDb::int() / GasDb::num() / GasDb::fecha().
 */
class GasDb
{
    private static $link = null;

    /** Devuelve la conexion (usa tu funcion conexion()) y la reutiliza en la peticion. */
    public static function link()
    {
        if (self::$link === null) {
            if (!function_exists('conexion')) {
                require_once dirname(dirname(__FILE__)) . '/conexion.php';
            }
            self::$link = conexion();
        }
        return self::$link;
    }

    /* ---------------------------------------------------------------- escapes */

    /** Cadena SQL literal: 'texto' (o NULL si el valor es null o ''). */
    public static function str($v, $max = 0)
    {
        if ($v === null) {
            return 'NULL';
        }
        $v = trim((string) $v);
        if ($v === '') {
            return 'NULL';
        }
        $v = str_replace("\0", '', $v);
        if ($max > 0 && function_exists('mb_substr')) {
            $v = mb_substr($v, 0, $max, 'UTF-8');
        }
        if (GasConfig::DB_LATIN1) {
            $v = utf8_decode($v);
        }
        return "'" . str_replace("'", "''", $v) . "'";
    }

    /** Cadena SQL literal que conserva el vacio ('' en lugar de NULL); util en comparaciones con ISNULL. */
    public static function lit($v)
    {
        $s = self::str($v);
        return $s === 'NULL' ? "''" : $s;
    }

    /** Entero seguro (NULL si viene vacio). */
    public static function int($v)
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return 'NULL';
        }
        return (string) intval($v);
    }

    /** Decimal seguro; acepta "1.234,50" o "1234.50". NULL si viene vacio. */
    public static function num($v)
    {
        if ($v === null || $v === '') {
            return 'NULL';
        }
        return number_format(self::aNumero($v), 2, '.', '');
    }

    /**
     * Convierte a float importes escritos a la colombiana: "1.250.000,75" -> 1250000.75,
     * "150.000" -> 150000 (punto seguido de 3 dígitos = miles), "1234.5" -> 1234.5.
     * La misma regla usa Gastos.num() en JavaScript.
     */
    public static function aNumero($v)
    {
        $v = trim((string) $v);
        if (strpos($v, ',') !== false) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
            $v = str_replace('.', '', $v);
        }
        return is_numeric($v) ? (float) $v : 0.0;
    }

    /** Fecha AAAA-MM-DD valida o NULL. */
    public static function fecha($v)
    {
        $v = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "'" . $v . "'";
        }
        return 'NULL';
    }

    /** Booleano a bit (0/1). */
    public static function bit($v)
    {
        return ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'SI') ? '1' : '0';
    }

    /* --------------------------------------------------------------- consultas */

    /** Ejecuta un SQL; lanza Exception si falla. Devuelve el recurso resultado. */
    public static function query($sql)
    {
        $res = @mssql_query($sql, self::link());
        if ($res === false) {
            throw new Exception('Error SQL: ' . mssql_get_last_message());
        }
        return $res;
    }

    /** Todas las filas como arreglos asociativos, con texto en UTF-8. */
    public static function all($sql)
    {
        $res  = self::query($sql);
        $rows = array();
        if (is_resource($res)) {
            while ($row = mssql_fetch_assoc($res)) {
                $rows[] = self::utf8($row);
            }
            mssql_free_result($res);
        }
        return $rows;
    }

    /** Primera fila o null. */
    public static function row($sql)
    {
        $rows = self::all($sql);
        return $rows ? $rows[0] : null;
    }

    /** Primer valor de la primera fila o null. */
    public static function scalar($sql)
    {
        $row = self::row($sql);
        return $row ? reset($row) : null;
    }

    /** Ejecuta un INSERT y devuelve el ID generado. */
    public static function insert($sql)
    {
        $id = self::scalar('SET NOCOUNT ON; ' . $sql . '; SELECT CAST(SCOPE_IDENTITY() AS int) AS id');
        return $id === null ? 0 : (int) $id;
    }

    /* ------------------------------------------------------------ transacciones */

    public static function begin()    { self::query('BEGIN TRAN'); }
    public static function commit()   { self::query('COMMIT TRAN'); }
    public static function rollback() { @mssql_query('IF @@TRANCOUNT > 0 ROLLBACK TRAN', self::link()); }

    /* ---------------------------------------------------------------- internos */

    /** Asegura UTF-8 en cada texto (json_encode falla con bytes Latin1). */
    private static function utf8($row)
    {
        foreach ($row as $k => $v) {
            if (is_string($v) && $v !== '' && !(function_exists('mb_check_encoding') && mb_check_encoding($v, 'UTF-8'))) {
                $row[$k] = utf8_encode($v);
            }
        }
        return $row;
    }
}
