<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/AllegroApi.php';

use App\AllegroApi;

try {

    $allegro = new AllegroApi($config);

    $token = $allegro->getAccessToken();

    $categories = $allegro->getCategories($token);

    echo "\nSUKCES! API ALLEGRO DZIALA.\n";

} catch (Throwable $e) {

    echo "\nWYSTAPIL BLAD:\n";
    echo $e->getMessage() . "\n";

}
