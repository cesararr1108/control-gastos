<?php
/**
 * PLANTILLA. Si ya tienes tu propia funcion conexion() en models/, NO uses este archivo:
 * GasDb solo lo carga cuando la funcion conexion() no existe.
 *
 * Debe devolver el recurso de conexion de mssql_connect() con la base ya seleccionada.
 */
if (!function_exists('conexion')) {
    function conexion()
    {
        $link = mssql_connect('SERVIDOR', 'USUARIO', 'CLAVE');
        if (!$link) {
            throw new Exception('No se pudo conectar a SQL Server.');
        }
        mssql_select_db('BASE_DE_DATOS', $link);
        return $link;
    }
}
