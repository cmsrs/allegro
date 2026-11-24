<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/AllegroOAuth.php';
require_once __DIR__ . '/../../config.php';

use App\AllegroOAuth;

try {
    //$a = 11/0;
    $oauth = new AllegroOAuth($config);

    $token = $oauth->getAccessToken();

    echo "Token jest aktualny!\n";

    echo "Długość tokena: " . strlen($token) . "\n";

} catch (Throwable $e) {
    echo " ";
    echo "  RUN: php ./src/cli/authorize_device.php  ";
    echo " ";
    echo "BŁĄD: " . $e->getMessage() . "\n";
    

}

