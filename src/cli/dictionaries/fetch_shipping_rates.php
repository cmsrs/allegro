<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroDictionaryEndpoint;
use App\Services\Rest\CategoryDetailsJsonWriter;

require_once dirname(__DIR__, 3) . '/config.php';

try {
    echo "\n" . __FILE__ . PHP_EOL;
    echo "API: GET /sale/shipping-rates\n";

    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();
    $dictionary = new AllegroDictionaryEndpoint($config['api_url'], $accessToken);
    $data = $dictionary->get('/sale/shipping-rates');

    $outputPath = dirname(__DIR__, 3) . '/dane/cenniki_dostawy_allegro.json';
    $shippingRates = $data['shippingRates'] ?? [];
    (new CategoryDetailsJsonWriter())->write($outputPath, [
        'generated_at' => date(DATE_ATOM),
        'count' => count($shippingRates),
        'shippingRates' => $shippingRates,
    ]);

    echo sprintf("Pobrano cenników dostawy: %d\n", count($shippingRates));
    echo sprintf("Zapisano JSON: %s\n", $outputPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
