<?php

declare(strict_types=1);

use App\AllegroOAuth;
use App\Services\Rest\AllegroDictionaryEndpoint;
use App\Services\Rest\CategoryDetailsJsonWriter;

require_once dirname(__DIR__, 3) . '/config.php';

try {
    echo "\n" . __FILE__ . PHP_EOL;
    echo "API: GET /after-sales-service-conditions/implied-warranties\n";

    $oauth = new AllegroOAuth($config);
    $accessToken = $oauth->getAccessToken();
    $dictionary = new AllegroDictionaryEndpoint($config['api_url'], $accessToken);
    $data = $dictionary->get('/after-sales-service-conditions/implied-warranties');

    $outputPath = dirname(__DIR__, 3) . '/dane/warunki_reklamacji_allegro.json';
    $impliedWarranties = $data['impliedWarranties'] ?? [];
    (new CategoryDetailsJsonWriter())->write($outputPath, [
        'generated_at' => date(DATE_ATOM),
        'count' => count($impliedWarranties),
        'impliedWarranties' => $impliedWarranties,
    ]);

    echo sprintf("Pobrano warunków reklamacji: %d\n", count($impliedWarranties));
    echo sprintf("Zapisano JSON: %s\n", $outputPath);
} catch (Throwable $e) {
    fwrite(STDERR, 'BŁĄD: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
