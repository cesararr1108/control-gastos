<?php
/**
 * Catalogos fijos del modulo: tipos de flujo, acciones de paso y condiciones.
 *
 * Las ACCIONES son el "vocabulario" de pasos: cada una tiene su formulario y su efecto
 * sobre la solicitud. El administrador arma un flujo eligiendo acciones, su orden,
 * quien las ejecuta y en que condicion aplican (pantalla Flujos).
 */
class GasCatalogo
{
    const TIPO_COTIZACION = 'COTIZACION';
    const TIPO_ANTICIPO   = 'ANTICIPO';
    const TIPO_FACTURA    = 'FACTURA';

    public static function tipos()
    {
        return array(
            self::TIPO_COTIZACION => array('nombre' => 'Cotización', 'prefijo' => 'COT'),
            self::TIPO_ANTICIPO   => array('nombre' => 'Anticipo',   'prefijo' => 'ANT'),
            self::TIPO_FACTURA    => array('nombre' => 'Factura',    'prefijo' => 'FAC'),
        );
    }

    /**
     * Acciones disponibles.
     *  - actor:  quien la ejecuta por defecto (SOLICITANTE o ROL). Es solo una sugerencia para la UI.
     *  - tipos:  en que tipos de flujo tiene sentido usarla.
     *  - decide: true si el ejecutor puede aprobar o rechazar (un rechazo cierra la solicitud).
     */
    public static function acciones()
    {
        return array(
            'SUBIR_COTIZACIONES' => array(
                'nombre' => 'Subir cotizaciones (3 PDF)', 'actor' => 'SOLICITANTE', 'decide' => false,
                'tipos'  => array(self::TIPO_COTIZACION),
                'ayuda'  => 'El solicitante sube 3 cotizaciones en PDF e indica el proceso y si requiere soporte de pago.',
            ),
            'AUTORIZAR_COTIZACION' => array(
                'nombre' => 'Autorizar cotización', 'actor' => 'ROL', 'decide' => true,
                'tipos'  => array(self::TIPO_COTIZACION),
                'ayuda'  => 'Elige una de las tres cotizaciones y deja un comentario (o rechaza).',
            ),
            'DECISION_ANTICIPO' => array(
                'nombre' => 'Definir anticipo y tercero', 'actor' => 'SOLICITANTE', 'decide' => false,
                'tipos'  => array(self::TIPO_COTIZACION),
                'ayuda'  => 'El solicitante indica si necesita anticipo (por cotización) o si ya tiene el preliminar. Con anticipo registra el valor; sin anticipo registra aquí mismo el preliminar y la factura (el paso «Montar preliminar» queda hecho).',
            ),
            'SOLICITAR_ANTICIPO' => array(
                'nombre' => 'Solicitar anticipo', 'actor' => 'SOLICITANTE', 'decide' => false,
                'tipos'  => array(self::TIPO_ANTICIPO),
                'ayuda'  => 'Anticipo por factura (valor) o por viáticos (formulario de gastos de viaje), con datos del tercero.',
            ),
            'MONTAR_PRELIMINAR' => array(
                'nombre' => 'Montar preliminar', 'actor' => 'SOLICITANTE', 'decide' => false,
                'tipos'  => array(self::TIPO_COTIZACION, self::TIPO_ANTICIPO, self::TIPO_FACTURA),
                'ayuda'  => 'El solicitante registra el número de preliminar, la fecha y el valor de la factura y adjunta la factura en PDF. En un anticipo por viáticos, en su lugar diligencia la legalización de gastos y sube los soportes en un solo PDF; el sistema calcula el saldo.',
            ),
            'APROBAR' => array(
                'nombre' => 'Aprobar / rechazar', 'actor' => 'ROL', 'decide' => true,
                'tipos'  => array(self::TIPO_COTIZACION, self::TIPO_ANTICIPO, self::TIPO_FACTURA),
                'ayuda'  => 'Aprueba o rechaza con comentario. Úsalo para aprobar anticipos o preliminares.',
            ),
            'CONTABILIZAR' => array(
                'nombre' => 'Contabilizar', 'actor' => 'ROL', 'decide' => false,
                'tipos'  => array(self::TIPO_COTIZACION, self::TIPO_ANTICIPO, self::TIPO_FACTURA),
                'ayuda'  => 'Contabilidad monta el número de contabilización y, si aplica, el de causación de compensación.',
            ),
            'PAGAR' => array(
                'nombre' => 'Pagar y montar comprobante', 'actor' => 'ROL', 'decide' => false,
                'tipos'  => array(self::TIPO_COTIZACION, self::TIPO_ANTICIPO, self::TIPO_FACTURA),
                'ayuda'  => 'Tesorería registra el número de comprobante ZP y sube el comprobante en PDF. Cierra la solicitud.',
            ),
        );
    }

    /** Condiciones que deciden si un paso aplica. Se evalúan sobre los datos de la solicitud. */
    public static function condiciones()
    {
        return array(
            'SIEMPRE'           => 'Siempre',
            'ANTICIPO_SI'       => 'Solo si hay anticipo',
            'ANTICIPO_NO'       => 'Solo si NO hay anticipo',
            'ANTICIPO_FACTURA'  => 'Solo si el anticipo es por factura',
            'ANTICIPO_VIATICOS' => 'Solo si el anticipo es por viáticos',
            'ANTICIPO_COTIZACION' => 'Solo si el anticipo es por cotización',
        );
    }

    /** Evalúa una condición contra la fila de la solicitud. */
    public static function cumpleCondicion($condicion, $sol)
    {
        $hay  = !empty($sol['REQUIERE_ANTICIPO']);
        $tipo = isset($sol['TIPO_ANTICIPO']) ? $sol['TIPO_ANTICIPO'] : '';
        switch ($condicion) {
            case 'ANTICIPO_SI':         return $hay;
            case 'ANTICIPO_NO':         return !$hay;
            case 'ANTICIPO_FACTURA':    return $hay && $tipo === 'FACTURA';
            case 'ANTICIPO_VIATICOS':   return $hay && $tipo === 'VIATICOS';
            case 'ANTICIPO_COTIZACION': return $hay && $tipo === 'COTIZACION';
            default:                    return true; // SIEMPRE
        }
    }

    public static function responsables()
    {
        return array(
            'SOLICITANTE' => 'El solicitante',
            'ROL'         => 'Un rol',
            'USUARIO'     => 'Un usuario específico',
        );
    }

    /** Conceptos del "valor presupuestado" del formato F-FR-023 (solicitud de viáticos). */
    public static function conceptosViaticos()
    {
        return array(
            'TIQUETES_AEREOS'     => 'Tiquetes aéreos',
            'TIQUETES_TERRESTRES' => 'Tiquetes terrestres',
            'TAXIS_BUSES'         => 'Taxis y buses',
            'PEAJES'              => 'Peajes',
            'HOSPEDAJE'           => 'Hospedaje',
            'ALIMENTACION'        => 'Alimentación',
            'FLETES'              => 'Fletes y acarreos',
            'VIATICOS_ADMIN'      => 'Viáticos admin.',
            'OTROS'               => 'Otros',
        );
    }

    /** Columnas de gasto del formato F-FR-024 (legalización de viáticos). */
    public static function tiposGastoLegalizacion()
    {
        return array(
            'TRANSP'    => 'Transp',
            'BUS_TAXIS' => 'Bus/taxis',
            'HOTEL'     => 'Hotel',
            'ALIMENT'   => 'Aliment',
            'ATENCION'  => 'Atención',
            'GASOLINA'  => 'Gasolina',
            'SERVICIOS' => 'Servicios',
            'OTROS'     => 'Otros',
        );
    }

    /** Fondos de los que puede salir el pago de un preliminar con factura. */
    public static function fondos()
    {
        return array(
            'FONDO_ROMA'        => 'Fondo Roma',
            'FONDO_PROVEEDORES' => 'Fondo proveedores',
        );
    }
}
