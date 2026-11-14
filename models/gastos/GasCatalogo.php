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
                'ayuda'  => '',
            ),
            'DECISION_ANTICIPO' => array(
                'nombre' => 'Definir anticipo y tercero', 'actor' => 'SOLICITANTE', 'decide' => false,
                'tipos'  => array(self::TIPO_COTIZACION),
                'ayuda'  => 'El solicitante indica si necesita anticipo (por cotización) o si va a legalizar. Con anticipo registra el valor; al legalizar registra aquí mismo el preliminar y la factura (el paso «Legalización» queda hecho).',
            ),
            'SOLICITAR_ANTICIPO' => array(
                'nombre' => 'Solicitar anticipo', 'actor' => 'SOLICITANTE', 'decide' => false,
                'tipos'  => array(self::TIPO_ANTICIPO),
                'ayuda'  => 'Anticipo por factura (valor) o por viáticos (formato de solicitud de anticipo de viáticos, impreso, firmado y con el finiquito), con datos del tercero y si requiere soporte de pago.',
            ),
            'MONTAR_PRELIMINAR' => array(
                'nombre' => 'Legalización', 'actor' => 'SOLICITANTE', 'decide' => false,
                'tipos'  => array(self::TIPO_COTIZACION, self::TIPO_ANTICIPO, self::TIPO_FACTURA),
                'ayuda'  => 'El solicitante legaliza el gasto: registra el número de preliminar, la fecha y el valor de la factura, reparte el valor entre uno o más centros de costo y adjunta la factura en PDF (y, si hay, el contrato u otro soporte). En un anticipo por viáticos, en su lugar diligencia el formato de legalización y sube los soportes en un solo PDF; el sistema calcula el saldo.',
            ),
            'APROBAR' => array(
                'nombre' => 'Aprobar / rechazar', 'actor' => 'ROL', 'decide' => true,
                'tipos'  => array(self::TIPO_COTIZACION, self::TIPO_ANTICIPO, self::TIPO_FACTURA),
                'ayuda'  => 'Aprueba, devuelve o rechaza (el comentario es opcional). Úsalo para aprobar anticipos o legalizaciones.',
            ),
            'CONTABILIZAR' => array(
                'nombre' => 'Contabilizar', 'actor' => 'ROL', 'decide' => false,
                'tipos'  => array(self::TIPO_COTIZACION, self::TIPO_ANTICIPO, self::TIPO_FACTURA),
                'ayuda'  => 'Contabilidad monta el número de contabilización (empieza por 19 o 17, máx. 10 dígitos) y, en la compensación, el número de compensación; puede adjuntar un archivo o pegar un pantallazo (Ctrl+V).',
            ),
            'PAGAR' => array(
                'nombre' => 'Pagar y montar comprobante', 'actor' => 'ROL', 'decide' => false,
                'tipos'  => array(self::TIPO_COTIZACION, self::TIPO_ANTICIPO, self::TIPO_FACTURA),
                'ayuda'  => 'Tesorería registra el número de comprobante ZP y la fecha de pago. Si el solicitante indicó que requiere soporte de pago, sube el soporte (PDF o imagen; también puede pegar un pantallazo con Ctrl+V).',
            ),
            'PAGAR_SALDO' => array(
                'nombre' => 'Devolver saldo al empleado', 'actor' => 'ROL', 'decide' => false,
                'tipos'  => array(self::TIPO_ANTICIPO),
                'ayuda'  => 'Tesorería evidencia que se le devolvió al empleado el saldo a su favor de la legalización de viáticos: número de comprobante, fecha y soporte de la transferencia. Úsalo con la condición «Solo si la legalización deja saldo a favor del empleado», después de la compensación de Contabilidad.',
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
            'SALDO_EMPLEADO'    => 'Solo si la legalización de viáticos deja saldo a favor del empleado',
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
            case 'SALDO_EMPLEADO':      return $hay && $tipo === 'VIATICOS' && isset($sol['SALDO_LEGALIZACION'])
                                            && $sol['SALDO_LEGALIZACION'] !== null && round((float) $sol['SALDO_LEGALIZACION'], 2) < 0;
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

    /** Conceptos del "valor presupuestado" del formato de solicitud de anticipo de viáticos (F-FR-023 / F-FR-0137). */
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

    /** Columnas de gasto del formato de legalización de viáticos (F-FR-024 / F-FR-0138). */
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

    /**
     * De dónde sale el pago de una legalización con factura. «Roma» es el valor por defecto;
     * «Fondo» y «Nota» exigen el número del fondo o de la nota; «Tarjeta» exige los últimos
     * 4 dígitos, el titular y el banco.
     */
    public static function fondos()
    {
        return array(
            'ROMA'    => 'Roma',
            'FONDO'   => 'Fondo',
            'NOTA'    => 'Nota',
            'TARJETA' => 'Tarjeta',
        );
    }

    /** Fondos con número obligatorio. */
    public static function fondosConNumero()
    {
        return array('FONDO', 'NOTA');
    }

    /**
     * Número de contabilización válido: solo dígitos, empieza por 19 o por 17 y tiene máximo 10.
     * Es la misma regla de pasos.js (Pasos.RE_CONTABILIZACION).
     */
    public static function contabilizacionValida($num)
    {
        return is_string($num) && preg_match('/^1[79][0-9]{0,8}$/', $num) === 1;
    }

    /**
     * Datos de la tarjeta con la que se paga: últimos 4 dígitos, titular y banco (todos obligatorios).
     * Devuelve array(ultimos4, titular, banco) o lanza GasError.
     */
    public static function validarTarjeta($in)
    {
        $ult = isset($in['tarjeta_ultimos4']) ? trim($in['tarjeta_ultimos4']) : '';
        $tit = isset($in['tarjeta_titular']) ? trim($in['tarjeta_titular']) : '';
        $ban = isset($in['tarjeta_banco']) ? trim($in['tarjeta_banco']) : '';
        if (!preg_match('/^[0-9]{4}$/', $ult)) {
            throw new GasError('Tarjeta: escribe los últimos 4 dígitos (solo números).');
        }
        if ($tit === '') {
            throw new GasError('Tarjeta: indica el titular.');
        }
        if ($ban === '') {
            throw new GasError('Tarjeta: indica el banco.');
        }
        return array($ult, $tit, $ban);
    }

    /**
     * Reparto del valor de la factura entre centros de costo.
     *  $filas  = [ ['centro' => 'CC1', 'valor' => '1.000'], ... ] tal como llegan del formulario
     *  $total  = valor de la factura (la suma debe ser igual)
     *  $existe = función (centro) => bool que dice si el centro existe en T_CENTROS_COSTOS
     * Las filas vacías se ignoran. Devuelve [ ['centro' => .., 'valor' => float], ... ] o lanza GasError.
     */
    public static function validarCentros($filas, $total, $existe)
    {
        $out  = array();
        $suma = 0.0;
        $n    = 0;
        foreach (is_array($filas) ? $filas : array() as $f) {
            if (!is_array($f)) {
                continue;
            }
            $cc    = isset($f['centro']) ? trim($f['centro']) : '';
            $valor = isset($f['valor']) ? round(GasDb::aNumero($f['valor']), 2) : 0.0;
            if ($cc === '' && $valor == 0) {
                continue; // fila vacía
            }
            $n++;
            if ($cc === '') {
                throw new GasError("Centro de costo $n: selecciónalo de la lista.");
            }
            if ($valor <= 0) {
                throw new GasError("Centro de costo $n ($cc): indica un valor mayor que cero.");
            }
            if (isset($out[$cc])) {
                throw new GasError("El centro de costo $cc está repetido: suma sus valores en una sola fila.");
            }
            if (!call_user_func($existe, $cc)) {
                throw new GasError("El centro de costo «{$cc}» no existe: selecciónalo de la lista.");
            }
            $out[$cc] = array('centro' => $cc, 'valor' => $valor);
            $suma += $valor;
        }
        if (!$out) {
            throw new GasError('Indica al menos un centro de costo con su valor.');
        }
        if (abs(round($suma, 2) - round((float) $total, 2)) > 0.009) {
            throw new GasError('La suma de los centros de costo (' . number_format($suma, 2, ',', '.') . ') debe ser igual al valor de la factura ('
                . number_format((float) $total, 2, ',', '.') . ').');
        }
        return array_values($out);
    }

    /** Niveles de T_ROLES_GASTOS_INFO. */
    public static function nivelesGasto()
    {
        return array(1 => 'Gerentes de proceso', 2 => 'Coordinadores', 3 => 'Auxiliares');
    }
}
