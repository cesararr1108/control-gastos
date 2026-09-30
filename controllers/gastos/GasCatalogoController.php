<?php
/**
 * Listas para poblar formularios y autocompletar terceros/usuarios.
 */
class GasCatalogoController
{
    /** Todo lo que la UI necesita para pintar los formularios. */
    public static function opciones($u, $in, $files)
    {
        $out = array(
            'usuario'    => $u,
            'esAdmin'    => GasSesion::esAdmin($u),
            'tipos'      => GasCatalogo::tipos(),
            'acciones'   => GasCatalogo::acciones(),
            'condiciones' => GasCatalogo::condiciones(),
            'responsables' => GasCatalogo::responsables(),
            'conceptos'  => GasCatalogo::conceptosViaticos(),
            'tiposGasto' => GasCatalogo::tiposGastoLegalizacion(),
            'retefuente' => array('tope' => GasConfig::RETEFUENTE_TOPE, 'tasa' => GasConfig::RETEFUENTE_TASA, 'tipos' => GasConfig::$RETEFUENTE_TIPOS),
            'diasLegalizacion' => GasConfig::DIAS_LEGALIZACION,
            'yo'         => GasListasModel::usuario($u['id']),
            'procesos'   => GasListasModel::procesos($u['org']),
            'pasosActuales' => GasSolicitudModel::pasosActuales(),
        );
        if ($out['esAdmin']) {
            $out['roles']    = GasListasModel::roles();
            $out['organizaciones'] = GasListasModel::organizaciones();
        }
        return $out;
    }

    public static function terceros($u, $in, $files)
    {
        return GasListasModel::terceros(isset($in['q']) ? $in['q'] : '');
    }

    public static function usuarios($u, $in, $files)
    {
        return GasListasModel::usuarios(isset($in['q']) ? $in['q'] : '');
    }
}
