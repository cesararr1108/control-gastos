<?php
/**
 * Arranque común de las vistas: exige sesión y deja $GAS_USR / $GAS_ADMIN listos.
 * Ajusta $LOGIN_URL a la pantalla de ingreso de tu aplicación.
 */
$LOGIN_URL = '../login.php';

require_once dirname(__FILE__) . '/../../models/gastos/GasError.php';
require_once dirname(__FILE__) . '/../../models/gastos/GasConfig.php';
require_once dirname(__FILE__) . '/../../models/gastos/GasDb.php';
require_once dirname(__FILE__) . '/../../models/gastos/GasSesion.php';

try {
    $GAS_USR   = GasSesion::actual();
    $GAS_ADMIN = GasSesion::esAdmin($GAS_USR);
    $GAS_TEMA  = GasSesion::tema($GAS_USR);
} catch (Exception $e) {
    header('Location: ' . $LOGIN_URL);
    exit;
}
